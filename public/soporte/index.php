<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

$isLoggedIn = (int)Session::getIdLogin() > 0;
$sessionEmpresa = (int)Session::getIdEmpresa();
$sessionEmpresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $sessionEmpresa));
$sessionUserName = (string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ($_SESSION['usuario'] ?? ''));

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

function supportNormalizeSupportUrl(string $url, string $fallback): string
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

function supportNormalizeRuntimeSettings(array $settings): array
{
    $defaults = [
        'support_remote_vendor' => 'SistemaX Assist',
        'support_remote_docs_url' => '/public/soporte/index.php',
        'support_remote_server_url' => '',
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
$supportDesktopSettings = supportNormalizeRuntimeSettings($supportDesktopSettings);

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isAndroid = (bool)preg_match('/Android/i', $ua);
$isIOS = (bool)preg_match('/iPhone|iPad|iPod/i', $ua);
$isWindows = (bool)preg_match('/Windows NT/i', $ua);
$isMacDesktop = (bool)preg_match('/Macintosh|Mac OS X/i', $ua) && !$isIOS;
$isLinuxDesktop = (bool)preg_match('/Linux|X11|Ubuntu|Debian|Fedora|CentOS/i', $ua) && !$isWindows && !$isMacDesktop && !$isAndroid;
$platform = $isAndroid ? 'android' : ($isIOS ? 'ios' : ($isWindows ? 'windows' : ($isMacDesktop ? 'macos' : ($isLinuxDesktop ? 'linux' : 'other'))));
$isMobileDevice = $isAndroid || $isIOS || (bool)preg_match('/Mobile|iPad|tablet/i', $ua);
if ($isMobileDevice && ($platform === 'android' || $platform === 'ios')) {
    header('Location: /public/helpwire/download.php?platform=' . rawurlencode($platform), true, 302);
    exit;
}
$showAllDesktopOptions = ($platform === 'other');

$supportWinCandidates = glob(__DIR__ . '/downloads/sistemax-support-desktop-windows*.exe') ?: [];
$supportMacCandidates = glob(__DIR__ . '/downloads/sistemax-support-desktop-macos*.tar.gz') ?: [];
$supportLinuxCandidates = glob(__DIR__ . '/downloads/sistemax-support-desktop-linux*.tar.gz') ?: [];
if (!empty($supportWinCandidates)) natsort($supportWinCandidates);
if (!empty($supportMacCandidates)) natsort($supportMacCandidates);
if (!empty($supportLinuxCandidates)) natsort($supportLinuxCandidates);
$supportWinPath = !empty($supportWinCandidates) ? end($supportWinCandidates) : (__DIR__ . '/downloads/sistemax-support-desktop-windows-0.1.0.exe');
$supportMacPath = !empty($supportMacCandidates) ? end($supportMacCandidates) : (__DIR__ . '/downloads/sistemax-support-desktop-macos-0.1.0.tar.gz');
$supportLinuxPath = !empty($supportLinuxCandidates) ? end($supportLinuxCandidates) : (__DIR__ . '/downloads/sistemax-support-desktop-linux-0.1.0.tar.gz');
$supportWinAvailable = is_file($supportWinPath);
$supportMacAvailable = is_file($supportMacPath);
$supportLinuxAvailable = is_file($supportLinuxPath);
$supportWinUrl = '/public/soporte/downloads/' . basename($supportWinPath);
$supportMacUrl = '/public/soporte/downloads/' . basename($supportMacPath);
$supportLinuxUrl = '/public/soporte/downloads/' . basename($supportLinuxPath);
$supportVendor = trim((string)($supportDesktopSettings['support_remote_vendor'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_VENDOR')) ?: 'SistemaX Assist';
$supportDocsDefaultUrl = '/public/soporte/index.php';
$supportPortalDefaultUrl = '';
$supportAccessDefaultUrl = '/public/soporte/assist_admin.php';
$supportVendorDocsUrl = supportNormalizeSupportUrl(
    trim((string)($supportDesktopSettings['support_remote_docs_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_DOCS_URL')),
    $supportDocsDefaultUrl
);
$supportWindowsOverrideUrl = supportNormalizeSupportUrl(
    trim((string)($supportDesktopSettings['support_desktop_windows_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_DESKTOP_WINDOWS_URL')),
    $supportPortalDefaultUrl
);
$supportMacOverrideUrl = supportNormalizeSupportUrl(
    trim((string)($supportDesktopSettings['support_desktop_macos_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_DESKTOP_MACOS_URL')),
    $supportPortalDefaultUrl
);
$supportLinuxOverrideUrl = supportNormalizeSupportUrl(
    trim((string)($supportDesktopSettings['support_desktop_linux_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_DESKTOP_LINUX_URL')),
    $supportPortalDefaultUrl
);
$supportHostLabel = trim((string)($supportDesktopSettings['support_remote_host_label'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_HOST_LABEL'));
$supportServerUrl = supportNormalizeSupportUrl(
    trim((string)($supportDesktopSettings['support_remote_server_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_SERVER_URL')),
    $supportPortalDefaultUrl
);
$supportAccessUrl = supportNormalizeSupportUrl(
    trim((string)($supportDesktopSettings['support_remote_access_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_ACCESS_URL')),
    $supportAccessDefaultUrl
);
$supportWinFinalUrl = supportClientDownloadUrl('windows', $supportWindowsOverrideUrl !== '' ? $supportWindowsOverrideUrl : ($supportWinAvailable ? $supportWinUrl : ''));
$supportMacFinalUrl = supportClientDownloadUrl('macos', $supportMacOverrideUrl !== '' ? $supportMacOverrideUrl : ($supportMacAvailable ? $supportMacUrl : ''));
$supportLinuxFinalUrl = supportClientDownloadUrl('linux', $supportLinuxOverrideUrl !== '' ? $supportLinuxOverrideUrl : ($supportLinuxAvailable ? $supportLinuxUrl : ''));
$supportWinCliUrl = supportAbsoluteUrl($supportWinFinalUrl);
$supportMacCliUrl = supportAbsoluteUrl($supportMacFinalUrl);
$supportLinuxCliUrl = supportAbsoluteUrl($supportLinuxFinalUrl);
$supportWinPublished = $supportWinFinalUrl !== '';
$supportMacPublished = $supportMacFinalUrl !== '';
$supportLinuxPublished = $supportLinuxFinalUrl !== '';
$supportWinFileName = $supportWinPublished ? basename(parse_url($supportWinFinalUrl, PHP_URL_PATH) ?: 'sistemax-support-desktop-windows.exe') : 'sistemax-support-desktop-windows.exe';
$supportMacFileName = $supportMacPublished ? basename(parse_url($supportMacFinalUrl, PHP_URL_PATH) ?: 'sistemax-support-desktop-macos.tar.gz') : 'sistemax-support-desktop-macos.tar.gz';
$supportLinuxFileName = $supportLinuxPublished ? basename(parse_url($supportLinuxFinalUrl, PHP_URL_PATH) ?: 'sistemax-support-desktop-linux.tar.gz') : 'sistemax-support-desktop-linux.tar.gz';
$supportMacFolderName = preg_replace('/^sistemax-support-desktop-macos-/', 'sistemax-agent-macos-', preg_replace('/\.tar\.gz$/', '', $supportMacFileName)) ?: 'sistemax-agent-macos';
$supportLinuxFolderName = preg_replace('/\.tar\.gz$/', '', $supportLinuxFileName) ?: 'sistemax-agent-linux';
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
$supportWinCliCommand =
    '$dest = "$env:USERPROFILE\\Downloads\\' . $supportWinFileName . "\"\n" .
    'Invoke-WebRequest -Uri "' . $supportWinCliUrl . '" -OutFile $dest' . "\n" .
    'Start-Process -FilePath $dest';
$supportMacCliHelp = 'Copiá todo este bloque, pegalo en Terminal y presioná Enter. Se descarga e instala el agente nativo de ' . $supportVendor . ' en macOS.';
$supportMacCliCommand = implode("\n", [
    'cd ~/Downloads',
    'curl -L -o "' . $supportMacFileName . '" "' . $supportMacCliUrl . '"',
    'tar -xzf "' . $supportMacFileName . '"',
    'cd "' . $supportMacFolderName . '"',
    'chmod +x install-macos.sh uninstall-macos.sh sistemax-agent-darwin-arm64',
    './install-macos.sh',
]);
$supportLinuxCliCommand = implode("\n", [
    'cd ~/Downloads',
    'curl -L -o "' . $supportLinuxFileName . '" "' . $supportLinuxCliUrl . '"',
    'tar -xzf "' . $supportLinuxFileName . '"',
    'cd "' . $supportLinuxFolderName . '"',
    'chmod +x install-linux.sh uninstall-linux.sh',
    './install-linux.sh',
]);
$supportAndroidGuideUrl = '/public/helpwire/download.php?platform=android';
$supportIosGuideUrl = '/public/helpwire/download.php?platform=ios';
$supportAndroidApkUrl = '/public/soporte/downloads/sistemax-assist-android.apk';
$supportAssistApiUrl = supportAbsoluteUrl('/public/api/assist.php');
$supportAndroidCliCommand = implode("\n", [
    'cd ~/Downloads',
    'curl -L -o "sistemax-assist-android.apk" "' . supportAbsoluteUrl($supportAndroidApkUrl) . '"',
    'adb install -r sistemax-assist-android.apk',
]);
$supportTutorials = [
    'windows' => [
        'label' => 'Windows',
        'badge' => 'Detectado',
        'steps' => [
            'Descargá el instalador `.exe` de SistemaX Assist.',
            'Ejecutá el instalador y aceptá los permisos de Windows.',
            'Volvé a esta pantalla y verificá que aparezca el ID de conexión.',
        ],
        'download_url' => $supportWinFinalUrl,
        'download_label' => 'Descargar instalador Windows',
        'command_label' => 'PowerShell',
        'command' => $supportWinCliCommand,
    ],
    'macos' => [
        'label' => 'macOS',
        'badge' => 'Detectado',
        'steps' => [
            'Descargá el paquete `.tar.gz` de SistemaX Assist.',
            'Abrí Terminal, pegá el bloque y ejecutá la instalación del agente.',
            'Si macOS bloquea el binario, permitilo en Privacidad y seguridad y corré el instalador otra vez.',
        ],
        'download_url' => $supportMacFinalUrl,
        'download_label' => 'Descargar paquete macOS',
        'command_label' => 'Comando para Terminal',
        'command' => $supportMacCliCommand,
        'help' => $supportMacCliHelp,
    ],
    'linux' => [
        'label' => 'Linux',
        'badge' => 'Detectado',
        'steps' => [
            'Descargá el paquete `.tar.gz` de SistemaX Assist.',
            'Abrí una terminal y ejecutá el bloque de instalación.',
            'Volvé a esta pantalla y confirmá que el ID de conexión quedó visible.',
        ],
        'download_url' => $supportLinuxFinalUrl,
        'download_label' => 'Descargar paquete Linux',
        'command_label' => 'Comando para Terminal',
        'command' => $supportLinuxCliCommand,
    ],
    'android' => [
        'label' => 'Android',
        'badge' => 'Detectado',
        'steps' => [
            'Descargá el APK `SistemaX Assist Android`.',
            'Instalalo en la tablet o teléfono Android.',
            'Abrí la app y dejá que el equipo quede registrado en Assist Console.',
        ],
        'download_url' => $supportAndroidApkUrl,
        'download_label' => 'Descargar APK Android',
        'command_label' => 'ADB opcional',
        'command' => $supportAndroidCliCommand,
        'help' => 'Android no requiere terminal en el propio equipo. Este bloque sirve si querés instalar el APK por `adb` desde una PC o Mac.',
    ],
];
$supportTutorialOrder = [$platform];
foreach (['macos', 'windows', 'linux', 'android'] as $tutorialPlatform) {
    if (!in_array($tutorialPlatform, $supportTutorialOrder, true)) {
        $supportTutorialOrder[] = $tutorialPlatform;
    }
}
$primaryInstallUrl = '/public/helpwire/download.php?platform=' . rawurlencode($platform);
if ($platform === 'windows' && $supportWinPublished) {
    $primaryInstallUrl = $supportWinFinalUrl;
} elseif ($platform === 'macos' && $supportMacPublished) {
    $primaryInstallUrl = $supportMacFinalUrl;
} elseif ($platform === 'linux' && $supportLinuxPublished) {
    $primaryInstallUrl = $supportLinuxFinalUrl;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="w-full max-w-none px-4 md:px-6 py-6">
        <section id="soporte-desktop" class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-sky-300"><?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="text-sm text-slate-300">Instalá el agente nativo de <strong><?php echo htmlspecialchars($supportVendor); ?></strong>. Si el agente ya está instalado en este equipo, esta pantalla te muestra el ID de conexión registrado.</p>
                </div>
                <span class="text-[11px] px-2 py-1 rounded-full bg-sky-900/40 text-sky-200 border border-sky-700/50">Agente nativo</span>
            </div>

            <div class="rounded-3xl border-2 border-blue-500/50 bg-gradient-to-br from-blue-900/50 via-sky-900/30 to-slate-900 p-5 md:p-6 shadow-[0_0_0_1px_rgba(59,130,246,0.15)]">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div class="text-xs uppercase tracking-[0.3em] text-blue-200">Instalación Assist</div>
                        <div class="mt-2 text-2xl md:text-3xl font-black text-white">Instalar agente o ver ID de conexión</div>
                        <p class="mt-3 text-sm md:text-base text-blue-100 max-w-2xl">El flujo esperado es simple: instalás el agente y el equipo queda registrado en la lista de máquinas del soporte. Si ya está instalado en este equipo, se muestra directamente su ID.</p>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full border border-blue-400/40 bg-blue-950/50 px-3 py-1 text-blue-100">1. Instalar agente</span>
                            <span class="rounded-full border border-slate-600 bg-slate-900/70 px-3 py-1 text-slate-200">2. Registro automático</span>
                            <span class="rounded-full border border-slate-600 bg-slate-900/70 px-3 py-1 text-slate-200">3. Ver ID de conexión</span>
                        </div>
                    </div>
                    <div id="supportConnectionCard" class="flex flex-col gap-3 md:min-w-[320px]">
                        <button id="btnSupportInstallAgent" class="w-full px-5 py-4 rounded-2xl bg-blue-500 hover:bg-blue-400 text-white text-base font-black shadow-lg shadow-blue-950/40">Instalar agente Assist</button>
                        <div class="rounded-2xl border border-blue-400/20 bg-slate-950/40 px-3 py-3 text-xs text-blue-100/90">
                            <div class="uppercase tracking-[0.2em] text-blue-200/80">ID de conexión</div>
                            <div id="supportConnectionId" class="mt-2 text-3xl font-black text-white">Detectando...</div>
                            <div id="supportConnectionStatus" class="mt-2 text-xs text-blue-100/90">Buscando agente local en este equipo.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-emerald-600/30 bg-emerald-950/20 px-4 py-3 text-sm text-emerald-100">
                Cuando el agente local responde, esta pantalla lo registra automáticamente en la lista de máquinas del soporte.
            </div>

            <div class="rounded-xl border border-sky-700/30 bg-sky-900/20 px-3 py-3 text-sm text-sky-100">
                Este instalador está pensado para que Soporte SistemaX te asista con permiso visible usando el agente nativo. El control remoto total permanente no se habilita desde la web.
                <div class="mt-2 text-xs text-sky-200">Host actual: <strong><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost'); ?></strong></div>
                <div class="mt-2 text-xs text-sky-200">Assist API URL usada: <strong><?php echo htmlspecialchars($supportAssistApiUrl); ?></strong></div>
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
                Agent detectado en este equipo. Esto no significa que <?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?> ya esté instalado. Si todavía no lo instalaste, descargalo abajo y después registrá el alias del equipo.
            </div>

            <div id="supportReinstallBlock" class="hidden rounded-xl border border-slate-700 bg-slate-950/60 p-3 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-semibold text-emerald-300">Agent ya instalado</div>
                        <div class="text-xs text-slate-400">Este bloque solo reinstala el agent local. <?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?> se instala y configura por separado con los pasos de abajo.</div>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-<?php echo $showAllDesktopOptions ? '3' : '1'; ?>">
                    <?php if ($showAllDesktopOptions || $platform === 'windows'): ?>
                    <a href="<?php echo htmlspecialchars($supportWinFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportWinFinalUrl); ?> class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 rounded-xl transition-colors <?php echo $supportWinPublished ? '' : 'pointer-events-none opacity-50'; ?>">
                        Reinstalar en Windows
                    </a>
                    <?php endif; ?>
                    <?php if ($showAllDesktopOptions || $platform === 'macos'): ?>
                    <a href="<?php echo htmlspecialchars($supportMacFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportMacFinalUrl); ?> class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 rounded-xl transition-colors <?php echo $supportMacPublished ? '' : 'pointer-events-none opacity-50'; ?>">
                        Reinstalar en macOS
                    </a>
                    <?php endif; ?>
                    <?php if ($showAllDesktopOptions || $platform === 'linux'): ?>
                    <a href="<?php echo htmlspecialchars($supportLinuxFinalUrl); ?>"<?php echo supportLinkTargetAttrs($supportLinuxFinalUrl); ?> class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2.5 rounded-xl transition-colors <?php echo $supportLinuxPublished ? '' : 'pointer-events-none opacity-50'; ?>">
                        Reinstalar en Linux
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 text-sm text-slate-300 space-y-3">
                <div class="font-semibold text-slate-100">Estado del agente en este equipo</div>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Agente local</div>
                        <div id="supportAgentStatus" class="mt-2 text-sm text-slate-300">Detectando...</div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Registro Assist</div>
                        <div id="supportRegisterStatus" class="mt-2 text-sm text-slate-300">Pendiente</div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Máquinas soporte</div>
                        <div id="supportListStatus" class="mt-2 text-sm text-slate-300">Se actualiza al registrar.</div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button id="btnSupportRefreshState" class="px-4 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-semibold">Actualizar estado</button>
                    <a id="btnSupportOpenConsole" href="<?php echo htmlspecialchars($supportAccessUrl); ?>" class="px-4 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">Abrir consola soporte</a>
                </div>
                <div class="rounded-xl border border-slate-800 bg-black p-3">
                    <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-300 mb-2">Detalle técnico</div>
                    <pre id="supportTestOutput" class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all">Esperando detección local...</pre>
                </div>
            </div>

            <section class="rounded-xl border border-slate-700 bg-slate-950/60 p-4 space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-semibold text-slate-100">Tutorial de instalación por sistema operativo</div>
                        <div class="text-xs text-slate-400">Se muestra primero el tutorial de la plataforma detectada en este equipo.</div>
                    </div>
                    <div class="text-[11px] uppercase tracking-[0.25em] text-sky-300"><?= htmlspecialchars(strtoupper($platform), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="space-y-4">
                    <?php foreach ($supportTutorialOrder as $tutorialPlatform): ?>
                        <?php if (!isset($supportTutorials[$tutorialPlatform])) { continue; } ?>
                        <?php $tutorial = $supportTutorials[$tutorialPlatform]; ?>
                        <?php $isDetectedTutorial = ($tutorialPlatform === $platform); ?>
                        <article class="rounded-2xl border <?= $isDetectedTutorial ? 'border-blue-500/40 bg-blue-950/20' : 'border-slate-800 bg-slate-950' ?> p-4 space-y-3">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-lg font-bold text-white"><?= htmlspecialchars($tutorial['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                <span class="rounded-full px-3 py-1 text-[11px] font-semibold <?= $isDetectedTutorial ? 'bg-blue-500/20 text-blue-200 border border-blue-400/30' : 'bg-slate-800 text-slate-300 border border-slate-700' ?>">
                                    <?= $isDetectedTutorial ? 'Detectado en este equipo' : 'También disponible' ?>
                                </span>
                            </div>
                            <ol class="space-y-2 text-sm text-slate-200">
                                <?php foreach ($tutorial['steps'] as $index => $step): ?>
                                    <li class="flex gap-3">
                                        <span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-800 text-xs font-semibold"><?= $index + 1 ?></span>
                                        <span><?= htmlspecialchars($step, ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <div class="flex flex-wrap gap-3">
                                <a href="<?= htmlspecialchars($tutorial['download_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-semibold">
                                    <?= htmlspecialchars($tutorial['download_label'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </div>
                            <?php if (!empty($tutorial['help'])): ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-900/70 px-3 py-2 text-xs text-slate-300"><?= htmlspecialchars($tutorial['help'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div class="rounded-xl border border-slate-800 bg-black p-3">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-300"><?= htmlspecialchars($tutorial['command_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <button
                                        type="button"
                                        class="support-copy-command px-3 py-1 rounded-lg border border-emerald-700/50 bg-emerald-900/20 text-[11px] font-semibold text-emerald-200 hover:bg-emerald-800/30"
                                        data-command="<?= htmlspecialchars($tutorial['command'], ENT_QUOTES, 'UTF-8') ?>"
                                    >Copiar comando</button>
                                </div>
                                <pre class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all"><?= htmlspecialchars($tutorial['command'], ENT_QUOTES, 'UTF-8') ?></pre>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="rounded-xl border border-slate-700 bg-slate-950/60 p-3 text-sm text-slate-300">
                <strong class="text-slate-100">Documentación oficial:</strong>
                <a href="<?php echo htmlspecialchars($supportVendorDocsUrl); ?>" target="_blank" rel="noopener" class="text-sky-400 hover:underline"><?php echo htmlspecialchars($supportVendorDocsUrl); ?></a>
            </div>

            <div id="desktopSupportMsg" class="hidden rounded-xl border px-3 py-2 text-sm"></div>
        </section>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const btnSupportInstallAgent = document.getElementById('btnSupportInstallAgent');
        const btnSupportRefreshState = document.getElementById('btnSupportRefreshState');
        const copyButtons = Array.from(document.querySelectorAll('.support-copy-command'));
        const supportConnectionId = document.getElementById('supportConnectionId');
        const supportConnectionStatus = document.getElementById('supportConnectionStatus');
        const desktopSupportMsg = document.getElementById('desktopSupportMsg');
        const supportAgentStatus = document.getElementById('supportAgentStatus');
        const supportRegisterStatus = document.getElementById('supportRegisterStatus');
        const supportListStatus = document.getElementById('supportListStatus');
        const supportTestOutput = document.getElementById('supportTestOutput');
        const primaryInstallUrl = <?php echo json_encode($primaryInstallUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const assistApiUrl = <?php echo json_encode($supportAssistApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        let latestSupportSnapshot = null;

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

        async function fetchAgentJson(path, body) {
            const options = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
            const res = await fetch('http://127.0.0.1:17890' + path, options);
            return await res.json();
        }

        async function fetchAssistJson(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            return await res.json();
        }

        async function ensureAgentRegistered(state) {
            const issue = await fetchAssistJson('/public/api/assist.php?action=client_issue_agent_bootstrap', { action: 'client_issue_agent_bootstrap' });
            if (!issue?.ok || !issue?.data?.bootstrap_token) {
                throw new Error(issue?.error || 'No se pudo emitir bootstrap token');
            }
            const sync = await fetchAgentJson('/assist/sync', {
                bootstrap_token: issue.data.bootstrap_token,
                assist_api_url: assistApiUrl,
                device_name: state?.device_name || state?.host_name || 'Equipo SistemaX',
                status: 'online'
            });
            if (!sync?.ok) {
                throw new Error(sync?.error || 'No se pudo registrar el agente');
            }
            return sync.data || sync;
        }

        async function refreshAssistState() {
            supportTestOutput.textContent = 'Detectando agente local...';
            latestSupportSnapshot = null;
            try {
                const local = await fetchAgentJson('/assist/status');
                if (!local?.ok) throw new Error('El agente local no respondió');
                let state = local.state || {};
                setSupportCardStatus(supportAgentStatus, 'Instalado · ' + (state.agent_version || 'sin versión'), 'ok');

                if (!local.registered) {
                    setSupportCardStatus(supportRegisterStatus, 'Registrando automáticamente...', 'warn');
                    state = await ensureAgentRegistered(state);
                } else {
                    await fetchAgentJson('/assist/heartbeat', { status: 'online' });
                }

                const deviceId = Number(state.device_id || 0);
                if (deviceId > 0) {
                    btnSupportInstallAgent?.classList.add('hidden');
                    supportConnectionId.textContent = '#' + deviceId;
                    supportConnectionStatus.textContent = 'Agente registrado y visible en la consola de soporte.';
                    setSupportCardStatus(supportRegisterStatus, 'Registrado · Device #' + deviceId, 'ok');
                    setSupportCardStatus(supportListStatus, 'Equipo agregado a la lista de máquinas.', 'ok');
                } else {
                    throw new Error('El agente respondió pero no devolvió un device_id válido');
                }

                latestSupportSnapshot = { local, state };
                supportTestOutput.textContent = JSON.stringify(latestSupportSnapshot, null, 2);
            } catch (err) {
                btnSupportInstallAgent?.classList.remove('hidden');
                supportConnectionId.textContent = '-';
                supportConnectionStatus.textContent = 'Todavía no se detectó un agente Assist instalado en este equipo.';
                setSupportCardStatus(supportAgentStatus, 'No detectado', 'warn');
                setSupportCardStatus(supportRegisterStatus, 'Pendiente de instalación', 'warn');
                setSupportCardStatus(supportListStatus, 'Todavía no aparece en la lista de máquinas.', 'warn');
                supportTestOutput.textContent = 'Error: ' + ((err && err.message) ? err.message : 'No se pudo detectar el agente local.');
            }
        }

        btnSupportInstallAgent?.addEventListener('click', function() {
            window.open(primaryInstallUrl, '_blank', 'noopener,noreferrer');
        });

        btnSupportRefreshState?.addEventListener('click', async function() {
            btnSupportRefreshState.disabled = true;
            const original = btnSupportRefreshState.textContent;
            btnSupportRefreshState.textContent = 'Actualizando...';
            await refreshAssistState();
            btnSupportRefreshState.disabled = false;
            btnSupportRefreshState.textContent = original;
        });

        copyButtons.forEach(function(btn) {
            btn.addEventListener('click', async function() {
                const command = btn.getAttribute('data-command') || '';
                const original = btn.textContent;
                try {
                    await navigator.clipboard.writeText(command);
                    btn.textContent = 'Copiado';
                    btn.classList.remove('text-emerald-200');
                    btn.classList.add('text-white', 'bg-emerald-600');
                } catch (err) {
                    btn.textContent = 'Copiá manual';
                }
                window.setTimeout(function() {
                    btn.textContent = original;
                    btn.classList.remove('text-white', 'bg-emerald-600');
                    btn.classList.add('text-emerald-200');
                }, 1800);
            });
        });

        refreshAssistState();
    });
    </script>
</body>
</html>
