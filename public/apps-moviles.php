<?php
require_once __DIR__ . '/../config/bootstrap.php';

$projectRoot = defined('SISTEMAX_PROJECT_ROOT') ? SISTEMAX_PROJECT_ROOT : dirname(__DIR__);
$isLoggedIn = (int)Session::getIdLogin() > 0;
$sessionEmpresa = (int)Session::getIdEmpresa();
$sessionEmpresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $sessionEmpresa));
$sessionLogin = (int)Session::getIdLogin();
$sessionUserName = (string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ($_SESSION['usuario'] ?? ''));
$externalSetupToken = trim((string)($_GET['setup_token'] ?? ''));

function supportAbsoluteUrl(string $url): string
{
    if ($url === '' || preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $url;
}

function supportLinkTargetAttrs(string $url): string
{
    return preg_match('#^https?://#i', trim($url))
        ? ' target="_blank" rel="noopener noreferrer"'
        : '';
}

function supportClientDownloadUrl(string $platform, string $url): string
{
    $platform = preg_replace('/[^a-z0-9_-]/i', '', strtolower($platform));
    $url = trim($url);
    if ($url === '' || preg_match('#^https?://(www\.)?helpwire\.app(?:/|$)#i', $url)) {
        return '/public/helpwire/download.php?platform=' . rawurlencode($platform);
    }
    return $url;
}

function supportNormalizeDwserviceUrl(string $url, string $fallback): string
{
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }
    if (preg_match('#rustdesk|github\.com/.*/rustdesk|helpwire#i', $url)) {
        return $fallback;
    }
    return $url;
}

function supportAppsNormalizeSettings(array $settings): array
{
    $defaults = [
        'support_remote_vendor' => 'SistemaX Assist',
        'support_remote_docs_url' => '/public/soporte/index.php',
        'support_remote_server_url' => '/public/soporte/index.php',
        'support_remote_access_url' => '/public/soporte/assist_admin.php',
        'support_desktop_windows_url' => '',
        'support_desktop_macos_url' => '',
        'support_desktop_linux_url' => '',
    ];
    $vendor = trim((string)($settings['support_remote_vendor'] ?? ''));
    if ($vendor === '' || preg_match('/dwservice/i', $vendor)) {
        $settings['support_remote_vendor'] = $defaults['support_remote_vendor'];
    }
    foreach ([
        'support_remote_docs_url',
        'support_remote_server_url',
        'support_remote_access_url',
        'support_desktop_windows_url',
        'support_desktop_macos_url',
        'support_desktop_linux_url',
    ] as $key) {
        $value = trim((string)($settings[$key] ?? ''));
        if ($value === '' || preg_match('/dwservice/i', $value)) {
            $settings[$key] = $defaults[$key];
        }
    }
    if (preg_match('/dwservice/i', trim((string)($settings['support_remote_host_label'] ?? '')))) {
        $settings['support_remote_host_label'] = '';
    }
    return $settings;
}

$supportDesktopSettings = [];
try {
    $pdoSupportCfg = Database::getMasterConnection();
    $stmtSupportCfg = $pdoSupportCfg->query("SELECT setting_key, setting_value FROM " . MASTER_DB . ".smx_support_desktop_settings");
    foreach (($stmtSupportCfg->fetchAll(PDO::FETCH_ASSOC) ?: []) as $rowCfg) {
        $supportDesktopSettings[(string)$rowCfg['setting_key']] = (string)($rowCfg['setting_value'] ?? '');
    }
} catch (Throwable $e) {
    $supportDesktopSettings = [];
}
$supportDesktopSettings = supportAppsNormalizeSettings($supportDesktopSettings);

$apkCandidates = array_merge(
    glob(__DIR__ . '/pos/downloads/sistemax-print-android*.apk') ?: [],
    glob(__DIR__ . '/pos/downloads/sistemax-agent-android*.apk') ?: []
);
$apkPath = __DIR__ . '/pos/downloads/sistemax-print-android.apk';
$apkVersion = 'latest';
$bestAgentVersion = null;
$bestAgentPath = null;

foreach ($apkCandidates as $candidatePath) {
    $base = basename($candidatePath);
    if (preg_match('/-(\d+\.\d+\.\d+)(?:-[A-Za-z0-9]+)?\.apk$/', $base, $m)) {
        $v = $m[1];
        if ($bestAgentVersion === null || version_compare($v, $bestAgentVersion, '>')) {
            $bestAgentVersion = $v;
            $bestAgentPath = $candidatePath;
        }
    }
}

if (is_file($apkPath)) {
    if ($bestAgentPath && preg_match('/-(\d+\.\d+\.\d+)(?:-[A-Za-z0-9]+)?\.apk$/', basename($bestAgentPath), $m)) {
        $apkVersion = $m[1];
    }
} elseif ($bestAgentPath) {
    $apkPath = $bestAgentPath;
    $apkVersion = (string)$bestAgentVersion;
} elseif (!empty($apkCandidates)) {
    natsort($apkCandidates);
    $apkPath = end($apkCandidates);
    if (preg_match('/-(\d+\.\d+\.\d+)(?:-[A-Za-z0-9]+)?\.apk$/', basename($apkPath), $m)) {
        $apkVersion = $m[1];
    }
}

$srcPath = __DIR__ . '/pos/downloads/sistemax-agent-android-source-0.1.0.tar.gz';
$apkFile = basename($apkPath);
$apkUrl = '/public/pos/downloads/' . $apkFile;
$mainCandidates = array_merge(
    glob(__DIR__ . '/pos/downloads/sistemaxpro-android-*.apk') ?: [],
    glob(__DIR__ . '/pos/downloads/sistemax-pro-android-*.apk') ?: []
);
if (!empty($mainCandidates)) natsort($mainCandidates);

$mainApkPath = __DIR__ . '/pos/downloads/sistemax-pro-android-0.1.0.apk';
$mainApkVersion = '0.1.0';
$bestVersion = null;
$bestPath = null;

foreach ($mainCandidates as $candidatePath) {
    $base = basename($candidatePath);
    if (preg_match('/-(\d+\.\d+\.\d+)(?:-[A-Za-z0-9]+)?\.apk$/', $base, $m)) {
        $v = $m[1];
        if ($bestVersion === null || version_compare($v, $bestVersion, '>')) {
            $bestVersion = $v;
            $bestPath = $candidatePath;
        }
    }
}

if ($bestPath) {
    $mainApkPath = $bestPath;
    $mainApkVersion = (string)$bestVersion;
} elseif (!empty($mainCandidates)) {
    $mainApkPath = end($mainCandidates);
    if (preg_match('/-(\d+\.\d+\.\d+)(?:-[A-Za-z0-9]+)?\.apk$/', basename($mainApkPath), $m)) {
        $mainApkVersion = $m[1];
    }
}
$mainApkUrl = '/public/pos/downloads/' . basename($mainApkPath);
$srcUrl = '/public/pos/downloads/sistemax-agent-android-source-0.1.0.tar.gz';
$macCandidates = glob(__DIR__ . '/pos/downloads/sistemax-agent-macos-*.tar.gz') ?: [];
$winExeCandidates = glob(__DIR__ . '/pos/downloads/Sistemax-Agent-Setup-*.exe') ?: [];
$winCandidates = glob(__DIR__ . '/pos/downloads/sistemax-agent-windows-*.tar.gz') ?: [];
if (!empty($macCandidates)) natsort($macCandidates);
if (!empty($winExeCandidates)) natsort($winExeCandidates);
if (!empty($winCandidates)) natsort($winCandidates);
$macPath = !empty($macCandidates) ? end($macCandidates) : (__DIR__ . '/pos/downloads/sistemax-agent-macos-0.1.4.tar.gz');
$winExePath = !empty($winExeCandidates) ? end($winExeCandidates) : (__DIR__ . '/pos/downloads/Sistemax-Agent-Setup-0.1.1.exe');
$winPath = !empty($winCandidates) ? end($winCandidates) : (__DIR__ . '/pos/downloads/sistemax-agent-windows-source-0.1.1.tar.gz');
$macUrl = '/public/pos/downloads/' . basename($macPath);
$winExeUrl = '/public/pos/downloads/' . basename($winExePath);
$winUrl = '/public/pos/downloads/' . basename($winPath);
$apkAvailable = is_file($apkPath);
$mainApkAvailable = is_file($mainApkPath);
$srcAvailable = is_file($srcPath);
$macAvailable = is_file($macPath);
$winExeAvailable = is_file($winExePath);
$winAvailable = is_file($winPath);
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = (bool)preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini|mobile|tablet/i', $ua);
$isAndroid = (bool)preg_match('/Android/i', $ua);
$isIOS = (bool)preg_match('/iPhone|iPad|iPod/i', $ua);
$isWindows = (bool)preg_match('/Windows NT/i', $ua);
$isMacDesktop = (bool)(preg_match('/Macintosh|Mac OS X/i', $ua) && !$isIOS);

$platform = 'other';
if ($isAndroid) {
    $platform = 'android';
} elseif ($isIOS) {
    $platform = 'ios';
} elseif ($isWindows) {
    $platform = 'windows';
} elseif ($isMacDesktop) {
    $platform = 'macos';
}
$showAll = ($platform === 'other');
$externalMode = trim((string)($_GET['external'] ?? ''));
$printAgentOnly = ($externalMode === '1');
$androidInstallOnly = in_array($externalMode, ['android', '2'], true);
$androidGeoAgentOnly = true;
$agentUrl = trim((string)($_GET['agent_url'] ?? 'http://127.0.0.1:17890'));

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$macInstallHttpUrl = $scheme . '://' . $host . $macUrl;
$winInstallHttpUrl = $scheme . '://' . $host . $winUrl;
$winExeHttpUrl = $scheme . '://' . $host . $winExeUrl;
$macArchiveName = basename($macPath);
$winArchiveName = basename($winPath);
$winExeName = basename($winExePath);
$macFolderName = preg_replace('/\.tar\.gz$/', '', $macArchiveName);
$winFolderName = preg_replace('/\.tar\.gz$/', '', $winArchiveName);
$macInstallCommand = "cd ~/Downloads && curl -L -o {$macArchiveName} {$macInstallHttpUrl} && tar -xzf {$macArchiveName} && cd {$macFolderName} && chmod +x install-macos.sh && ./install-macos.sh";
$winInstallCommand =
    'powershell -NoProfile -ExecutionPolicy Bypass -Command "' .
    '`$f = Join-Path `$env:USERPROFILE \'Downloads\\' . $winExeName . '\'; ' .
    'Invoke-WebRequest -Uri \'' . $winExeHttpUrl . '\' -OutFile `$f; ' .
    'Start-Process -FilePath `$f"';
$platformLabel = 'este dispositivo';
if ($platform === 'android') $platformLabel = 'Android';
if ($platform === 'ios') $platformLabel = 'iOS';
if ($platform === 'windows') $platformLabel = 'Windows';
if ($platform === 'macos') $platformLabel = 'macOS';
$androidNativePackage = 'pro.sistemax.main';
$androidNativeFallbackUrl = supportAbsoluteUrl('/public/apps-moviles.php?platform=android');
$androidNativeOpenUrl = 'intent://open#Intent;scheme=sistemaxpro;package=' . rawurlencode($androidNativePackage) . ';S.browser_fallback_url=' . rawurlencode($androidNativeFallbackUrl) . ';end';

$supportWinCandidates = glob(__DIR__ . '/soporte/downloads/sistemax-support-desktop-windows*.exe') ?: [];
$supportMacCandidates = glob(__DIR__ . '/soporte/downloads/sistemax-support-desktop-macos*.tar.gz') ?: [];
$supportLinuxCandidates = glob(__DIR__ . '/soporte/downloads/sistemax-support-desktop-linux*.tar.gz') ?: [];
if (!empty($supportWinCandidates)) natsort($supportWinCandidates);
if (!empty($supportMacCandidates)) natsort($supportMacCandidates);
if (!empty($supportLinuxCandidates)) natsort($supportLinuxCandidates);
$supportWinPath = !empty($supportWinCandidates) ? end($supportWinCandidates) : (__DIR__ . '/soporte/downloads/sistemax-support-desktop-windows-0.1.0.exe');
$supportMacPath = !empty($supportMacCandidates) ? end($supportMacCandidates) : (__DIR__ . '/soporte/downloads/sistemax-support-desktop-macos-0.1.0.tar.gz');
$supportLinuxPath = !empty($supportLinuxCandidates) ? end($supportLinuxCandidates) : (__DIR__ . '/soporte/downloads/sistemax-support-desktop-linux-0.1.0.tar.gz');
$supportWinAvailable = is_file($supportWinPath);
$supportMacAvailable = is_file($supportMacPath);
$supportLinuxAvailable = is_file($supportLinuxPath);
$supportWinUrl = '/public/soporte/downloads/' . basename($supportWinPath);
$supportMacUrl = '/public/soporte/downloads/' . basename($supportMacPath);
$supportLinuxUrl = '/public/soporte/downloads/' . basename($supportLinuxPath);
$supportAndroidCandidates = glob(__DIR__ . '/soporte/downloads/sistemax-assist-android*.apk') ?: [];
if (!empty($supportAndroidCandidates)) {
    natsort($supportAndroidCandidates);
}
$supportAndroidPath = !empty($supportAndroidCandidates) ? end($supportAndroidCandidates) : (__DIR__ . '/soporte/downloads/sistemax-assist-android.apk');
$supportAndroidAvailable = is_file($supportAndroidPath);
$supportAndroidUrl = '/public/soporte/downloads/' . basename($supportAndroidPath);
$supportAndroidStablePath = __DIR__ . '/soporte/downloads/sistemax-assist-android.apk';
$supportAndroidStableAvailable = is_file($supportAndroidStablePath);
$supportAndroidStableUrl = '/public/soporte/downloads/' . basename($supportAndroidStablePath);
$supportAndroidVersion = 'latest';
if (preg_match('/-(\d+\.\d+\.\d+)(?:-[A-Za-z0-9]+)?\.apk$/', basename($supportAndroidPath), $m)) {
    $supportAndroidVersion = $m[1];
}
$assistApiAbsoluteUrl = supportAbsoluteUrl('/public/api/assist.php');
$trackingApiAbsoluteUrl = supportAbsoluteUrl('/public/api/mobile_tracking.php');
$supportVendor = trim((string)($supportDesktopSettings['support_remote_vendor'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_VENDOR')) ?: 'SistemaX Assist';
$supportDocsDefaultUrl = '/public/soporte/index.php';
$supportPortalDefaultUrl = '';
$supportAccessDefaultUrl = '/public/soporte/assist_admin.php';
$supportVendorDocsUrl = supportNormalizeDwserviceUrl(
    trim((string)($supportDesktopSettings['support_remote_docs_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_DOCS_URL')),
    $supportDocsDefaultUrl
);
$supportWindowsOverrideUrl = supportNormalizeDwserviceUrl(
    trim((string)($supportDesktopSettings['support_desktop_windows_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_DESKTOP_WINDOWS_URL')),
    $supportPortalDefaultUrl
);
$supportMacOverrideUrl = supportNormalizeDwserviceUrl(
    trim((string)($supportDesktopSettings['support_desktop_macos_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_DESKTOP_MACOS_URL')),
    $supportPortalDefaultUrl
);
$supportLinuxOverrideUrl = supportNormalizeDwserviceUrl(
    trim((string)($supportDesktopSettings['support_desktop_linux_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_DESKTOP_LINUX_URL')),
    $supportPortalDefaultUrl
);
$supportHostLabel = trim((string)($supportDesktopSettings['support_remote_host_label'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_HOST_LABEL'));
$supportServerUrl = supportNormalizeDwserviceUrl(
    trim((string)($supportDesktopSettings['support_remote_server_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_SERVER_URL')),
    $supportPortalDefaultUrl
);
$supportAccessUrl = supportNormalizeDwserviceUrl(
    trim((string)($supportDesktopSettings['support_remote_access_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_ACCESS_URL')),
    $supportAccessDefaultUrl
);
$supportCompaniesLabel = implode('/', SISTEMAX_SUPPORT_COMPANIES);
$supportCompaniesNamedLabel = $supportCompaniesLabel;
try {
    $pdoSupportCompanies = Database::getMasterConnection();
    $in = implode(',', array_map('intval', SISTEMAX_SUPPORT_COMPANIES));
    $rowsSupportCompanies = $pdoSupportCompanies->query("SELECT id_empresa, empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa IN ({$in}) ORDER BY FIELD(id_empresa, {$in})")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rowsSupportCompanies) {
        $supportCompaniesNamedLabel = implode(' / ', array_map(static function (array $row): string {
            return (string)($row['empresa'] ?? ('Empresa #' . (int)($row['id_empresa'] ?? 0))) . ' · #' . (int)($row['id_empresa'] ?? 0);
        }, $rowsSupportCompanies));
    }
} catch (Throwable $e) {
}
$supportWinFinalUrl = supportClientDownloadUrl('windows', $supportWindowsOverrideUrl !== '' ? $supportWindowsOverrideUrl : ($supportWinAvailable ? $supportWinUrl : ''));
$supportMacFinalUrl = supportClientDownloadUrl('macos', $supportMacOverrideUrl !== '' ? $supportMacOverrideUrl : ($supportMacAvailable ? $supportMacUrl : ''));
$supportLinuxFinalUrl = supportClientDownloadUrl('linux', $supportLinuxOverrideUrl !== '' ? $supportLinuxOverrideUrl : ($supportLinuxAvailable ? $supportLinuxUrl : ''));
$supportWinCliUrl = supportAbsoluteUrl($supportWinFinalUrl);
$supportMacCliUrl = supportAbsoluteUrl($supportMacFinalUrl);
$supportLinuxCliUrl = supportAbsoluteUrl($supportLinuxFinalUrl);
$supportWinPublished = $supportWinFinalUrl !== '';
$supportMacPublished = $supportMacFinalUrl !== '';
$supportLinuxPublished = $supportLinuxFinalUrl !== '';
$showSupportDesktop = in_array($platform, ['windows', 'macos', 'other'], true);
$supportWinFileName = $supportWinPublished ? basename(parse_url($supportWinFinalUrl, PHP_URL_PATH) ?: 'sistemax-support-desktop-windows.exe') : 'sistemax-support-desktop-windows.exe';
$supportMacFileName = $supportMacPublished ? basename(parse_url($supportMacFinalUrl, PHP_URL_PATH) ?: 'sistemax-support-desktop-macos.tar.gz') : 'sistemax-support-desktop-macos.tar.gz';
$supportLinuxFileName = $supportLinuxPublished ? basename(parse_url($supportLinuxFinalUrl, PHP_URL_PATH) ?: 'sistemax-support-desktop-linux.tar.gz') : 'sistemax-support-desktop-linux.tar.gz';
$supportWinCliCommand =
    'powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process \'' . $supportWinCliUrl . '\'"';
$supportMacCliHelp = 'Copiá todo este bloque, pegalo en Terminal y presioná Enter. Se abre la página oficial de ' . $supportVendor . ' para descargar e instalar el agente en macOS.';
$supportMacCliCommand = implode("\n", [
    'cd ~/Downloads',
    'open "' . $supportMacCliUrl . '"',
]);
$supportLinuxCliCommand = implode("\n", [
    'cd ~/Downloads',
    'xdg-open "' . $supportLinuxCliUrl . '"',
]);

if ($androidInstallOnly):
    $androidSetupStorageKey = 'smx-android-app-setup-v1';
    $androidPrinterFocusOnly = (trim((string)($_GET['focus'] ?? '')) === 'printer');
    $androidLaunchTarget = supportAbsoluteUrl('/public/apps-moviles.php?external=android' . ($externalSetupToken !== '' ? '&setup_token=' . rawurlencode($externalSetupToken) : ''));
    $androidSetupDeepLink = 'sistemaxpro://setup'
        . '?setup_token=' . rawurlencode($externalSetupToken)
        . '&assist_api_url=' . rawurlencode($assistApiAbsoluteUrl)
        . '&tracking_api_url=' . rawurlencode($trackingApiAbsoluteUrl)
        . '&url=' . rawurlencode($androidLaunchTarget);
    $androidSetupIntent = 'intent://setup'
        . '?setup_token=' . rawurlencode($externalSetupToken)
        . '&assist_api_url=' . rawurlencode($assistApiAbsoluteUrl)
        . '&tracking_api_url=' . rawurlencode($trackingApiAbsoluteUrl)
        . '&url=' . rawurlencode($androidLaunchTarget)
        . '#Intent;scheme=sistemaxpro;package=pro.sistemax.main;end';
    $androidLaunchDeepLink = 'sistemaxpro://open?url=' . rawurlencode($androidLaunchTarget);
    $androidLaunchIntent = 'intent://open?url=' . rawurlencode($androidLaunchTarget) . '#Intent;scheme=sistemaxpro;package=pro.sistemax.main;end';
    $assistSetupDeepLink = 'sistemaxassist://setup'
        . '?setup_token=' . rawurlencode($externalSetupToken)
        . '&assist_api_url=' . rawurlencode($assistApiAbsoluteUrl)
        . '&tracking_api_url=' . rawurlencode($trackingApiAbsoluteUrl);
    $assistSetupIntent = 'intent://setup'
        . '?setup_token=' . rawurlencode($externalSetupToken)
        . '&assist_api_url=' . rawurlencode($assistApiAbsoluteUrl)
        . '&tracking_api_url=' . rawurlencode($trackingApiAbsoluteUrl)
        . '#Intent;scheme=sistemaxassist;package=pro.sistemax.assist;end';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalar Agente Geolocalizador</title>
    <link rel="manifest" href="/public/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto max-w-6xl px-4 py-8 space-y-5">
        <?php if (!$androidPrinterFocusOnly): ?>
        <header class="rounded-3xl border border-emerald-900/50 bg-[radial-gradient(circle_at_top_left,_rgba(16,185,129,0.18),_transparent_30%),linear-gradient(180deg,_rgba(15,23,42,0.95),_rgba(2,6,23,0.98))] p-6 shadow-2xl shadow-emerald-950/30">
            <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center gap-2 rounded-full border border-emerald-700/40 bg-emerald-900/20 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.28em] text-emerald-200">Telefono y tablet</div>
                    <h1 class="mt-3 text-3xl font-black tracking-tight text-white">Instalar Agente Geolocalizador</h1>
                    <p class="mt-2 text-sm text-slate-300">Esta pantalla queda enfocada solo en instalar y activar el agente geolocalizador en Android. El objetivo es dejar <strong>SistemaX Pro</strong> listo para reportar ubicación y mantener tracking activo en segundo plano.</p>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border border-slate-700 bg-slate-900/70 px-3 py-1 text-slate-200">Dispositivo detectado: <?php echo htmlspecialchars($platformLabel); ?></span>
                        <span class="rounded-full border border-emerald-700/40 bg-emerald-900/20 px-3 py-1 text-emerald-200">Instalacion guiada</span>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 text-sm text-slate-300 md:w-[340px]">
                    <div class="text-xs uppercase tracking-[0.28em] text-emerald-300">Orden recomendado</div>
                    <ol class="mt-3 space-y-2">
                        <li>1. Instalar <strong>SistemaX Pro</strong>.</li>
                        <li>2. Abrir la app y conceder ubicación precisa y segundo plano.</li>
                        <li>3. Desactivar restricciones de batería para el agente.</li>
                        <li>4. Verificar que el dispositivo reporte tracking.</li>
                    </ol>
                </div>
            </div>
        </header>
        <?php endif; ?>

        <section class="grid gap-4 <?php echo $androidPrinterFocusOnly ? '' : 'lg:grid-cols-[1.05fr_0.95fr]'; ?>">
            <?php if (!$androidPrinterFocusOnly): ?>
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-lg font-bold text-white">1. Instalar y activar</h2>
                <p class="mt-1 text-sm text-slate-300">El flujo visible queda resumido en instalar la app principal, abrirla y autorizar el acceso a ubicación para que el geolocalizador empiece a reportar.</p>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded-2xl border border-emerald-700/40 bg-emerald-950/20 p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-300">Paso 1</div>
                        <div class="mt-2 text-base font-black text-white">Instalar agente Android</div>
                        <p class="mt-2 text-sm text-emerald-100/80">Instalá el APK principal. Si Android lo bloquea, habilitá instalar apps desconocidas para tu navegador o gestor de archivos.</p>
                        <?php if ($mainApkAvailable): ?>
                            <a href="<?php echo htmlspecialchars($mainApkUrl); ?>" class="mt-4 block rounded-xl bg-emerald-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-emerald-500">Descargar SistemaX Pro <?php echo htmlspecialchars($mainApkVersion); ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-2xl border border-cyan-700/40 bg-cyan-950/20 p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-cyan-300">Paso 2</div>
                        <div class="mt-2 text-base font-black text-white">Abrir y aceptar ubicación</div>
                        <p class="mt-2 text-sm text-cyan-100/80">Abrí la app instalada. Si esta página trae <code>setup_token</code>, la app principal se autorregistra para tracking sin pegar tokens manualmente.</p>
                        <button type="button" class="js-open-android-app mt-4 w-full rounded-xl border border-cyan-700/40 bg-cyan-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-cyan-500" data-app-deeplink="<?php echo htmlspecialchars($externalSetupToken !== '' ? $androidSetupDeepLink : $androidLaunchDeepLink); ?>" data-app-intent="<?php echo htmlspecialchars($externalSetupToken !== '' ? $androidSetupIntent : $androidLaunchIntent); ?>">Abrir app instalada</button>
                    </div>
                </div>

                <?php if (!$androidGeoAgentOnly): ?>
                <details class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                    <summary class="cursor-pointer list-none text-sm font-bold text-slate-100">Herramientas Legacy</summary>
                    <p class="mt-2 text-sm text-slate-400">Estas opciones quedan solo para compatibilidad con instalaciones antiguas o para soporte técnico puntual. Las nuevas instalaciones deberían usar solo SistemaX Pro.</p>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded-2xl border border-violet-800/40 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-violet-300">SistemaX Print</div>
                        <p class="mt-2 text-xs text-slate-400">Opción legada para impresión Android. La ruta recomendada ahora es imprimir desde SistemaX Pro.</p>
                        <?php if ($apkAvailable): ?>
                            <a href="<?php echo htmlspecialchars($apkUrl); ?>" class="mt-4 block rounded-xl bg-violet-600 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-violet-500">Descargar APK <?php echo htmlspecialchars($apkVersion); ?></a>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-700/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-200">APK de impresion no publicado.</div>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-2xl border border-cyan-800/40 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-cyan-300">SistemaX Assist Android</div>
                        <p class="mt-2 text-xs text-slate-400">Herramienta legado deprecada. Ya no es la app recomendada para nuevas instalaciones.</p>
                        <?php if ($supportAndroidAvailable): ?>
                            <a href="<?php echo htmlspecialchars($supportAndroidUrl); ?>" class="mt-4 block rounded-xl bg-cyan-600 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-cyan-500">Descargar APK <?php echo htmlspecialchars($supportAndroidVersion); ?></a>
                            <?php if ($externalSetupToken !== ''): ?>
                                <button type="button" class="js-open-assist-app mt-2 w-full rounded-xl border border-cyan-700/40 bg-cyan-950/30 px-4 py-2 text-center text-xs font-semibold text-cyan-200 transition hover:bg-cyan-900/30" data-assist-deeplink="<?php echo htmlspecialchars($assistSetupDeepLink); ?>" data-assist-intent="<?php echo htmlspecialchars($assistSetupIntent); ?>">Abrir Assist instalada</button>
                            <?php endif; ?>
                            <?php if ($supportAndroidStableAvailable && basename($supportAndroidStableUrl) !== basename($supportAndroidUrl)): ?>
                                <a href="<?php echo htmlspecialchars($supportAndroidStableUrl); ?>" class="mt-2 block rounded-xl border border-cyan-700/40 bg-cyan-950/30 px-4 py-2 text-center text-xs font-semibold text-cyan-200 transition hover:bg-cyan-900/30">Descarga estable sin versionado</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-700/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-200">APK de asistencia no publicado.</div>
                        <?php endif; ?>
                    </div>
                    </div>
                </details>
                <?php endif; ?>

                <div class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
                    <h3 class="text-sm font-bold text-slate-100">Pasos de instalación del geolocalizador</h3>
                    <ol class="mt-3 space-y-2 text-sm text-slate-300">
                        <li>1. Descargá e instalá <strong>SistemaX Pro</strong>.</li>
                        <li>2. Si Android bloquea la instalacion, habilitá <strong>Instalar apps desconocidas</strong> para tu navegador o gestor de archivos.</li>
                        <li>3. Abrí <strong>SistemaX Pro</strong> para que Android registre permisos de ubicación y tracking.</li>
                        <li>4. Concedé <strong>ubicación precisa</strong> y <strong>permitir siempre</strong> si Android lo solicita.</li>
                        <li>5. Desactivá ahorro de batería para que el tracking no se corte en segundo plano.</li>
                        <li>6. Verificá luego el dispositivo en <strong>Configuración Geolocalización</strong>.</li>
                    </ol>
                </div>

                <div class="mt-4 rounded-2xl border border-emerald-700/40 bg-emerald-950/20 p-4">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-emerald-300">Activacion casi automatica</h3>
                            <p class="mt-1 text-xs text-emerald-100/80">Esta pantalla prepara automáticamente el tracking. En Android solo queda instalar SistemaX Pro, abrirla una vez y aceptar los permisos de ubicación cuando los pida.</p>
                        </div>
                        <div class="rounded-full border border-emerald-700/50 bg-emerald-900/30 px-3 py-1 text-[11px] font-semibold text-emerald-200">
                            Menos pasos manuales
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-cyan-300">Permisos minimos</div>
                        <ul class="mt-2 space-y-2 text-sm text-slate-300">
                            <li>Ubicación precisa.</li>
                            <li>Permitir ubicación en segundo plano.</li>
                            <li>Datos móviles o Wi-Fi estables para reportar.</li>
                            <li>Desactivar optimización de batería para tracking continuo.</li>
                        </ul>
                    </div>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-sky-300">Configuracion recomendada</div>
                        <ul class="mt-2 space-y-2 text-sm text-slate-300">
                            <li>Asignar un alias claro al dispositivo.</li>
                            <li>Elegir color e ícono para identificarlo en el mapa.</li>
                            <li>Si usás datos móviles, mantener buena cobertura para sincronización.</li>
                            <li>Revisar periódicamente que el equipo no haya quedado con tracking pausado.</li>
                        </ul>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-cyan-800/40 bg-slate-950/60 p-4">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-cyan-300">Soporte tecnico y bootstrap manual</h3>
                            <p class="mt-1 text-xs text-slate-400">Dejá esta sección solo para soporte o para regenerar datos manuales si necesitás depurar una instalación.</p>
                        </div>
                        <div class="rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1 text-[11px] font-semibold text-slate-300">
                            Dura 10 minutos
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4">
                            <div class="text-xs uppercase tracking-[0.22em] text-emerald-300">Agente geolocalizador</div>
                            <div class="mt-3 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400">Tracking API URL</div>
                            <div class="mt-1 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-200 break-all" id="trackingApiUrlText"><?php echo htmlspecialchars($trackingApiAbsoluteUrl); ?></div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" id="btnIssueTrackingBootstrap" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-500">Generar token Tracking</button>
                                <button type="button" class="copy-bootstrap-btn rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-800" data-copy-target="trackingApiUrlText">Copiar URL</button>
                            </div>
                            <div class="mt-3 rounded-xl border border-slate-800 bg-black px-3 py-3">
                                <div class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Bootstrap token tracking</div>
                                <div id="trackingBootstrapToken" class="mt-2 min-h-[48px] break-all font-mono text-sm text-emerald-200">Todavia no generado</div>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" class="copy-bootstrap-btn rounded-xl border border-emerald-700/40 bg-emerald-950/30 px-4 py-2 text-xs font-semibold text-emerald-200 hover:bg-emerald-900/30" data-copy-target="trackingBootstrapToken">Copiar token Tracking</button>
                                <div id="trackingBootstrapMeta" class="text-xs text-slate-400"></div>
                            </div>
                        </div>
                    </div>

                    <div id="bootstrapMsg" class="hidden mt-4 rounded-xl border px-3 py-2 text-sm"></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="space-y-4">
                <?php if (!$androidGeoAgentOnly): ?>
                <section id="android-printer-config" class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-lg font-bold text-white"><?php echo $androidPrinterFocusOnly ? 'Configurar Impresora Bluetooth' : '2. Formulario de configuracion'; ?></h2>
                    <p class="mt-1 text-sm text-slate-300"><?php echo $androidPrinterFocusOnly ? 'Elegí una impresora Bluetooth emparejada del dispositivo o cargala manualmente. La configuración se guarda localmente en este equipo.' : 'Guardá la configuracion operativa recomendada para este telefono o tablet. Se persiste localmente en el navegador.'; ?></p>
                    <form id="androidSetupForm" class="mt-4 grid gap-3">
                        <?php if (!$androidPrinterFocusOnly): ?>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Nombre del dispositivo</label>
                            <input name="device_name" type="text" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500" placeholder="Ej: Tablet caja 1" />
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Tipo de equipo</label>
                                <select name="device_type" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500">
                                    <option value="telefono">Telefono</option>
                                    <option value="tablet">Tablet</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Uso principal</label>
                                <select name="primary_use" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500">
                                    <option value="ventas">Ventas / POS</option>
                                    <option value="inventario">Inventario</option>
                                    <option value="mixto">Mixto</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="inline-flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-3 text-sm text-slate-200">
                                <input name="uses_print" type="checkbox" class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-emerald-500 focus:ring-emerald-500" />
                                Usa impresion Bluetooth
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-3 text-sm text-slate-200">
                                <input name="battery_whitelist" type="checkbox" class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-emerald-500 focus:ring-emerald-500" />
                                Bateria sin restricciones
                            </label>
                        </div>
                        <?php endif; ?>
                        <div id="android-printer-field">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Impresora Bluetooth</label>
                            <div class="space-y-2">
                                <div class="flex gap-2">
                                    <select id="androidPrinterSelect" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500">
                                        <option value="">Detectar impresoras enlazadas...</option>
                                    </select>
                                    <button id="btnDetectAndroidPrinters" type="button" class="shrink-0 rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-3 py-2.5 text-xs font-bold text-emerald-200 transition hover:bg-emerald-900/35">Actualizar</button>
                                </div>
                                <input id="androidPrinterManual" name="printer_name" type="text" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500" placeholder="Opcional: nombre manual de la impresora enlazada" />
                                <div id="androidPrinterStatus" class="rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-2 text-xs text-slate-400">Abrí esta pantalla desde SistemaX Pro para listar las impresoras Bluetooth emparejadas del dispositivo.</div>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Ancho de ticket</label>
                            <select name="ticket_width" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500">
                                <option value="80mm">80 mm</option>
                                <option value="58mm">58 mm</option>
                            </select>
                            <div class="mt-2 rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-2 text-xs text-slate-400">Elegí el ancho correcto para que el logo y el centrado salgan bien en el ticket.</div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Ajuste fino de centrado</label>
                            <select name="ticket_align_offset" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500">
                                <option value="-3">-3 izquierda</option>
                                <option value="-2">-2 izquierda</option>
                                <option value="-1">-1 izquierda</option>
                                <option value="0" selected>0 centrado</option>
                                <option value="1">+1 derecha</option>
                                <option value="2">+2 derecha</option>
                                <option value="3">+3 derecha</option>
                            </select>
                            <div class="mt-2 rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-2 text-xs text-slate-400">Usalo si el logo o el texto salen levemente corridos aun teniendo bien el ancho de ticket.</div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Observaciones</label>
                            <textarea name="notes" rows="4" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500" placeholder="Ej: tablet fija en mostrador, impresora BT de cocina, solo ventas"></textarea>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-500"><?php echo $androidPrinterFocusOnly ? 'Guardar impresora' : 'Guardar configuracion'; ?></button>
                            <button id="btnAndroidPrinterTest" type="button" class="rounded-xl border border-cyan-700/40 bg-cyan-900/20 px-4 py-2.5 text-sm font-bold text-cyan-200 transition hover:bg-cyan-900/35">Prueba de impresion</button>
                            <button id="btnResetAndroidSetup" type="button" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-700">Limpiar ficha</button>
                        </div>
                        <div id="androidPrinterTestStatus" class="hidden rounded-xl border px-3 py-2 text-sm"></div>
                        <div id="androidSetupMsg" class="hidden rounded-xl border px-3 py-2 text-sm"></div>
                    </form>
                </section>
                <?php else: ?>
                <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-lg font-bold text-white">2. Activación del agente</h2>
                    <p class="mt-1 text-sm text-slate-300">Usá este checklist para dejar el geolocalizador operativo después de instalar la app.</p>
                    <div class="mt-4 rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-300">
                        <ul class="space-y-2">
                            <li>1. La app abre correctamente en Android.</li>
                            <li>2. El permiso de ubicación quedó aceptado.</li>
                            <li>3. El dispositivo no tiene restricciones de batería para la app.</li>
                            <li>4. Si usás token, la app quedó registrada al primer inicio.</li>
                            <li>5. El equipo ya aparece luego en <strong>Configuración Geolocalización</strong>.</li>
                        </ul>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (!$androidPrinterFocusOnly && !$androidGeoAgentOnly): ?>
                <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-lg font-bold text-white">3. Verificacion final</h2>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-300">
                        <ul class="space-y-2">
                            <li>App principal abre y permite iniciar sesion.</li>
                            <li>SistemaX Pro detecta la impresora Bluetooth si corresponde.</li>
                            <li>El dispositivo no tiene restricciones de bateria para estas apps.</li>
                            <li>La orientacion y brillo del equipo son adecuados para el puesto.</li>
                            <li>Ya probaste al menos una impresion o un flujo real.</li>
                        </ul>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const STORAGE_KEY = <?php echo json_encode($androidSetupStorageKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const form = document.getElementById('androidSetupForm');
            const msg = document.getElementById('androidSetupMsg');
            const resetBtn = document.getElementById('btnResetAndroidSetup');
            const assistBootstrapBtn = document.getElementById('btnIssueAssistBootstrap');
            const trackingBootstrapBtn = document.getElementById('btnIssueTrackingBootstrap');
            const bootstrapMsg = document.getElementById('bootstrapMsg');
            const assistTokenEl = document.getElementById('assistBootstrapToken');
            const trackingTokenEl = document.getElementById('trackingBootstrapToken');
            const assistMetaEl = document.getElementById('assistBootstrapMeta');
            const trackingMetaEl = document.getElementById('trackingBootstrapMeta');
            const printerSelect = document.getElementById('androidPrinterSelect');
            const printerManual = document.getElementById('androidPrinterManual');
            const printerStatus = document.getElementById('androidPrinterStatus');
            const printerDetectBtn = document.getElementById('btnDetectAndroidPrinters');
            const printerTestBtn = document.getElementById('btnAndroidPrinterTest');
            const printerTestStatus = document.getElementById('androidPrinterTestStatus');
            const printerConfigSection = document.getElementById('android-printer-config');
            const printerField = document.getElementById('android-printer-field');
            const externalSetupToken = <?php echo json_encode($externalSetupToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            let trackingBootstrapReady = false;

            function canUseAndroidPrinterBridge() {
                try {
                    return !!window.Android
                        && typeof window.Android.printerListJson === 'function'
                        && typeof window.Android.getDefaultPrinter === 'function';
                } catch (_) {
                    return false;
                }
            }

            function setPrinterStatus(text, tone) {
                if (!printerStatus) return;
                printerStatus.textContent = text;
                printerStatus.className = 'rounded-xl border px-3 py-2 text-xs ' + (
                    tone === 'ok' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-200' :
                    tone === 'error' ? 'border-rose-700/40 bg-rose-900/20 text-rose-200' :
                    'border-slate-800 bg-slate-950/70 text-slate-400'
                );
            }

            function setPrinterTestStatus(text, tone) {
                if (!printerTestStatus) return;
                printerTestStatus.textContent = text;
                printerTestStatus.className = 'rounded-xl border px-3 py-2 text-sm ' + (
                    tone === 'ok' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-200' :
                    tone === 'error' ? 'border-rose-700/40 bg-rose-900/20 text-rose-200' :
                    'border-slate-700 bg-slate-900/80 text-slate-200'
                );
                printerTestStatus.classList.remove('hidden');
            }

            function encodeBase64Utf8(text) {
                const bytes = new TextEncoder().encode(String(text || ''));
                let binary = '';
                bytes.forEach((b) => { binary += String.fromCharCode(b); });
                return btoa(binary);
            }

            function getSelectedTicketWidth() {
                const field = form?.elements?.namedItem('ticket_width');
                return String(field?.value || '80mm').trim().toLowerCase() === '58mm' ? '58mm' : '80mm';
            }

            function getSelectedAlignOffset() {
                const field = form?.elements?.namedItem('ticket_align_offset');
                const raw = Number.parseInt(String(field?.value || '0'), 10);
                if (Number.isNaN(raw)) return 0;
                return Math.max(-3, Math.min(3, raw));
            }

            function charsPerLineForWidth(width) {
                return width === '58mm' ? 32 : 48;
            }

            function centerTicketText(text, width, offset) {
                const raw = String(text || '');
                const lineWidth = charsPerLineForWidth(width);
                if (raw.length >= lineWidth) return raw;
                const left = Math.max(0, Math.floor((lineWidth - raw.length) / 2) + Number(offset || 0));
                return ' '.repeat(left) + raw;
            }

            function buildEscposTestPayload() {
                const ticketWidth = getSelectedTicketWidth();
                const alignOffset = getSelectedAlignOffset();
                const lineWidth = charsPerLineForWidth(ticketWidth);
                const divider = '-'.repeat(lineWidth);
                const logoGuide = centerTicketText(ticketWidth === '58mm' ? '[ LOGO 58mm ]' : '[   LOGO 80mm   ]', ticketWidth, alignOffset);
                const lines = [
                    '\u001b@',
                    '\u001ba\u0000',
                    logoGuide,
                    '\n',
                    centerTicketText('SISTEMAX PRO', ticketWidth, alignOffset),
                    '\n',
                    centerTicketText('PRUEBA DE IMPRESION', ticketWidth, alignOffset),
                    '\n',
                    centerTicketText('ANCHO ' + ticketWidth.toUpperCase(), ticketWidth, alignOffset),
                    '\n',
                    centerTicketText('AJUSTE ' + (alignOffset > 0 ? '+' : '') + String(alignOffset), ticketWidth, alignOffset),
                    '\n',
                    'Fecha: ' + new Date().toLocaleString('es-PY'),
                    '\n',
                    'Estado: Impresora Bluetooth OK',
                    '\n',
                    divider,
                    '\n',
                    centerTicketText('CENTRADO VISUAL', ticketWidth, alignOffset),
                    '\n',
                    centerTicketText('1234567890', ticketWidth, alignOffset),
                    '\n',
                    centerTicketText('Si esto sale al centro,', ticketWidth, alignOffset),
                    '\n',
                    centerTicketText('el logo tambien deberia centrar.', ticketWidth, alignOffset),
                    '\n',
                    divider,
                    '\n\n\n',
                    '\u001di\u0000'
                ];
                return encodeBase64Utf8(lines.join(''));
            }

            function getSelectedPrinterName() {
                const manual = String(printerManual?.value || '').trim();
                const selected = String(printerSelect?.value || '').trim();
                return manual || selected || '';
            }

            function syncManualPrinterFromSelect() {
                if (!printerSelect || !printerManual) return;
                const selected = String(printerSelect.value || '').trim();
                if (selected !== '') {
                    printerManual.value = selected;
                }
            }

            async function loadAndroidPrinters() {
                if (!printerSelect) return;
                printerDetectBtn && (printerDetectBtn.disabled = true);
                printerSelect.innerHTML = '<option value="">Detectando impresoras enlazadas...</option>';

                if (!canUseAndroidPrinterBridge()) {
                    setPrinterStatus('Abrí esta pantalla desde SistemaX Pro para listar las impresoras Bluetooth emparejadas del dispositivo.', 'info');
                    printerSelect.innerHTML = '<option value="">No disponible fuera de SistemaX Pro</option>';
                    printerDetectBtn && (printerDetectBtn.disabled = false);
                    return;
                }

                try {
                    const raw = window.Android.printerListJson();
                    const parsed = raw ? JSON.parse(raw) : null;
                    if (!parsed || parsed.ok !== true) {
                        throw new Error((parsed && parsed.error) || 'No se pudo leer la lista de impresoras');
                    }

                    const list = Array.isArray(parsed.data) ? parsed.data.map((item) => String(item || '').trim()).filter(Boolean) : [];
                    const defaultPrinter = String(window.Android.getDefaultPrinter() || '').trim();
                    printerSelect.innerHTML = '';

                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = list.length > 0 ? 'Seleccionar impresora Bluetooth' : 'No hay impresoras Bluetooth emparejadas';
                    printerSelect.appendChild(placeholder);

                    list.forEach((printerName) => {
                        const option = document.createElement('option');
                        option.value = printerName;
                        option.textContent = printerName;
                        if (defaultPrinter !== '' && printerName === defaultPrinter) {
                            option.selected = true;
                        }
                        printerSelect.appendChild(option);
                    });

                    if (defaultPrinter !== '') {
                        printerSelect.value = defaultPrinter;
                    } else if (printerManual && printerManual.value) {
                        printerSelect.value = printerManual.value;
                    }
                    syncManualPrinterFromSelect();

                    if (list.length > 0) {
                        setPrinterStatus(`${list.length} impresora(s) Bluetooth detectada(s)${defaultPrinter ? '. Predeterminada: ' + defaultPrinter : ''}.`, 'ok');
                    } else {
                        setPrinterStatus('No hay impresoras Bluetooth emparejadas en este dispositivo.', 'error');
                    }
                } catch (error) {
                    printerSelect.innerHTML = '<option value="">No se pudieron cargar las impresoras</option>';
                    setPrinterStatus(String(error?.message || 'No se pudieron cargar las impresoras Bluetooth'), 'error');
                } finally {
                    printerDetectBtn && (printerDetectBtn.disabled = false);
                }
            }

            async function runAndroidPrinterTest() {
                if (!canUseAndroidPrinterBridge()) {
                    setPrinterTestStatus('Esta prueba solo funciona dentro de SistemaX Pro Android.', 'error');
                    return;
                }
                const printerName = getSelectedPrinterName() || String(window.Android.getDefaultPrinter() || '').trim();
                if (!printerName) {
                    setPrinterTestStatus('Elegí una impresora Bluetooth o emparejá una primero en Android.', 'error');
                    return;
                }

                try {
                    setPrinterTestStatus('Enviando prueba de impresion...', 'info');
                    printerTestBtn && (printerTestBtn.disabled = true);
                    const raw = window.Android.printRawJson(printerName, buildEscposTestPayload());
                    const parsed = raw ? JSON.parse(raw) : null;
                    if (!parsed || parsed.ok !== true) {
                        throw new Error((parsed && parsed.error) || 'No se pudo imprimir la prueba');
                    }
                    const usedPrinter = String(parsed?.data?.printer || printerName).trim();
                    setPrinterTestStatus(`Prueba enviada correctamente a ${usedPrinter}.`, 'ok');
                    if (printerManual) {
                        printerManual.value = usedPrinter;
                    }
                    if (printerSelect) {
                        printerSelect.value = usedPrinter;
                    }
                } catch (error) {
                    setPrinterTestStatus(String(error?.message || 'No se pudo imprimir la prueba'), 'error');
                } finally {
                    printerTestBtn && (printerTestBtn.disabled = false);
                }
            }

            function focusAndroidPrinterSection() {
                const params = new URLSearchParams(window.location.search);
                if (params.get('focus') !== 'printer') {
                    return;
                }
                const target = printerField || printerConfigSection;
                if (!target) {
                    return;
                }
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('ring-2', 'ring-emerald-500/70', 'ring-offset-2', 'ring-offset-slate-950');
                window.setTimeout(function () {
                    target.classList.remove('ring-2', 'ring-emerald-500/70', 'ring-offset-2', 'ring-offset-slate-950');
                }, 2600);
            }

            function setMessage(text, tone) {
                if (!msg) return;
                msg.textContent = text;
                msg.className = 'rounded-xl border px-3 py-2 text-sm ' + (
                    tone === 'ok' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-200' :
                    'border-slate-700 bg-slate-900/80 text-slate-200'
                );
                msg.classList.remove('hidden');
            }

            function setBootstrapMessage(text, tone) {
                if (!bootstrapMsg) return;
                bootstrapMsg.textContent = text;
                bootstrapMsg.className = 'mt-4 rounded-xl border px-3 py-2 text-sm ' + (
                    tone === 'ok' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-200' :
                    tone === 'error' ? 'border-red-700/40 bg-red-900/20 text-red-200' :
                    'border-slate-700 bg-slate-900/80 text-slate-200'
                );
                bootstrapMsg.classList.remove('hidden');
            }

            async function fetchJsonStrict(url, options) {
                const res = await fetch(url, options || {});
                const raw = await res.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : null;
                } catch (_) {
                    throw new Error('La API devolvió una respuesta no JSON. Volvé a iniciar sesión e intentá otra vez.');
                }
                if (data?.auth_required) {
                    throw new Error(data?.error || 'Necesitás iniciar sesión en el navegador para generar el token.');
                }
                if (!res.ok || !data?.ok) {
                    throw new Error(data?.error || ('HTTP ' + res.status));
                }
                return data;
            }

            async function issueAssistBootstrap() {
                setBootstrapMessage('Generando token Assist...', 'info');
                const data = await fetchJsonStrict('/public/api/assist.php?action=client_issue_agent_bootstrap', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'client_issue_agent_bootstrap', setup_token: externalSetupToken })
                });
                if (!data?.data?.bootstrap_token) {
                    throw new Error('No se pudo generar el token Assist');
                }
                assistTokenEl.textContent = data.data.bootstrap_token;
                assistMetaEl.textContent = data.data.expires_at ? ('Vence: ' + data.data.expires_at) : '';
                setBootstrapMessage('Token Assist generado. Pegalo en la app Android.', 'ok');
            }

            async function issueTrackingBootstrap() {
                setBootstrapMessage('Generando token Tracking...', 'info');
                const data = await fetchJsonStrict('/public/api/mobile_tracking.php?action=issue_bootstrap_token', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'issue_bootstrap_token', setup_token: externalSetupToken })
                });
                if (!data?.data?.bootstrap_token) {
                    throw new Error('No se pudo generar el token Tracking');
                }
                trackingTokenEl.textContent = data.data.bootstrap_token;
                trackingMetaEl.textContent = data.data.expires_at ? ('Vence: ' + data.data.expires_at) : '';
                trackingBootstrapReady = true;
                setBootstrapMessage('Tracking listo. Instalá la app, abrila y aceptá ubicacion cuando Android lo solicite.', 'ok');
            }

            assistBootstrapBtn?.addEventListener('click', async function() {
                try {
                    assistBootstrapBtn.disabled = true;
                    await issueAssistBootstrap();
                } catch (error) {
                    setBootstrapMessage(error?.message || 'No se pudo generar el token Assist', 'error');
                } finally {
                    assistBootstrapBtn.disabled = false;
                }
            });

            trackingBootstrapBtn?.addEventListener('click', async function() {
                try {
                    trackingBootstrapBtn.disabled = true;
                    await issueTrackingBootstrap();
                } catch (error) {
                    setBootstrapMessage(error?.message || 'No se pudo generar el token Tracking', 'error');
                } finally {
                    trackingBootstrapBtn.disabled = false;
                }
            });

            async function autoPrepareTrackingBootstrap() {
                if (!externalSetupToken || trackingBootstrapReady) {
                    return;
                }
                try {
                    if (trackingBootstrapBtn) {
                        trackingBootstrapBtn.disabled = true;
                    }
                    await issueTrackingBootstrap();
                } catch (error) {
                    setBootstrapMessage(error?.message || 'No se pudo preparar Tracking automáticamente', 'error');
                } finally {
                    if (trackingBootstrapBtn) {
                        trackingBootstrapBtn.disabled = false;
                    }
                }
            }

            document.querySelectorAll('.js-open-android-app').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const deepLink = btn.getAttribute('data-app-deeplink') || '';
                    const intentUrl = btn.getAttribute('data-app-intent') || '';
                    if (!deepLink) {
                        return;
                    }
                    try {
                        window.location.href = deepLink;
                        if (intentUrl) {
                            window.setTimeout(function() {
                                window.location.href = intentUrl;
                            }, 900);
                        }
                    } catch (_) {
                        if (intentUrl) {
                            window.location.href = intentUrl;
                        }
                    }
                });
            });

            document.querySelectorAll('.js-open-assist-app').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const deepLink = btn.getAttribute('data-assist-deeplink') || '';
                    const intentUrl = btn.getAttribute('data-assist-intent') || '';
                    if (!deepLink) {
                        return;
                    }
                    try {
                        window.location.href = deepLink;
                        if (intentUrl) {
                            window.setTimeout(function() {
                                window.location.href = intentUrl;
                            }, 900);
                        }
                    } catch (_) {
                        if (intentUrl) {
                            window.location.href = intentUrl;
                        }
                    }
                });
            });

            document.querySelectorAll('.copy-bootstrap-btn').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    const targetId = btn.getAttribute('data-copy-target');
                    const target = targetId ? document.getElementById(targetId) : null;
                    const value = (target?.textContent || '').trim();
                    if (!value || value === 'Todavia no generado') {
                        setBootstrapMessage('Primero generá el token o usá la URL visible.', 'error');
                        return;
                    }
                    try {
                        await navigator.clipboard.writeText(value);
                        const original = btn.textContent;
                        btn.textContent = 'Copiado';
                        setTimeout(function() { btn.textContent = original; }, 1400);
                    } catch (_) {
                        setBootstrapMessage('No se pudo copiar automáticamente.', 'error');
                    }
                });
            });

            printerSelect?.addEventListener('change', function () {
                syncManualPrinterFromSelect();
            });

            printerDetectBtn?.addEventListener('click', function () {
                loadAndroidPrinters();
            });

            printerTestBtn?.addEventListener('click', function () {
                runAndroidPrinterTest();
            });

            function loadForm() {
                if (!form) return;
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    if (!raw) return;
                    const saved = JSON.parse(raw);
                    Object.entries(saved || {}).forEach(([key, value]) => {
                        const field = form.elements.namedItem(key);
                        if (!field) return;
                        if (field.type === 'checkbox') {
                            field.checked = !!value;
                        } else {
                            field.value = value ?? '';
                        }
                    });
                } catch (_) {}
            }

            form?.addEventListener('submit', function (e) {
                e.preventDefault();
                const fd = new FormData(form);
                const payload = {};
                fd.forEach((value, key) => {
                    payload[key] = value;
                });
                payload.uses_print = !!form.querySelector('[name="uses_print"]')?.checked;
                payload.battery_whitelist = !!form.querySelector('[name="battery_whitelist"]')?.checked;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
                setMessage('Configuracion Android guardada localmente.', 'ok');
            });

            resetBtn?.addEventListener('click', function () {
                localStorage.removeItem(STORAGE_KEY);
                form?.reset();
                setMessage('Ficha Android limpiada.', 'ok');
            });

            loadForm();
            loadAndroidPrinters();
            autoPrepareTrackingBootstrap();
            focusAndroidPrinterSection();
        });
    </script>
</body>
</html>
<?php
exit;
endif;

if ($printAgentOnly):
    $printAgentStorageKey = 'smx-print-agent-setup-v1';
    $agentProbeUrl = 'http://127.0.0.1:17890/health';
    $printAgentTitle = 'Agente de Impresion Directa';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($printAgentTitle); ?> - SistemaX</title>
    <link rel="manifest" href="/public/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto max-w-6xl px-4 py-8 space-y-5">
        <header class="rounded-3xl border border-cyan-900/60 bg-[radial-gradient(circle_at_top_left,_rgba(34,211,238,0.16),_transparent_30%),linear-gradient(180deg,_rgba(15,23,42,0.95),_rgba(2,6,23,0.98))] p-6 shadow-2xl shadow-cyan-950/30">
            <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center gap-2 rounded-full border border-cyan-700/40 bg-cyan-900/20 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.28em] text-cyan-200">Instalacion obligatoria</div>
                    <h1 class="mt-3 text-3xl font-black tracking-tight text-white"><?php echo htmlspecialchars($printAgentTitle); ?></h1>
                    <p class="mt-2 text-sm text-slate-300">Esta app instala el puente nativo que permite a SistemaX detectar impresoras locales, imprimir tickets ESC/POS y documentos A4/A5 sin depender del navegador.</p>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border border-slate-700 bg-slate-900/70 px-3 py-1 text-slate-200">Dispositivo detectado: <?php echo htmlspecialchars($platformLabel); ?></span>
                        <span class="rounded-full border border-emerald-700/40 bg-emerald-900/20 px-3 py-1 text-emerald-200">Requerido para impresion directa</span>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 text-sm text-slate-300 md:w-[320px]">
                    <div class="text-xs uppercase tracking-[0.28em] text-cyan-300">Que hace</div>
                    <ul class="mt-3 space-y-2">
                        <li>Detecta impresoras instaladas en tu PC.</li>
                        <li>Expone un bridge local en <code class="text-cyan-200"><?php echo htmlspecialchars($agentProbeUrl); ?></code>.</li>
                        <li>Permite elegir impresora, ancho de ticket y formato por usuario.</li>
                    </ul>
                </div>
            </div>
        </header>

        <section class="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-white">1. Descargar e instalar</h2>
                        <p class="text-sm text-slate-300">Elegi el instalador correcto para tu equipo. Si estas en Windows, usa el instalador `.exe`.</p>
                    </div>
                    <div id="printAgentStatusBadge" class="rounded-full border border-amber-700/40 bg-amber-900/20 px-3 py-1 text-xs font-semibold text-amber-200">Agent no probado</div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-cyan-800/40 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-cyan-300">Windows</div>
                        <p class="mt-2 text-xs text-slate-400">Instalador recomendado para la mayoria de los equipos.</p>
                        <?php if ($winExeAvailable): ?>
                            <a href="<?php echo htmlspecialchars($winExeUrl); ?>" class="mt-4 block rounded-xl bg-cyan-600 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-cyan-500">Descargar instalador EXE</a>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-700/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-200">Instalador Windows pendiente de publicar.</div>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-2xl border border-sky-800/40 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-sky-300">macOS</div>
                        <p class="mt-2 text-xs text-slate-400">Descarga el paquete, extraelo y ejecuta el script de instalacion.</p>
                        <?php if ($macAvailable): ?>
                            <a href="<?php echo htmlspecialchars($macUrl); ?>" class="mt-4 block rounded-xl bg-sky-600 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-sky-500">Descargar paquete macOS</a>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-700/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-200">Paquete macOS pendiente.</div>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-2xl border border-emerald-800/40 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-emerald-300">Linux</div>
                        <p class="mt-2 text-xs text-slate-400">Incluye scripts para instalar el servicio de usuario del agente.</p>
                        <?php if (is_file(__DIR__ . '/pos/downloads/sistemax-agent-linux-0.1.2.tar.gz')): ?>
                            <a href="/public/pos/downloads/sistemax-agent-linux-0.1.2.tar.gz" class="mt-4 block rounded-xl bg-emerald-600 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-emerald-500">Descargar paquete Linux</a>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-700/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-200">Paquete Linux pendiente.</div>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-2xl border border-violet-800/40 bg-slate-950/60 p-4">
                        <div class="text-sm font-bold text-violet-300">Android</div>
                        <p class="mt-2 text-xs text-slate-400">Usa SistemaX Print para impresion Bluetooth o puente local en Android.</p>
                        <?php if ($apkAvailable): ?>
                            <a href="<?php echo htmlspecialchars($apkUrl); ?>" class="mt-4 block rounded-xl bg-violet-600 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-violet-500">Descargar APK</a>
                        <?php else: ?>
                            <div class="mt-4 rounded-xl border border-amber-700/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-200">APK Android pendiente.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($macAvailable): ?>
                    <div class="mt-4 rounded-2xl border border-sky-800/40 bg-[linear-gradient(180deg,rgba(2,6,23,0.92),rgba(15,23,42,0.92))] p-4 md:p-5">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div class="max-w-2xl">
                                <div class="text-[11px] uppercase tracking-[0.25em] text-sky-300">Script Terminal macOS</div>
                                <h3 class="mt-2 text-base font-bold text-white">Ejecutá este bloque en Terminal después de descargar el paquete</h3>
                                <p class="mt-1 text-sm text-slate-300">Queda separado de las tarjetas para que se lea completo, con scroll horizontal si hace falta.</p>
                            </div>
                            <button type="button" class="copy-cmd-btn rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-slate-700 md:min-w-[220px]" data-cmd="<?php echo htmlspecialchars($macInstallCommand, ENT_QUOTES, 'UTF-8'); ?>">
                                Copiar comando macOS
                            </button>
                        </div>
                        <div class="mt-4 rounded-xl border border-amber-700/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-100">
                            Si macOS bloquea la app, abrí <strong>Privacidad y Seguridad</strong>, permití el binario descargado y volvé a ejecutar <code>./install-macos.sh</code>.
                        </div>
                        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-800 bg-black/70 p-4">
                            <pre id="printAgentMacInstallCmd" class="whitespace-pre-wrap break-words text-[13px] leading-7 font-mono text-emerald-300"><?php echo htmlspecialchars($macInstallCommand); ?></pre>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/70 p-4">
                    <h3 class="text-sm font-bold text-slate-100">Pasos de instalacion</h3>
                    <ol class="mt-3 space-y-2 text-sm text-slate-300">
                        <li>1. Descarga el instalador correcto para tu sistema operativo.</li>
                        <li>2. Ejecuta el instalador y acepta el permiso de red local o firewall cuando te lo pida.</li>
                        <li>3. Abre el agente una vez y verifica que quede escuchando en <code class="text-cyan-200">127.0.0.1:17890</code>.</li>
                        <li>4. Vuelve a SistemaX, abre este mismo panel y pulsa <strong>Probar agente</strong>.</li>
                        <li>5. Si usas ticket termico, luego selecciona la impresora y el ancho correcto: 58 mm o 80 mm.</li>
                    </ol>
                </div>
            </div>

            <div class="space-y-4">
                <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-lg font-bold text-white">2. Probar agente</h2>
                    <p class="mt-1 text-sm text-slate-300">Esta prueba confirma si el bridge local responde y si ya puede listar impresoras.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button id="btnPrintAgentTest" type="button" class="rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-cyan-500">Probar agente</button>
                        <button id="btnPrintAgentPrinters" type="button" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-700">Ver impresoras</button>
                    </div>
                    <pre id="printAgentOutput" class="mt-4 min-h-[180px] overflow-auto rounded-2xl border border-slate-800 bg-black/70 p-3 text-[12px] text-emerald-300"></pre>
                </section>

                <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-lg font-bold text-white">3. Formulario de configuracion</h2>
                    <p class="mt-1 text-sm text-slate-300">Completa esta ficha para dejar registrada la configuracion recomendada en este equipo. Se guarda localmente en tu navegador.</p>
                    <form id="printAgentSetupForm" class="mt-4 grid gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Nombre del equipo</label>
                            <input name="host_name" type="text" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500" placeholder="Ej: Caja principal" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Tipo de impresora</label>
                            <select name="printer_type" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                                <option value="escpos">Ticket termico ESC/POS</option>
                                <option value="sheet">Hoja A4 / A5</option>
                                <option value="mixed">Mixto</option>
                            </select>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Ancho / papel</label>
                                <select name="paper_width" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                                    <option value="58mm">58 mm</option>
                                    <option value="80mm">80 mm</option>
                                    <option value="a4">A4</option>
                                    <option value="a5">A5</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Modo recomendado</label>
                                <select name="print_mode" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                                    <option value="escpos">ESC/POS</option>
                                    <option value="auto">Auto detectar</option>
                                    <option value="a4">Documento A4</option>
                                    <option value="a5">Documento A5</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Impresora preferida</label>
                            <input name="printer_name" type="text" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500" placeholder="Nombre exacto de la impresora" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Observaciones</label>
                            <textarea name="notes" rows="3" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500" placeholder="Ej: usar 80mm, logo habilitado, impresora USB en caja 2"></textarea>
                        </div>
                        <label class="inline-flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950/70 px-3 py-2 text-sm text-slate-200">
                            <input name="autostart" type="checkbox" class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-cyan-500 focus:ring-cyan-500" />
                            Dejar el agente configurado para iniciar con el equipo
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <button id="btnSavePrintAgentSetup" type="submit" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-500">Guardar configuracion</button>
                            <button id="btnResetPrintAgentSetup" type="button" class="rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-700">Limpiar ficha</button>
                        </div>
                        <div id="printAgentFormMsg" class="hidden rounded-xl border px-3 py-2 text-sm"></div>
                    </form>
                </section>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="text-lg font-bold text-white">4. Instrucciones finales</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-3 text-sm text-slate-300">
                <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                    <div class="font-semibold text-cyan-300">ESC/POS</div>
                    <p class="mt-2">Si tu impresora es termica, instala el agente, selecciona la impresora exacta y define el ancho correcto del ticket. Sin eso, el logo y el centrado pueden salir mal.</p>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                    <div class="font-semibold text-sky-300">A4 / A5</div>
                    <p class="mt-2">Si imprimes en hoja, el agente sigue siendo util para detectar la impresora, pero el documento se enviara en formato hoja y no como ticket termico.</p>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                    <div class="font-semibold text-violet-300">iPhone / iPad</div>
                    <p class="mt-2">iOS no expone este tipo de bridge local para impresion directa dentro de este flujo. Usa AirPrint o un equipo intermedio con el agente instalado.</p>
                </div>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const STORAGE_KEY = <?php echo json_encode($printAgentStorageKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const AGENT_URL = <?php echo json_encode($agentUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const form = document.getElementById('printAgentSetupForm');
            const output = document.getElementById('printAgentOutput');
            const statusBadge = document.getElementById('printAgentStatusBadge');
            const formMsg = document.getElementById('printAgentFormMsg');
            const btnTest = document.getElementById('btnPrintAgentTest');
            const btnPrinters = document.getElementById('btnPrintAgentPrinters');
            const btnReset = document.getElementById('btnResetPrintAgentSetup');

            document.querySelectorAll('.copy-cmd-btn').forEach((btn) => {
                btn.addEventListener('click', async function () {
                    const cmd = btn.getAttribute('data-cmd') || '';
                    if (!cmd) return;
                    const original = btn.textContent;
                    try {
                        await navigator.clipboard.writeText(cmd);
                        btn.textContent = 'Copiado';
                        btn.classList.remove('bg-slate-800');
                        btn.classList.add('bg-emerald-700');
                    } catch (_) {
                        alert('No se pudo copiar automaticamente. Copialo manualmente desde el bloque.');
                    }
                    setTimeout(() => {
                        btn.textContent = original;
                        btn.classList.remove('bg-emerald-700');
                        btn.classList.add('bg-slate-800');
                    }, 1800);
                });
            });

            function setStatus(text, tone) {
                if (!statusBadge) return;
                statusBadge.textContent = text;
                statusBadge.className = 'rounded-full px-3 py-1 text-xs font-semibold border ' + (
                    tone === 'ok' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-200' :
                    tone === 'error' ? 'border-rose-700/40 bg-rose-900/20 text-rose-200' :
                    'border-amber-700/40 bg-amber-900/20 text-amber-200'
                );
            }

            function setFormMessage(text, tone) {
                if (!formMsg) return;
                formMsg.textContent = text;
                formMsg.className = 'rounded-xl border px-3 py-2 text-sm ' + (
                    tone === 'ok' ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-200' :
                    tone === 'error' ? 'border-rose-700/40 bg-rose-900/20 text-rose-200' :
                    'border-slate-700 bg-slate-900/80 text-slate-200'
                );
                formMsg.classList.remove('hidden');
            }

            function saveForm() {
                if (!form) return;
                const fd = new FormData(form);
                const payload = {};
                fd.forEach((value, key) => {
                    payload[key] = value;
                });
                payload.autostart = !!form.querySelector('[name="autostart"]')?.checked;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
            }

            function loadForm() {
                if (!form) return;
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    if (!raw) return;
                    const saved = JSON.parse(raw);
                    Object.entries(saved || {}).forEach(([key, value]) => {
                        const field = form.elements.namedItem(key);
                        if (!field) return;
                        if (field.type === 'checkbox') {
                            field.checked = !!value;
                        } else {
                            field.value = value ?? '';
                        }
                    });
                } catch (_) {}
            }

            async function fetchJson(path) {
                const res = await fetch(AGENT_URL + path);
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return await res.json();
            }

            async function probeAgent(showPrinters) {
                output.textContent = 'Consultando agente local...';
                try {
                    const health = await fetchJson('/health');
                    let data = { health };
                    if (showPrinters) {
                        const printers = await fetchJson('/printers');
                        const details = await fetchJson('/printers/details');
                        data.printers = printers;
                        data.details = details;
                    }
                    output.textContent = JSON.stringify(data, null, 2);
                    setStatus('Agent detectado', 'ok');
                    const hostField = form?.elements?.namedItem('host_name');
                    if (hostField && !hostField.value && health?.host_name) {
                        hostField.value = health.host_name;
                        saveForm();
                    }
                } catch (err) {
                    output.textContent = 'Error: ' + ((err && err.message) ? err.message : 'No se pudo conectar con el agente local.');
                    setStatus('Agent no disponible', 'error');
                }
            }

            form?.addEventListener('submit', function (e) {
                e.preventDefault();
                saveForm();
                setFormMessage('Configuracion guardada localmente en este navegador.', 'ok');
            });

            btnReset?.addEventListener('click', function () {
                localStorage.removeItem(STORAGE_KEY);
                form?.reset();
                setFormMessage('Ficha local limpiada.', 'ok');
            });

            btnTest?.addEventListener('click', function () {
                probeAgent(false);
            });

            btnPrinters?.addEventListener('click', function () {
                probeAgent(true);
            });

            loadForm();
            probeAgent(false);
        });
    </script>
</body>
</html>
<?php
exit;
endif;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Apps - SistemaX</title>
    <link rel="manifest" href="/public/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="w-full max-w-none px-4 md:px-6 py-8 space-y-4">
        <header class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h1 class="text-2xl font-black">Centro de Apps</h1>
            <p class="text-sm text-slate-300 mt-1">Instalá SistemaX paso a paso, de forma simple.</p>
            <p class="text-xs text-slate-400 mt-2">Dispositivo detectado: <strong><?php echo htmlspecialchars($platformLabel); ?></strong></p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-4 text-xs">
                <div class="rounded-lg px-2 py-2 text-center border <?php echo $platform === 'android' ? 'border-emerald-400 bg-emerald-900/30 text-emerald-200' : 'border-slate-700 text-slate-400'; ?>">Android</div>
                <div class="rounded-lg px-2 py-2 text-center border <?php echo $platform === 'windows' ? 'border-cyan-400 bg-cyan-900/30 text-cyan-200' : 'border-slate-700 text-slate-400'; ?>">Windows</div>
                <div class="rounded-lg px-2 py-2 text-center border <?php echo $platform === 'macos' ? 'border-sky-400 bg-sky-900/30 text-sky-200' : 'border-slate-700 text-slate-400'; ?>">macOS</div>
                <div class="rounded-lg px-2 py-2 text-center border <?php echo $platform === 'ios' ? 'border-indigo-400 bg-indigo-900/30 text-indigo-200' : 'border-slate-700 text-slate-400'; ?>">iPhone</div>
            </div>
        </header>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-2">
            <h2 class="text-base font-bold text-white">No sé qué opción usar</h2>
            <p class="text-sm text-slate-300">Elegí una opción rápida:</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-slate-200">
                    <strong>Celular Android:</strong> instalá SistemaX Pro + SistemaX Print.
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-slate-200">
                    <strong>PC Windows:</strong> usá “Instalar en Windows”.
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-slate-200">
                    <strong>Mac / iPhone:</strong> instalá como App (PWA) desde navegador.
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-white">Tutorial Paso a Paso</h2>
                    <p class="text-sm text-slate-300">Guía simple para instalar, pedir ayuda y compartir pantalla sin experiencia técnica.</p>
                </div>
                <span class="text-[11px] px-2 py-1 rounded-full bg-emerald-900/30 text-emerald-200 border border-emerald-700/40">Explicado fácil</span>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-xl bg-sky-900/40 text-sky-200 text-lg">1</span>
                        <h3 class="font-semibold text-sky-300">Si querés que Soporte te ayude en tu PC</h3>
                    </div>
                    <div class="flex flex-wrap gap-2 mb-3 text-[11px]">
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Descargar</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Instalar</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Solicitar soporte</span>
                    </div>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Buscá el bloque <strong>SistemaX Assist</strong>.</li>
                        <li>Descargá el instalador de tu sistema: Windows, macOS o Linux.</li>
                        <li>Abrí el archivo descargado y seguí los pasos de instalación.</li>
                        <li>Si tenés sesión iniciada, tocá <strong>Solicitar soporte</strong>.</li>
                        <li>Esperá a que soporte te indique el siguiente paso.</li>
                    </ol>
                    <div class="mt-3 rounded-lg border border-sky-700/30 bg-sky-900/20 px-3 py-2 text-xs text-sky-100">
                        Resultado esperado: soporte recibe tu pedido y te guía paso a paso.
                    </div>
                </div>

                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-xl bg-cyan-900/40 text-cyan-200 text-lg">2</span>
                        <h3 class="font-semibold text-cyan-300">Si querés compartir solo la pantalla</h3>
                    </div>
                    <div class="flex flex-wrap gap-2 mb-3 text-[11px]">
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Abrir app</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Compartir</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Detener</span>
                    </div>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Entrá a la app <strong>Compartir Mi Pantalla</strong> desde el menú principal.</li>
                        <li>Cuando soporte cree una sesión, vas a ver el estado listo para compartir.</li>
                        <li>Tocá <strong>Compartir</strong>.</li>
                        <li>Elegí la pantalla o ventana que querés mostrar.</li>
                        <li>Cuando termines, tocá <strong>Detener</strong>.</li>
                    </ol>
                    <div class="mt-3 rounded-lg border border-cyan-700/30 bg-cyan-900/20 px-3 py-2 text-xs text-cyan-100">
                        Resultado esperado: soporte ve tu pantalla, pero no controla tu PC desde esta web.
                    </div>
                </div>

                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-xl bg-emerald-900/40 text-emerald-200 text-lg">3</span>
                        <h3 class="font-semibold text-emerald-300">Si usás Android</h3>
                    </div>
                    <div class="flex flex-wrap gap-2 mb-3 text-[11px]">
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">SistemaX Pro</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">SistemaX Print</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Impresora</span>
                    </div>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Instalá <strong>SistemaX Pro</strong>.</li>
                        <li>Instalá <strong>SistemaX Print</strong>.</li>
                        <li>Aceptá permisos si Android los pide.</li>
                        <li>Abrí SistemaX Print y elegí tu impresora.</li>
                        <li>Volvé a SistemaX y probá imprimir.</li>
                    </ol>
                    <div class="mt-3 rounded-lg border border-emerald-700/30 bg-emerald-900/20 px-3 py-2 text-xs text-emerald-100">
                        Resultado esperado: el celular queda listo para usar SistemaX e imprimir.
                    </div>
                </div>

                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-xl bg-violet-900/40 text-violet-200 text-lg">4</span>
                        <h3 class="font-semibold text-violet-300">Si usás Mac o iPhone</h3>
                    </div>
                    <div class="flex flex-wrap gap-2 mb-3 text-[11px]">
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Mac</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">iPhone</span>
                        <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">App / PWA</span>
                    </div>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>En Mac, descargá el instalador de <strong>SistemaX Assist</strong> o instalá SistemaX como app.</li>
                        <li>En iPhone, abrí SistemaX en Safari.</li>
                        <li>Tocá <strong>Compartir</strong>.</li>
                        <li>Elegí <strong>Agregar a pantalla de inicio</strong>.</li>
                        <li>Usá ese acceso como si fuera una app.</li>
                    </ol>
                    <div class="mt-3 rounded-lg border border-violet-700/30 bg-violet-900/20 px-3 py-2 text-xs text-violet-100">
                        Resultado esperado: acceso rápido y simple desde Mac o iPhone.
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-amber-700/40 bg-amber-900/20 px-4 py-3 text-sm text-amber-100">
                <strong>Importante:</strong> el soporte solo puede ayudarte si vos aceptás la instalación o la compartición de pantalla. Nada se activa de forma oculta desde esta página.
            </div>
        </section>

        <?php if ($showAll || $platform === 'android'): ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-emerald-300">Android - APK nativo</h2>
                <span class="text-xs bg-emerald-600 text-white px-2 py-1 rounded-full">✨ Con Soporte de Cámara</span>
            </div>
            <div class="rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-100">
                Instalá el <strong>APK nativo de SistemaX Pro</strong> para tener Bluetooth, geolocalización y notificaciones en Android.
            </div>
            <div class="flex flex-wrap gap-2 text-[11px]">
                <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Bluetooth BT</span>
                <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Geolocalización</span>
                <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Notificaciones</span>
                <span class="px-2 py-1 rounded-full bg-slate-800 text-slate-200">Cámara</span>
            </div>
            
            <!-- Nueva sección: Características de Cámara -->
            <div class="rounded-xl border border-cyan-700/40 bg-cyan-900/20 px-3 py-2 space-y-2">
                <strong class="text-cyan-200 text-sm block">📸 Nuevo: Captura de Facturas con Cámara</strong>
                <ul class="text-xs text-cyan-100 space-y-1 ml-3">
                    <li>✅ Captura automática de fotos desde la cámara</li>
                    <li>✅ OCR inteligente para extraer datos</li>
                    <li>✅ Soporte completo en módulo Compras</li>
                    <li>✅ Fallback a galería si no hay cámara</li>
                </ul>
            </div>
            
            <?php if ($mainApkAvailable): ?>
                <div id="cardMainAndroidInstall" class="space-y-2">
                    <?php if ($isMobile): ?>
                        <a id="btnInstallMainNow" href="<?php echo htmlspecialchars($mainApkUrl); ?>"
                                class="block w-full text-center bg-indigo-600 hover:bg-indigo-500 text-white font-black py-3 rounded-xl transition-colors">
                            Instalar APK nativo SistemaX Pro v<?php echo htmlspecialchars($mainApkVersion); ?>
                        </a>
                        <?php if ($isAndroid): ?>
                            <a href="<?php echo htmlspecialchars($androidNativeOpenUrl); ?>"
                               class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 rounded-xl transition-colors">
                                Abrir SistemaX Pro si ya está instalado
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($mainApkUrl); ?>"
                           class="block w-full text-center bg-indigo-700 hover:bg-indigo-600 text-white font-bold py-3 rounded-xl transition-colors">
                            Descargar APK nativo SistemaX Pro v<?php echo htmlspecialchars($mainApkVersion); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($apkAvailable): ?>
                <div id="cardPrintAndroidInstall" class="space-y-2">
                    <?php if ($isMobile): ?>
                        <a id="btnInstallNow"
                                href="<?php echo htmlspecialchars($apkUrl); ?>"
                                class="block w-full text-center bg-fuchsia-600 hover:bg-fuchsia-500 text-white font-black py-3 rounded-xl transition-colors">
                            Paso 2: Instalar SistemaX Print
                        </a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($apkUrl); ?>"
                           class="block w-full text-center bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-3 rounded-xl transition-colors">
                            Paso 2: Descargar SistemaX Print
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="rounded-xl border border-amber-700 bg-amber-900/20 px-3 py-2 text-sm text-amber-200">
                    Aún no está disponible el instalador Android. Archivo esperado:
                    <code class="text-amber-100">public/pos/downloads/sistemax-agent-android.apk</code>
                </div>
            <?php endif; ?>

            <div id="androidAppsInstalledMsg" class="hidden rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-200">
                SistemaX Pro y SistemaX Print ya están instalados en este móvil.
            </div>

            <?php if ($srcAvailable && (!$isMobile || !$apkAvailable)): ?>
                <a href="<?php echo htmlspecialchars($srcUrl); ?>"
                   class="block w-full text-center bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition-colors">
                    Descargar codigo fuente Android
                </a>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($showAll || $platform === 'windows' || $platform === 'macos'): ?>
        <section id="soporte-desktop" class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-sky-300"><?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="text-sm text-slate-300">Cliente recomendado: <strong><?php echo htmlspecialchars($supportVendor); ?></strong> con consentimiento visible y uso auditado. No instala acceso oculto.</p>
                </div>
                <span class="text-[11px] px-2 py-1 rounded-full bg-sky-900/40 text-sky-200 border border-sky-700/50">Uso asistido</span>
            </div>
            <div class="rounded-xl border border-sky-700/30 bg-sky-900/20 px-3 py-3 text-sm text-sky-100">
                Este instalador está pensado para que Soporte SistemaX te asista con permiso visible. El control remoto total permanente no se habilita desde la web.
                <?php if ($supportHostLabel !== ''): ?>
                    <div class="mt-2 text-xs text-sky-200">Servidor/configuración esperada: <strong><?php echo htmlspecialchars($supportHostLabel); ?></strong></div>
                <?php endif; ?>
                    <?php if ($supportServerUrl !== ''): ?>
                    <div class="mt-2 text-xs text-sky-200">Instalación/portal: <strong><?php echo htmlspecialchars($supportServerUrl); ?></strong></div>
                    <?php endif; ?>
                    <?php if ($supportAccessUrl !== ''): ?>
                    <div class="mt-2 text-xs text-sky-200">Consola remota: <strong><?php echo htmlspecialchars($supportAccessUrl); ?></strong></div>
                    <?php endif; ?>
                    <div class="mt-2 text-xs text-sky-200">Mesa central de soporte tecnico Sistemax.pro</div>
            </div>
            <?php if ($supportHostLabel !== '' || $supportAccessUrl !== ''): ?>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-sm text-slate-300 space-y-3">
                <div class="font-semibold text-slate-100">Configuración de acceso de <?php echo htmlspecialchars($supportVendor); ?></div>
                <?php if ($supportHostLabel !== ''): ?>
                    <div><span class="text-slate-500">Host:</span> <span class="text-slate-100"><?php echo htmlspecialchars($supportHostLabel); ?></span></div>
                <?php endif; ?>
                <?php if ($supportServerUrl !== ''): ?>
                    <div><span class="text-slate-500">Portal:</span> <span class="text-slate-100 break-all"><?php echo htmlspecialchars($supportServerUrl); ?></span></div>
                <?php endif; ?>
                <?php if ($supportAccessUrl !== ''): ?>
                    <div><span class="text-slate-500">Consola:</span> <span class="text-slate-100 break-all"><?php echo htmlspecialchars($supportAccessUrl); ?></span></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div id="supportAgentDetectedBanner" class="hidden rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-3 py-3 text-sm text-emerald-200">
                Agent detectado en este equipo. Esto no significa que <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?> ya esté instalado. Si todavía no instalaste <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?>, descargalo abajo y después registrá el alias del equipo.
            </div>
            <div id="supportReinstallBlock" class="hidden rounded-xl border border-slate-700 bg-slate-950/60 p-3 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-semibold text-emerald-300">Agent ya instalado</div>
                        <div class="text-xs text-slate-400">Este bloque solo reinstala el agent local. <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?> se instala y configura por separado con los pasos de abajo.</div>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    <a href="<?php echo htmlspecialchars($supportWinFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportWinFinalUrl); ?> class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 rounded-xl transition-colors <?php echo $supportWinPublished ? '' : 'pointer-events-none opacity-50'; ?>">
                        Reinstalar en Windows
                    </a>
                    <a href="<?php echo htmlspecialchars($supportMacFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportMacFinalUrl); ?> class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 rounded-xl transition-colors <?php echo $supportMacPublished ? '' : 'pointer-events-none opacity-50'; ?>">
                        Reinstalar en macOS
                    </a>
                    <a href="<?php echo htmlspecialchars($supportLinuxFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportLinuxFinalUrl); ?> class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 rounded-xl transition-colors <?php echo $supportLinuxPublished ? '' : 'pointer-events-none opacity-50'; ?>">
                        Reinstalar en Linux
                    </a>
                </div>
            </div>
            <div id="supportInstallDownloadBlock" class="grid gap-3 md:grid-cols-3">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 space-y-2">
                    <div class="font-semibold text-cyan-300">Windows</div>
                    <?php if ($supportWinPublished): ?>
                        <a href="<?php echo htmlspecialchars($supportWinFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportWinFinalUrl); ?> class="block w-full text-center bg-cyan-600 hover:bg-cyan-500 text-white font-bold py-2.5 rounded-xl transition-colors">Descargar <?php echo htmlspecialchars($supportVendor); ?></a>
                    <?php else: ?>
                        <div class="text-xs text-amber-200">Pendiente publicar instalador Windows.</div>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 space-y-2">
                    <div class="font-semibold text-sky-300">macOS</div>
                    <?php if ($supportMacPublished): ?>
                        <a href="<?php echo htmlspecialchars($supportMacFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportMacFinalUrl); ?> class="block w-full text-center bg-sky-600 hover:bg-sky-500 text-white font-bold py-2.5 rounded-xl transition-colors">Descargar <?php echo htmlspecialchars($supportVendor); ?></a>
                    <?php else: ?>
                        <div class="text-xs text-amber-200">Pendiente publicar instalador macOS.</div>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 space-y-2">
                    <div class="font-semibold text-emerald-300">Linux</div>
                    <?php if ($supportLinuxPublished): ?>
                        <a href="<?php echo htmlspecialchars($supportLinuxFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportLinuxFinalUrl); ?> class="block w-full text-center bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 rounded-xl transition-colors">Descargar <?php echo htmlspecialchars($supportVendor); ?></a>
                    <?php else: ?>
                        <div class="text-xs text-amber-200">Pendiente publicar instalador Linux.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div id="supportInstallTerminalBlock" class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-sm text-slate-300">
                <strong class="text-slate-100">Instalación por Terminal:</strong>
                <p class="mt-1 text-xs text-slate-400">Copiá el comando según tu sistema. Abre la descarga o la página oficial de instalación de <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?>.</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl border border-slate-800 bg-black p-3">
                        <div class="text-[11px] uppercase tracking-[0.25em] text-cyan-300 mb-2">Windows</div>
                        <pre class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all"><?php echo htmlspecialchars($supportWinCliCommand); ?></pre>
                        <button class="mt-2 w-full rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold py-2 transition-colors copy-cmd-btn"
                                data-cmd="<?php echo htmlspecialchars($supportWinCliCommand, ENT_QUOTES, 'UTF-8'); ?>">
                            Copiar comando Windows
                        </button>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-black p-3">
                        <div class="text-[11px] uppercase tracking-[0.25em] text-sky-300 mb-2">macOS</div>
                        <pre class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all"><?php echo htmlspecialchars($supportMacCliCommand); ?></pre>
                        <p class="mt-2 text-[11px] text-slate-400"><?php echo htmlspecialchars($supportMacCliHelp); ?></p>
                        <div class="mt-2 rounded-lg border border-slate-800 bg-slate-950/80 p-2 text-[11px] text-slate-300">
                            <div class="font-semibold text-slate-100">Si macOS lo bloquea:</div>
                            <div class="mt-1">1. Abrí <span class="text-slate-100">Configuración del Sistema</span> → <span class="text-slate-100">Privacidad y seguridad</span>.</div>
                            <div>2. Permití abrir la app descargada.</div>
                            <div>3. Activá <span class="text-slate-100">Grabación de pantalla</span> y <span class="text-slate-100">Accesibilidad</span>.</div>
                        </div>
                        <button class="mt-2 w-full rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold py-2 transition-colors copy-cmd-btn"
                                data-cmd="<?php echo htmlspecialchars($supportMacCliCommand, ENT_QUOTES, 'UTF-8'); ?>">
                            Copiar comando macOS
                        </button>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-black p-3">
                        <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-300 mb-2">Linux</div>
                        <pre class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all"><?php echo htmlspecialchars($supportLinuxCliCommand); ?></pre>
                        <button class="mt-2 w-full rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold py-2 transition-colors copy-cmd-btn"
                                data-cmd="<?php echo htmlspecialchars($supportLinuxCliCommand, ENT_QUOTES, 'UTF-8'); ?>">
                            Copiar comando Linux
                        </button>
                    </div>
                </div>
            </div>
            <div id="supportInstallFlowBlock" class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-sm text-slate-300">
                <strong class="text-slate-100">Flujo recomendado:</strong>
                1) descargá el instalador de tu sistema, 2) ejecutalo con consentimiento del cliente, 3) probá el agent desde esta pantalla, 4) completá y registrá el acceso de <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?> para el soporte `<?php echo htmlspecialchars($supportCompaniesLabel, ENT_QUOTES, 'UTF-8'); ?>`, 5) cerrá la sesión al finalizar.
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-sm text-slate-300 space-y-4">
                <div>
                    <strong class="text-slate-100">Prueba y registro del equipo</strong>
                    <p class="mt-1 text-xs text-slate-400">Usá este asistente después de instalar el Agent. Va a comprobar el estado local y guardar tu PC en la mesa de soporte junto con los datos de <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?>.</p>
                </div>
                <div class="grid gap-3 md:grid-cols-4">
                    <div id="supportAgentCard" class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Agent</div>
                        <div id="supportAgentStatus" class="mt-2 text-sm text-slate-300">Pendiente</div>
                    </div>
                    <div id="supportRemoteCard" class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500"><?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div id="supportRemoteStatus" class="mt-2 text-sm text-slate-300">Pendiente</div>
                    </div>
                    <div id="supportPermCard" class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Permisos</div>
                        <div id="supportPermStatus" class="mt-2 text-sm text-slate-300">Pendiente</div>
                    </div>
                    <div id="supportRegisterCard" class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Registro</div>
                        <div id="supportRegisterStatus" class="mt-2 text-sm text-slate-300">Aún no enviado</div>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block">
                        <span class="text-xs text-slate-400">ID o alias <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?></span>
                        <input id="supportRemoteId" type="text" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-white" placeholder="Pegá el alias o nombre del agente <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <label class="block">
                        <span class="text-xs text-slate-400">Clave temporal / nota</span>
                        <input id="supportRemoteSecret" type="text" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-white" placeholder="Opcional: contraseña temporal o referencia">
                    </label>
                    <label class="block">
                        <span class="text-xs text-slate-400">Nombre del equipo</span>
                        <input id="supportHostName" type="text" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-white" placeholder="Se completa solo si el agent responde">
                    </label>
                    <label class="block">
                        <span class="text-xs text-slate-400">Notas opcionales</span>
                        <input id="supportEndpointNotes" type="text" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2.5 text-sm text-white" placeholder="Ej: PC caja principal, Mac oficina, etc.">
                    </label>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button id="btnSupportTestLocal" class="px-4 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">1. Probar agent</button>
                    <button id="btnSupportOpenRemote" class="px-4 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">2. Abrir <?php echo htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8'); ?></button>
                    <button id="btnSupportOpenPerms" class="px-4 py-3 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold">3. Abrir permisos del sistema</button>
                    <?php if ($isLoggedIn): ?>
                        <button id="btnSupportRegisterEndpoint" class="px-4 py-3 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-semibold">4. Registrar equipo en soporte</button>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-slate-800 bg-black p-3">
                    <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-300 mb-2">Resultado de la prueba</div>
                    <pre id="supportTestOutput" class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all">Esperando prueba local...</pre>
                </div>
            </div>
            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-sm text-slate-300">
                <strong class="text-slate-100">Documentación oficial:</strong>
                <a href="<?php echo htmlspecialchars($supportVendorDocsUrl); ?>" target="_blank" rel="noopener" class="text-sky-400 hover:underline"><?php echo htmlspecialchars($supportVendorDocsUrl); ?></a>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php if ($isLoggedIn): ?>
                    <button
                        id="btnRequestDesktopSupport"
                        data-platform="<?php echo htmlspecialchars($platform); ?>"
                        class="px-4 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold">
                        Solicitar soporte
                    </button>
                    <div class="text-xs text-slate-400 self-center">
                        <?php echo htmlspecialchars($sessionEmpresaName, ENT_QUOTES, 'UTF-8'); ?> · #<?php echo (int)$sessionEmpresa; ?> · Usuario <?php echo htmlspecialchars($sessionUserName, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php else: ?>
                    <a href="/public/login.php" class="px-4 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold">Iniciar sesión para solicitar soporte</a>
                <?php endif; ?>
            </div>
            <div id="desktopSupportMsg" class="hidden rounded-xl border px-3 py-2 text-sm"></div>
        </section>
        <?php endif; ?>

        <?php if ($showAll || $platform === 'windows' || $platform === 'macos'): ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-3">
            <h2 class="text-lg font-bold text-fuchsia-300">Computadora - Impresión directa</h2>
            <p class="text-sm text-slate-300">Usá este bloque si vas a imprimir desde una PC o notebook.</p>

            <?php if (($showAll || $platform === 'windows') && ($winExeAvailable || $winAvailable)): ?>
                <button
                    data-desktop-installer-url="<?php echo htmlspecialchars($winExeAvailable ? $winExeUrl : $winUrl); ?>"
                    class="btnDesktopInstallNow block w-full text-center bg-cyan-600 hover:bg-cyan-500 text-white font-black py-3 rounded-xl transition-colors">
                    <?php echo $winExeAvailable ? 'Paso 1: Instalar en Windows' : 'Paso 1: Descargar para Windows'; ?>
                </button>
                <details class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <summary class="cursor-pointer text-xs font-bold text-slate-200">Ver modo técnico (PowerShell)</summary>
                    <div class="space-y-2 mt-2">
                        <pre id="winInstallCmd" class="text-[11px] text-cyan-300 font-mono whitespace-pre-wrap break-all"><?php echo htmlspecialchars($winInstallCommand); ?></pre>
                        <button id="copyWinInstallCmd"
                                class="w-full text-center bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                            Copiar comando Windows
                        </button>
                    </div>
                </details>
            <?php elseif ($showAll || $platform === 'windows'): ?>
                <div class="rounded-xl border border-amber-700 bg-amber-900/20 px-3 py-2 text-sm text-amber-200">
                    Windows no publicado aun (subir paquete en <code class="text-amber-100">public/pos/downloads/</code>).
                </div>
            <?php endif; ?>

            <?php if (($showAll || $platform === 'macos') && $macAvailable): ?>
                <button
                    data-desktop-installer-url="<?php echo htmlspecialchars($macUrl); ?>"
                    class="btnDesktopInstallNow block w-full text-center bg-sky-600 hover:bg-sky-500 text-white font-black py-3 rounded-xl transition-colors">
                    Paso 1: Descargar para macOS
                </button>
                <p class="text-xs text-slate-400">
                    En macOS la instalación necesita un paso manual en Terminal.
                </p>
                <details class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <summary class="cursor-pointer text-xs font-bold text-slate-200">Ver modo técnico (Terminal)</summary>
                    <div class="space-y-2 mt-2">
                        <pre id="macInstallCmd" class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all"><?php echo htmlspecialchars($macInstallCommand); ?></pre>
                        <button id="copyMacInstallCmd"
                                class="w-full text-center bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold py-2 rounded-lg transition-colors">
                            Copiar comando macOS
                        </button>
                    </div>
                </details>
            <?php elseif ($showAll || $platform === 'macos'): ?>
                <div class="rounded-xl border border-amber-700 bg-amber-900/20 px-3 py-2 text-sm text-amber-200">
                    macOS no publicado aun (subir paquete en <code class="text-amber-100">public/pos/downloads/</code>).
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($showAll || $platform === 'windows' || $platform === 'macos'): ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-3">
            <h2 class="text-lg font-bold text-cyan-300">Instalar PWA (Desktop)</h2>
            <p id="pwaInstallHint" class="text-sm text-slate-300">
                Si usás Chrome o Edge, podés instalar SistemaX como app de escritorio.
            </p>
            <button id="btnInstallPwaDesktop"
                    class="hidden w-full text-center bg-cyan-600 hover:bg-cyan-500 text-white font-black py-3 rounded-xl transition-colors">
                Instalar SistemaX como App
            </button>
            <p class="text-xs text-slate-400">
                Si el botón no aparece, abrí <strong>/public/login.php</strong> en Chrome/Edge y usá el menú del navegador:
                <em>Instalar SistemaX</em>.
            </p>
        </section>
        <?php endif; ?>

        <?php if ($showAll || $platform === 'ios'): ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-3">
            <h2 class="text-lg font-bold text-sky-300">iPhone (iOS)</h2>
            <p class="text-sm text-slate-300">
                iOS no permite instalar APK/IPA directa fuera de App Store. Para uso inmediato, instalá la version web como app (PWA).
            </p>
            <ol class="text-sm text-slate-300 list-decimal ml-5 space-y-1">
                <li>Abrí SistemaX en Safari.</li>
                <li>Tocá Compartir.</li>
                <li>Elegí <strong>Agregar a pantalla de inicio</strong>.</li>
                <li>Listo: se instala como app en tu iPhone.</li>
            </ol>
            <a href="/public/login.php"
               class="block w-full text-center bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl transition-colors">
                Abrir SistemaX para instalar en iOS
            </a>
        </section>
        <?php endif; ?>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-4">
            <h2 class="text-lg font-bold text-amber-300">Ayuda rápida</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <h3 class="font-semibold text-emerald-300 mb-2">Android</h3>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Instalá <strong>SistemaX Pro</strong> y <strong>SistemaX Print</strong>.</li>
                        <li>Aceptá la instalación de apps externas cuando Android lo solicite.</li>
                        <li>Abrí SistemaX Print y elegí tu impresora Bluetooth.</li>
                        <li>Volvé al POS y probá imprimir una venta.</li>
                    </ol>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <h3 class="font-semibold text-cyan-300 mb-2">Windows</h3>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Descargá y ejecutá <strong>Sistemax-Agent-Setup.exe</strong>.</li>
                        <li>Permití la ejecución si Windows pregunta.</li>
                        <li>El instalador deja el Agent Print listo para iniciar solo.</li>
                        <li>Comprobá con <code>http://127.0.0.1:17890/health</code>.</li>
                    </ol>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <h3 class="font-semibold text-sky-300 mb-2">macOS</h3>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Descargá el paquete de macOS.</li>
                        <li>En Terminal, ejecutá el comando asistido de esta pantalla.</li>
                        <li>Si macOS bloquea, habilitalo en Privacidad y Seguridad.</li>
                        <li>Comprobá con <code>http://127.0.0.1:17890/health</code>.</li>
                    </ol>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <h3 class="font-semibold text-indigo-300 mb-2">iPhone (iOS)</h3>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Abrí SistemaX en Safari.</li>
                        <li>Tocá <strong>Compartir</strong> y luego <strong>Agregar a pantalla de inicio</strong>.</li>
                        <li>Usá la app para acceso rápido.</li>
                    </ol>
                </div>
            </div>
            <p class="text-xs text-slate-400">Si la impresión no sale: abrí primero SistemaX Print y luego reintentá desde POS.</p>
        </section>

        <?php if ($showAll || $platform === 'windows' || $platform === 'macos'): ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-4">
            <h2 class="text-lg font-bold text-cyan-300">SistemaX como App Desktop</h2>
            <p class="text-sm text-slate-300">También podés instalar SistemaX como aplicativo de escritorio (PWA) para abrirlo como app independiente.</p>
            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <h3 class="font-semibold text-cyan-300 mb-2">Windows (Chrome / Edge)</h3>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>Abrí <strong>https://sistemax.pro/public/login.php</strong> en Chrome o Edge.</li>
                        <li>Menú del navegador → <strong>Instalar SistemaX</strong> (o "Apps &gt; Instalar este sitio").</li>
                        <li>Confirmá y se creará acceso directo en escritorio/inicio.</li>
                        <li>Desde ahí abre como app separada del navegador.</li>
                    </ol>
                </div>
                <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                    <h3 class="font-semibold text-sky-300 mb-2">macOS (Safari / Chrome)</h3>
                    <ol class="list-decimal ml-5 text-sm text-slate-300 space-y-1">
                        <li>En Safari: Archivo → <strong>Añadir al Dock</strong>.</li>
                        <li>En Chrome: Menú → <strong>Instalar SistemaX</strong>.</li>
                        <li>Se agrega como app al Dock y Launchpad.</li>
                        <li>Iniciá sesión y usalo como aplicación nativa web.</li>
                    </ol>
                </div>
            </div>
            <a href="/public/login.php"
               class="block w-full text-center bg-cyan-600 hover:bg-cyan-500 text-white font-bold py-3 rounded-xl transition-colors">
                Abrir SistemaX para instalar como App Desktop
            </a>
        </section>
        <?php endif; ?>

        <!-- Nueva sección: Compilación Automática para Desarrolladores -->
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-3">
            <h2 class="text-lg font-bold text-violet-300">👨‍💻 Compilación Automática (Desarrolladores)</h2>
            <p class="text-sm text-slate-300">
                Si necesitás recompilar SistemaX Pro Android con actualizaciones (ej: nuevas características de cámara), usá este script.
            </p>
            
            <details class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                <summary class="cursor-pointer font-semibold text-violet-200">Ver instrucciones de compilación</summary>
                <div class="space-y-3 mt-3 text-sm text-slate-300">
                    <p>
                        <strong>Requisitos previos:</strong> Java 11+, Android SDK, Gradle
                    </p>
                    
                    <div class="rounded-lg bg-slate-950 p-2 border border-slate-700">
                        <p class="text-xs text-slate-400 mb-1">Comando para compilar DEBUG:</p>
                        <pre class="text-violet-300 text-xs font-mono whitespace-pre-wrap break-all">bash <?= htmlspecialchars($projectRoot . '/scripts/build_sistemax_pro_android.sh', ENT_QUOTES, 'UTF-8') ?> debug</pre>
                        <button class="mt-1 w-full text-center bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold py-1 rounded transition-colors copy-cmd-btn"
                                data-cmd="bash <?= htmlspecialchars($projectRoot . '/scripts/build_sistemax_pro_android.sh debug', ENT_QUOTES, 'UTF-8') ?>">
                            Copiar comando DEBUG
                        </button>
                    </div>

                    <div class="rounded-lg bg-slate-950 p-2 border border-slate-700">
                        <p class="text-xs text-slate-400 mb-1">Comando para compilar RELEASE (Play Store):</p>
                        <pre class="text-violet-300 text-xs font-mono whitespace-pre-wrap break-all">bash <?= htmlspecialchars($projectRoot . '/scripts/build_sistemax_pro_android.sh', ENT_QUOTES, 'UTF-8') ?> release</pre>
                        <button class="mt-1 w-full text-center bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold py-1 rounded transition-colors copy-cmd-btn"
                                data-cmd="bash <?= htmlspecialchars($projectRoot . '/scripts/build_sistemax_pro_android.sh release', ENT_QUOTES, 'UTF-8') ?>">
                            Copiar comando RELEASE
                        </button>
                    </div>

                    <p class="text-xs text-slate-400 mt-2">
                        El script compilará automáticamente y guardará el APK en:<br>
                        <code class="text-violet-300"><?= htmlspecialchars($projectRoot . '/public/pos/downloads/', ENT_QUOTES, 'UTF-8') ?></code>
                    </p>

                    <p class="text-xs text-slate-400">
                        ✅ El APK estará disponible en el Centro de Apps automáticamente.
                    </p>
                </div>
            </details>

            <details class="rounded-xl border border-slate-700 bg-slate-950/60 p-3">
                <summary class="cursor-pointer font-semibold text-violet-200">Ver cambios en cámara (v0.2.0+)</summary>
                <div class="space-y-2 mt-3 text-sm text-slate-300">
                    <p><strong>Últimas mejoras implementadas:</strong></p>
                    <ul class="ml-4 space-y-1 list-disc">
                        <li>✅ Permiso <code class="bg-slate-800 px-1 rounded">CAMERA</code> en AndroidManifest.xml</li>
                        <li>✅ Manejador <code class="bg-slate-800 px-1 rounded">VIDEO_CAPTURE</code> en MainActivity.kt</li>
                        <li>✅ Método <code class="bg-slate-800 px-1 rounded">hasCameraPermission()</code> para validación</li>
                        <li>✅ Fallback automático a galería si no hay cámara</li>
                        <li>✅ Modal de permisos mejorado con explicación</li>
                    </ul>
                    <p class="text-xs text-slate-400 mt-2">
                        Ver documentación completa en: <code class="bg-slate-800 px-1 rounded">INDICE_DOCUMENTACION_CAMARA.md</code>
                    </p>
                </div>
            </details>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <a href="/public/login.php" class="text-blue-400 hover:underline text-sm">Volver al login</a>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="text-lg font-bold text-violet-300 mb-3">QR de instalacion</h2>
            <p class="text-sm text-slate-300 mb-4">Escaneá este QR para abrir esta pagina en otro movil.</p>
            <div class="flex justify-center">
                <div id="qrInstall" class="bg-white p-3 rounded-xl"></div>
            </div>
            <p id="qrUrlText" class="text-xs text-slate-400 text-center mt-3 break-all"></p>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const isAndroidDevice = <?php echo $platform === 'android' ? 'true' : 'false'; ?>;
            const isMobileDevice = <?php echo $isMobile ? 'true' : 'false'; ?>;
            let deferredPwaPrompt = null;

            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/public/sw.js', { scope: '/public/' }).catch(function(err) {
                    console.warn('[PWA] SW no registrado:', err);
                });
            }

            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                deferredPwaPrompt = e;
                const btn = document.getElementById('btnInstallPwaDesktop');
                const hint = document.getElementById('pwaInstallHint');
                if (btn) btn.classList.remove('hidden');
                if (hint) hint.textContent = 'SistemaX ya está listo para instalarse como app.';
            });

            const pwaBtn = document.getElementById('btnInstallPwaDesktop');
            if (pwaBtn) {
                pwaBtn.addEventListener('click', async function() {
                    if (!deferredPwaPrompt) {
                        alert('Tu navegador no expuso el instalador automático. Usa el menú: Instalar SistemaX.');
                        return;
                    }
                    deferredPwaPrompt.prompt();
                    try { await deferredPwaPrompt.userChoice; } catch (_) {}
                    deferredPwaPrompt = null;
                    pwaBtn.classList.add('hidden');
                });
            }

            async function detectAndroidAppByScheme(schemeUrl, timeoutMs = 1400) {
                return new Promise((resolve) => {
                    let resolved = false;
                    let hidden = false;
                    const iframe = document.createElement('iframe');
                    iframe.style.display = 'none';

                    const finish = (result) => {
                        if (resolved) return;
                        resolved = true;
                        try { document.removeEventListener('visibilitychange', onVisibility, true); } catch (_) {}
                        try { iframe.remove(); } catch (_) {}
                        resolve(result);
                    };

                    const onVisibility = () => {
                        if (document.visibilityState === 'hidden' || document.hidden) {
                            hidden = true;
                            finish(true);
                        }
                    };

                    document.addEventListener('visibilitychange', onVisibility, true);
                    document.body.appendChild(iframe);
                    iframe.src = schemeUrl;

                    setTimeout(() => finish(hidden), timeoutMs);
                });
            }

            document.querySelectorAll('.btnDesktopInstallNow').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const url = btn.getAttribute('data-desktop-installer-url');
                    if (!url) return;
                    window.location.href = url;
                    setTimeout(function() {
                        alert('Descarga iniciada. Cuando termine, abrí el archivo descargado para continuar.');
                    }, 400);
                });
            });

            const installNowBtn = document.getElementById('btnInstallNow');
            if (installNowBtn) {
                installNowBtn.addEventListener('click', function() {
                    const apkUrl = installNowBtn.getAttribute('href');
                    if (!apkUrl) return;
                    const sep = apkUrl.indexOf('?') >= 0 ? '&' : '?';
                    window.location.href = apkUrl + sep + 't=' + Date.now();
                });
            }

            if (isAndroidDevice && isMobileDevice) {
                const mainCard = document.getElementById('cardMainAndroidInstall');
                const printCard = document.getElementById('cardPrintAndroidInstall');
                const installedMsg = document.getElementById('androidAppsInstalledMsg');

                // Detección best-effort por deep link
                Promise.all([
                    detectAndroidAppByScheme('sistemaxpro://open?ping=1'),
                    detectAndroidAppByScheme('sistemaxagent://print?ping=1')
                ]).then(([mainInstalled, printInstalled]) => {
                    if (mainInstalled && mainCard) mainCard.classList.add('hidden');
                    if (printInstalled && printCard) printCard.classList.add('hidden');
                    const allHidden = (!mainCard || mainCard.classList.contains('hidden')) &&
                                      (!printCard || printCard.classList.contains('hidden'));
                    if (allHidden && installedMsg) installedMsg.classList.remove('hidden');
                }).catch(() => {});
            }

            const copyMacBtn = document.getElementById('copyMacInstallCmd');
            const macCmdEl = document.getElementById('macInstallCmd');
            if (copyMacBtn && macCmdEl) {
                copyMacBtn.addEventListener('click', async function() {
                    const cmd = macCmdEl.textContent || '';
                    if (!cmd) return;
                    try {
                        await navigator.clipboard.writeText(cmd);
                        copyMacBtn.textContent = 'Comando copiado';
                        setTimeout(function() { copyMacBtn.textContent = 'Copiar comando macOS'; }, 1800);
                    } catch (_) {
                        alert('No se pudo copiar solo. Mantené presionado y copiá manualmente.');
                    }
                });
            }

            const copyWinBtn = document.getElementById('copyWinInstallCmd');
            const winCmdEl = document.getElementById('winInstallCmd');
            if (copyWinBtn && winCmdEl) {
                copyWinBtn.addEventListener('click', async function() {
                    const cmd = winCmdEl.textContent || '';
                    if (!cmd) return;
                    try {
                        await navigator.clipboard.writeText(cmd);
                        copyWinBtn.textContent = 'Comando copiado';
                        setTimeout(function() { copyWinBtn.textContent = 'Copiar comando Windows'; }, 1800);
                    } catch (_) {
                        alert('No se pudo copiar solo. Mantené presionado y copiá manualmente.');
                    }
                });
            }

            const url = window.location.origin + '/public/apps-moviles.php';
            const qrBox = document.getElementById('qrInstall');
            const urlText = document.getElementById('qrUrlText');
            if (urlText) urlText.textContent = url;
            if (qrBox && window.QRCode) {
                new QRCode(qrBox, {
                    text: url,
                    width: 220,
                    height: 220
                });
            } else if (qrBox) {
                const img = document.createElement('img');
                img.width = 220;
                img.height = 220;
                img.alt = 'QR SistemaX';
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(url);
                qrBox.appendChild(img);
            }

            // Copiar comando de compilación
            document.querySelectorAll('.copy-cmd-btn').forEach(btn => {
                btn.addEventListener('click', async function(e) {
                    e.preventDefault();
                    const cmd = this.getAttribute('data-cmd');
                    if (!cmd) return;
                    
                    try {
                        await navigator.clipboard.writeText(cmd);
                        const originalText = this.textContent;
                        this.textContent = '✅ Comando copiado';
                        this.classList.add('bg-emerald-700');
                        this.classList.remove('bg-slate-700');
                        
                        setTimeout(() => {
                            this.textContent = originalText;
                            this.classList.remove('bg-emerald-700');
                            this.classList.add('bg-slate-700');
                        }, 2000);
                    } catch (err) {
                        alert('No se pudo copiar. Seleccioná el texto manualmente e intentá de nuevo.');
                    }
                });
            });

            const desktopSupportBtn = document.getElementById('btnRequestDesktopSupport');
            const desktopSupportMsg = document.getElementById('desktopSupportMsg');
            const supportAgentStatus = document.getElementById('supportAgentStatus');
            const supportRemoteStatus = document.getElementById('supportRemoteStatus');
            const supportPermStatus = document.getElementById('supportPermStatus');
            const supportRegisterStatus = document.getElementById('supportRegisterStatus');
            const supportTestOutput = document.getElementById('supportTestOutput');
            const supportRemoteId = document.getElementById('supportRemoteId');
            const supportRemoteSecret = document.getElementById('supportRemoteSecret');
            const supportHostName = document.getElementById('supportHostName');
            const supportEndpointNotes = document.getElementById('supportEndpointNotes');
            const btnSupportTestLocal = document.getElementById('btnSupportTestLocal');
            const btnSupportOpenRemote = document.getElementById('btnSupportOpenRemote');
            const btnSupportOpenPerms = document.getElementById('btnSupportOpenPerms');
            const btnSupportRegisterEndpoint = document.getElementById('btnSupportRegisterEndpoint');
            const supportAgentDetectedBanner = document.getElementById('supportAgentDetectedBanner');
            const supportReinstallBlock = document.getElementById('supportReinstallBlock');
            const supportInstallDownloadBlock = document.getElementById('supportInstallDownloadBlock');
            const supportInstallTerminalBlock = document.getElementById('supportInstallTerminalBlock');
            const supportInstallFlowBlock = document.getElementById('supportInstallFlowBlock');
            const supportVendor = <?php echo json_encode($supportVendor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const supportAccessUrl = <?php echo json_encode($supportAccessUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            let latestSupportSnapshot = null;

            function setSupportInstallVisibility(agentDetected) {
                supportAgentDetectedBanner?.classList.toggle('hidden', !agentDetected);
                supportReinstallBlock?.classList.toggle('hidden', !agentDetected);
            }

            function setSupportCardStatus(el, text, tone) {
                if (!el) return;
                el.textContent = text;
                el.className = 'mt-2 text-sm ' + (
                    tone === 'ok' ? 'text-emerald-300' :
                    tone === 'warn' ? 'text-amber-300' :
                    tone === 'error' ? 'text-rose-300' :
                    'text-slate-300'
                );
            }

            function describePermissionsStatus(permissions, health) {
                const platform = String(health?.platform || '').toLowerCase();
                if (platform === 'darwin' && permissions?.manual_review) {
                    return {
                        text: 'Verificación manual en macOS',
                        tone: 'ok',
                    };
                }
                return {
                    text: (permissions?.screen_recording ? 'Pantalla OK' : 'Falta pantalla') + ' · ' + (permissions?.accessibility ? 'Accesibilidad OK' : 'Falta accesibilidad'),
                    tone: (permissions?.screen_recording && permissions?.accessibility) ? 'ok' : 'warn',
                };
            }

            function describeDwServiceStatus() {
                const alias = String(supportRemoteId?.value || '').trim();
                if (alias !== '') {
                    return { text: 'Alias cargado manualmente', tone: 'ok' };
                }
                return { text: 'Pendiente de instalar o abrir ' + supportVendor, tone: 'warn' };
            }

            async function fetchAgentJson(path, body) {
                const options = body ? {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                } : {};
                const res = await fetch('http://127.0.0.1:17890' + path, options);
                return await res.json();
            }

            function buildAgentOpenUrl(payload) {
                const url = new URL('http://127.0.0.1:17890/apps/open');
                Object.entries(payload || {}).forEach(([key, value]) => {
                    if (value !== null && value !== undefined && String(value).trim() !== '') {
                        url.searchParams.set(key, String(value));
                    }
                });
                url.searchParams.set('_ts', String(Date.now()));
                return url.toString();
            }

            async function runSupportLocalTest() {
                if (!supportTestOutput) return;
                supportTestOutput.textContent = 'Probando agent local...';
                latestSupportSnapshot = null;
                try {
                    const health = await fetchAgentJson('/health');
                    if (!health?.ok) throw new Error('Agent no responde correctamente');
                    const remoteSession = { installed: false, running: false, version: 'manual', id: '', host: '' };
                    const permissions = await fetchAgentJson('/permissions/status');
                    if (!health.platform) health.platform = navigator.platform || 'unknown';
                    latestSupportSnapshot = { health, remote: remoteSession, permissions };
                    setSupportInstallVisibility(true);
                    if (supportHostName && health?.host_name && !supportHostName.value.trim()) {
                        supportHostName.value = health.host_name;
                    }
                    setSupportCardStatus(supportAgentStatus, 'Activo · ' + (health.version || 'sin versión'), 'ok');
                    const dwStatus = describeDwServiceStatus();
                    setSupportCardStatus(supportRemoteStatus, dwStatus.text, dwStatus.tone);
                    const permState = describePermissionsStatus(permissions, health);
                    setSupportCardStatus(supportPermStatus, permState.text, permState.tone);
                    supportTestOutput.textContent = JSON.stringify(latestSupportSnapshot, null, 2);
                } catch (err) {
                    setSupportInstallVisibility(false);
                    setSupportCardStatus(supportAgentStatus, 'Agent no disponible', 'error');
                    setSupportCardStatus(supportRemoteStatus, 'Sin datos', 'error');
                    setSupportCardStatus(supportPermStatus, 'Sin datos', 'error');
                    supportTestOutput.textContent = 'Error: ' + ((err && err.message) ? err.message : 'No se pudo probar el equipo local.');
                }
            }

            async function detectSupportAgentOnLoad() {
                try {
                    const health = await fetchAgentJson('/health');
                    if (health?.ok) {
                        await runSupportLocalTest();
                        return;
                    }
                } catch (err) {
                }
                setSupportInstallVisibility(false);
            }

            if (btnSupportTestLocal) {
                btnSupportTestLocal.addEventListener('click', async function() {
                    btnSupportTestLocal.disabled = true;
                    const original = btnSupportTestLocal.textContent;
                    btnSupportTestLocal.textContent = 'Probando...';
                    await runSupportLocalTest();
                    btnSupportTestLocal.disabled = false;
                    btnSupportTestLocal.textContent = original;
                });
            }

            if (btnSupportOpenRemote) {
                btnSupportOpenRemote.addEventListener('click', async function() {
                    try {
                        if (!supportAccessUrl) throw new Error('Falta configurar la consola remota de ' + supportVendor + '.');
                        window.open(supportAccessUrl, '_blank', 'noopener,noreferrer');
                    } catch (err) {
                        alert((err && err.message) ? err.message : 'No se pudo abrir la consola ' + supportVendor);
                    }
                });
            }

            supportRemoteId?.addEventListener('input', function() {
                const dwStatus = describeDwServiceStatus();
                setSupportCardStatus(supportRemoteStatus, dwStatus.text, dwStatus.tone);
            });

            if (btnSupportOpenPerms) {
                btnSupportOpenPerms.addEventListener('click', async function() {
                    try {
                        const data = await fetchAgentJson('/permissions/open-settings', { });
                        if (!data?.ok) throw new Error(data?.error || 'No se pudo abrir la configuración del sistema');
                    } catch (err) {
                        alert((err && err.message) ? err.message : 'No se pudo abrir configuración del sistema');
                    }
                });
            }

            if (btnSupportRegisterEndpoint && supportRegisterStatus) {
                btnSupportRegisterEndpoint.addEventListener('click', async function() {
                    const remoteId = (supportRemoteId?.value || '').trim();
                    if (!remoteId) {
                        alert('Primero cargá el ID o alias del cliente.');
                        return;
                    }
                    btnSupportRegisterEndpoint.disabled = true;
                    const original = btnSupportRegisterEndpoint.textContent;
                    btnSupportRegisterEndpoint.textContent = 'Registrando...';
                    try {
                        if (!latestSupportSnapshot) {
                            await runSupportLocalTest();
                        }
                        if (!latestSupportSnapshot) {
                            throw new Error('Primero completá una prueba local correcta del agent.');
                        }
                        const res = await fetch('/public/soporte/api.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'register_endpoint',
                                host_name: (supportHostName?.value || '').trim(),
                                remote_id: remoteId,
                                remote_password: (supportRemoteSecret?.value || '').trim(),
                                remote_host: <?php echo json_encode($supportHostLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                                notes: (supportEndpointNotes?.value || '').trim(),
                                agent: latestSupportSnapshot?.health || {},
                                remote: { ...(latestSupportSnapshot?.remote || {}), vendor: supportVendor, id: remoteId },
                                permissions: latestSupportSnapshot?.permissions || {}
                            })
                        });
                        const data = await res.json();
                        if (!data?.ok) throw new Error(data?.error || 'No se pudo registrar el equipo');
                        setSupportCardStatus(supportRegisterStatus, 'Equipo registrado en mesa de soporte', 'ok');
                        supportTestOutput.textContent += '\n\nRegistro: ' + (data.message || 'OK');
                    } catch (err) {
                        setSupportCardStatus(supportRegisterStatus, 'Falló el registro', 'error');
                        alert((err && err.message) ? err.message : 'No se pudo registrar el equipo');
                    }
                    btnSupportRegisterEndpoint.disabled = false;
                    btnSupportRegisterEndpoint.textContent = original;
                });
            }

            if (desktopSupportBtn && desktopSupportMsg) {
                desktopSupportBtn.addEventListener('click', async function() {
                    desktopSupportBtn.disabled = true;
                    const original = desktopSupportBtn.textContent;
                    desktopSupportBtn.textContent = 'Enviando...';
                    desktopSupportMsg.classList.add('hidden');
                    try {
                        const res = await fetch('/public/soporte/api.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'request_desktop_support',
                                platform: desktopSupportBtn.getAttribute('data-platform') || 'other',
                                version: 'support-desktop-center-1',
                                notes: 'Solicitud enviada desde Centro de Apps'
                            })
                        });
                        const data = await res.json();
                        if (!data?.ok) throw new Error(data?.error || 'No se pudo enviar la solicitud');
                        desktopSupportMsg.className = 'rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-200';
                        desktopSupportMsg.textContent = data.message || 'Solicitud enviada a soporte.';
                        desktopSupportMsg.classList.remove('hidden');
                    } catch (e) {
                        desktopSupportMsg.className = 'rounded-xl border border-red-700/40 bg-red-900/20 px-3 py-2 text-sm text-red-200';
                        desktopSupportMsg.textContent = e.message || 'No se pudo enviar la solicitud.';
                        desktopSupportMsg.classList.remove('hidden');
                    }
                    desktopSupportBtn.disabled = false;
                    desktopSupportBtn.textContent = original;
                });
            }

            detectSupportAgentOnLoad();
        });
    </script>
</body>
</html>
