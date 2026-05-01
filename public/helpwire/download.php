<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

$idEmpresa = (int)Session::getIdEmpresa();
$sessionEmpresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$sessionUserName = (string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ($_SESSION['usuario'] ?? ''));
if (in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    header('Location: /public/helpwire/admin.php', true, 302);
    exit;
}

$platform = strtolower(trim((string)($_GET['platform'] ?? 'windows')));
if (!in_array($platform, ['windows', 'macos', 'linux', 'android', 'ios'], true)) {
    $platform = 'windows';
}

$platformMeta = [
    'windows' => [
        'label' => 'Windows',
        'accent' => 'cyan',
        'steps' => [
            'Descargá SistemaX Assist para Windows.',
            'Ejecutá el archivo descargado y aceptá la instalación del agente nativo.',
            'Volvé a SistemaX y continuá con "Probar agent".',
        ],
    ],
    'macos' => [
        'label' => 'macOS',
        'accent' => 'sky',
        'steps' => [
            'Descargá SistemaX Assist para macOS.',
            'Abrí la app y permití pantalla/accesibilidad si macOS lo solicita.',
            'Volvé a SistemaX y continuá con "Probar agent".',
        ],
    ],
    'linux' => [
        'label' => 'Linux',
        'accent' => 'emerald',
        'steps' => [
            'Descargá el paquete o instalador sugerido para Linux.',
            'Abrí el archivo o seguí el método de instalación indicado por tu distro.',
            'Volvé a SistemaX y continuá con "Probar agent".',
        ],
    ],
    'android' => [
        'label' => 'Android / Tablet',
        'accent' => 'emerald',
        'steps' => [
            'Solicitá soporte desde esta pantalla para avisar a la mesa técnica.',
            'Descargá e instalá la app nativa SistemaX Assist para Android si soporte te lo indica.',
            'Abrí la app, registrá el equipo y activá Compartir pantalla cuando soporte inicie la sesión.',
        ],
    ],
    'ios' => [
        'label' => 'iPhone / iPad',
        'accent' => 'sky',
        'steps' => [
            'No existe agente nativo completo para iPhone o iPad dentro de este flujo.',
            'Solicita soporte desde esta pantalla y seguí las indicaciones del equipo.',
            'Si soporte lo requiere, continuá el proceso en navegador externo o por otro canal.',
        ],
    ],
];

$meta = $platformMeta[$platform];
$downloadMap = [
    'windows' => '/public/soporte/downloads/sistemax-support-desktop-windows-0.1.0.exe',
    'macos' => '/public/soporte/downloads/sistemax-support-desktop-macos-0.1.0.tar.gz',
    'linux' => '/public/soporte/downloads/sistemax-support-desktop-linux-0.1.0.tar.gz',
];
$androidApkUrl = '/public/soporte/downloads/sistemax-assist-android.apk';
$androidApkAbsoluteUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $androidApkUrl;
$androidAdbCommand = implode("\n", [
    'cd ~/Downloads',
    'curl -L -o "sistemax-assist-android.apk" "' . $androidApkAbsoluteUrl . '"',
    'adb install -r sistemax-assist-android.apk',
]);
$desktopCommands = [
    'windows' => implode("\n", [
        '$dest = "$env:USERPROFILE\\Downloads\\sistemax-support-desktop-windows-0.1.4.exe"',
        'Invoke-WebRequest -Uri "' . (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/public/soporte/downloads/sistemax-support-desktop-windows-0.1.4.exe') . '" -OutFile $dest',
        'Start-Process -FilePath $dest',
    ]),
    'macos' => implode("\n", [
        'cd ~/Downloads',
        'curl -L -o "sistemax-support-desktop-macos-0.1.4.tar.gz" "' . (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/public/soporte/downloads/sistemax-support-desktop-macos-0.1.4.tar.gz') . '"',
        'tar -xzf "sistemax-support-desktop-macos-0.1.4.tar.gz"',
        'cd "sistemax-agent-macos-0.1.4"',
        'chmod +x install-macos.sh uninstall-macos.sh sistemax-agent-darwin-arm64',
        './install-macos.sh',
    ]),
    'linux' => implode("\n", [
        'cd ~/Downloads',
        'curl -L -o "sistemax-support-desktop-linux-0.1.2.tar.gz" "' . (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/public/soporte/downloads/sistemax-support-desktop-linux-0.1.2.tar.gz') . '"',
        'tar -xzf "sistemax-support-desktop-linux-0.1.2.tar.gz"',
        'cd "sistemax-agent-linux-0.1.2"',
        'chmod +x install-linux.sh uninstall-linux.sh',
        './install-linux.sh',
    ]),
];
$downloadUrl = $downloadMap[$platform] ?? '/public/soporte/index.php';
$docsUrl = '/public/soporte/index.php';
$backUrl = in_array($platform, ['android', 'ios'], true) ? '/public/menu/menu.php' : '/public/helpwire/index.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= in_array($platform, ['android', 'ios'], true) ? 'Soporte remoto móvil' : 'Descargar SistemaX Assist' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        <div class="rounded-3xl border border-slate-800 bg-slate-900 p-6 md:p-8">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-xs uppercase tracking-[0.3em] text-slate-400"><?= in_array($platform, ['android', 'ios'], true) ? 'Soporte móvil' : 'SistemaX Assist' ?></div>
                    <h1 class="mt-2 text-3xl font-black text-white"><?= in_array($platform, ['android', 'ios'], true) ? 'Guía de soporte para ' : 'Descarga guiada para ' ?><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="mt-3 text-slate-300"><?= $platform === 'android' ? 'En Android tablet ya podés usar la app nativa SistemaX Assist. Esta pantalla sigue siendo el punto correcto para solicitar soporte y bajar la app si hace falta.' : (in_array($platform, ['android', 'ios'], true) ? 'En móvil o tablet esta pantalla sirve para solicitar soporte y seguir el flujo correcto.' : 'Esta es la pantalla correcta para el cliente. Primero descargás SistemaX Assist y luego volvés a SistemaX para registrar y habilitar el soporte.') ?></p>
                </div>
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Volver</a>
            </div>
        </div>

        <?php if (in_array($platform, ['android', 'ios'], true)): ?>
        <section class="rounded-3xl border-2 border-blue-500/40 bg-gradient-to-br from-blue-900/50 via-sky-900/30 to-slate-900 p-6 md:p-8">
            <div class="text-xs uppercase tracking-[0.3em] text-blue-200">Paso 1 obligatorio</div>
            <h2 class="mt-2 text-2xl md:text-3xl font-black text-white">Solicitar soporte remoto</h2>
            <p class="mt-3 text-sm md:text-base text-blue-100">En Android tablet o iPad primero tenés que avisar al equipo de soporte desde acá. Abrir guías o descargas externas no genera la solicitud por sí solo.</p>
            <div class="mt-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <button id="btnRequestRemoteMobile" class="px-5 py-4 rounded-2xl bg-blue-500 hover:bg-blue-400 text-white text-base font-black shadow-lg shadow-blue-950/40">Solicitar soporte ahora</button>
                <div class="rounded-2xl border border-blue-400/20 bg-slate-950/40 px-3 py-3 text-xs text-blue-100/90">
                    <?= htmlspecialchars($sessionEmpresaName, ENT_QUOTES, 'UTF-8') ?> · #<?= (int)$idEmpresa ?><br>
                    Usuario <?= htmlspecialchars($sessionUserName, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <div id="remoteSupportMsg" class="hidden mt-4 rounded-xl border px-3 py-3 text-sm"></div>
        </section>
        <?php endif; ?>

        <div class="grid gap-6 md:grid-cols-[1.3fr_0.9fr]">
            <section class="rounded-3xl border border-slate-800 bg-slate-900 p-6">
                <h2 class="text-xl font-bold text-<?= htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8') ?>-300">Paso a paso</h2>
                <ol class="mt-4 space-y-3 text-slate-200">
                    <?php foreach ($meta['steps'] as $index => $step): ?>
                        <li class="flex gap-3">
                            <span class="mt-0.5 inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-800 text-sm font-semibold"><?= $index + 1 ?></span>
                            <span><?= htmlspecialchars($step, ENT_QUOTES, 'UTF-8') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <div class="mt-6 flex flex-wrap gap-3">
                    <?php if ($platform === 'android'): ?>
                    <a href="<?= htmlspecialchars($androidApkUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="px-5 py-3 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">
                        Descargar app Android
                    </a>
                    <?php endif; ?>
                    <?php if (!in_array($platform, ['android', 'ios'], true)): ?>
                    <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="px-5 py-3 rounded-2xl bg-<?= htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8') ?>-600 hover:bg-<?= htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8') ?>-500 text-white font-semibold">
                        Descargar SistemaX Assist
                    </a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($docsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="px-5 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white font-semibold">
                        Ver guía oficial
                    </a>
                </div>
                <?php if ($platform === 'android'): ?>
                <div class="mt-6 rounded-2xl border border-slate-800 bg-black p-4">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-300">Instalación por terminal opcional</div>
                        <button type="button" class="support-copy-command px-3 py-1 rounded-lg border border-emerald-700/50 bg-emerald-900/20 text-[11px] font-semibold text-emerald-200 hover:bg-emerald-800/30" data-command="<?= htmlspecialchars($androidAdbCommand, ENT_QUOTES, 'UTF-8') ?>">Copiar comando</button>
                    </div>
                    <div class="mb-3 text-xs text-slate-400">Si preferís instalar el APK desde una Mac o PC con `adb`, usá este bloque.</div>
                    <pre class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all"><?= htmlspecialchars($androidAdbCommand, ENT_QUOTES, 'UTF-8') ?></pre>
                </div>
                <?php elseif (isset($desktopCommands[$platform])): ?>
                <div class="mt-6 rounded-2xl border border-slate-800 bg-black p-4">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div class="text-[11px] uppercase tracking-[0.25em] text-emerald-300"><?= $platform === 'windows' ? 'Comando para PowerShell' : 'Comando para Terminal' ?></div>
                        <button type="button" class="support-copy-command px-3 py-1 rounded-lg border border-emerald-700/50 bg-emerald-900/20 text-[11px] font-semibold text-emerald-200 hover:bg-emerald-800/30" data-command="<?= htmlspecialchars($desktopCommands[$platform], ENT_QUOTES, 'UTF-8') ?>">Copiar comando</button>
                    </div>
                    <div class="mb-3 text-xs text-slate-400">Comando listo para copiar y ejecutar la instalación en <?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?>.</div>
                    <pre class="text-[11px] text-emerald-300 font-mono whitespace-pre-wrap break-all"><?= htmlspecialchars($desktopCommands[$platform], ENT_QUOTES, 'UTF-8') ?></pre>
                </div>
                <?php endif; ?>
            </section>

            <aside class="rounded-3xl border border-slate-800 bg-slate-900 p-6">
                <h2 class="text-lg font-bold text-white"><?= in_array($platform, ['android', 'ios'], true) ? 'Después de solicitar soporte' : 'Después de descargar' ?></h2>
                <ul class="mt-4 space-y-3 text-sm text-slate-300">
                    <?php if ($platform === 'android'): ?>
                    <li>Instalá la app `SistemaX Assist Android` desde el botón de descarga.</li>
                    <li>Cuando soporte te lo pida, abrí la app y tocá `Compartir pantalla`.</li>
                    <li>La conexión remota se confirma desde la consola Assist de soporte.</li>
                    <li>Si todavía no instalaste la app, podés volver después con la solicitud ya enviada.</li>
                    <?php elseif (in_array($platform, ['android', 'ios'], true)): ?>
                    <li>Esperá la respuesta del equipo de soporte.</li>
                    <li>Si soporte te lo pide, abrí la guía oficial en navegador externo.</li>
                    <li>Mantené la sesión dentro de SistemaX para no perder acceso.</li>
                    <li>El control remoto completo debe continuar fuera de esta app.</li>
                    <?php else: ?>
                    <li>Volvé a `SistemaX Assist` dentro de SistemaX.</li>
                    <li>Pulsá `1. Probar agent`.</li>
                    <li>Cargá tu ID o alias.</li>
                    <li>Registrá el equipo en soporte.</li>
                    <?php endif; ?>
                </ul>
                <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-950 p-4 text-sm text-slate-400">
                    Si el navegador bloquea la descarga, abrí el enlace en una pestaña nueva y seguí desde ahí.
                </div>
            </aside>
        </div>
    </main>
    <?php if (in_array($platform, ['android', 'ios'], true)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('btnRequestRemoteMobile');
        const msg = document.getElementById('remoteSupportMsg');
        const copyButtons = Array.from(document.querySelectorAll('.support-copy-command'));
        btn?.addEventListener('click', async function() {
            btn.disabled = true;
            const original = btn.textContent;
            btn.textContent = 'Enviando...';
            msg.className = 'mt-4 rounded-xl border border-blue-700/40 bg-blue-900/20 px-3 py-3 text-sm text-blue-100';
            msg.textContent = 'Enviando solicitud de soporte...';
            msg.classList.remove('hidden');
            try {
                const res = await fetch('/public/soporte/api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'request_desktop_support',
                        platform: <?= json_encode($platform, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                        version: 'support-mobile-assist-1',
                        notes: 'Solicitud enviada desde SistemaX Assist mobile/tablet'
                    })
                });
                const data = await res.json();
                if (!data?.ok) throw new Error(data?.error || 'No se pudo enviar la solicitud');
                msg.className = 'mt-4 rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-3 py-3 text-sm text-emerald-200';
                msg.textContent = data.message || 'Solicitud enviada a soporte.';
            } catch (err) {
                msg.className = 'mt-4 rounded-xl border border-red-700/40 bg-red-900/20 px-3 py-3 text-sm text-red-200';
                msg.textContent = (err && err.message) ? err.message : 'No se pudo enviar la solicitud.';
            }
            btn.disabled = false;
            btn.textContent = original;
        });
        copyButtons.forEach(function(copyBtn) {
            copyBtn.addEventListener('click', async function() {
                const command = copyBtn.getAttribute('data-command') || '';
                const original = copyBtn.textContent;
                try {
                    await navigator.clipboard.writeText(command);
                    copyBtn.textContent = 'Copiado';
                    copyBtn.classList.remove('text-emerald-200');
                    copyBtn.classList.add('text-white', 'bg-emerald-600');
                } catch (err) {
                    copyBtn.textContent = 'Copiá manual';
                }
                window.setTimeout(function() {
                    copyBtn.textContent = original;
                    copyBtn.classList.remove('text-white', 'bg-emerald-600');
                    copyBtn.classList.add('text-emerald-200');
                }, 1800);
            });
        });
    });
    </script>
    <?php else: ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const copyButtons = Array.from(document.querySelectorAll('.support-copy-command'));
        copyButtons.forEach(function(copyBtn) {
            copyBtn.addEventListener('click', async function() {
                const command = copyBtn.getAttribute('data-command') || '';
                const original = copyBtn.textContent;
                try {
                    await navigator.clipboard.writeText(command);
                    copyBtn.textContent = 'Copiado';
                    copyBtn.classList.remove('text-emerald-200');
                    copyBtn.classList.add('text-white', 'bg-emerald-600');
                } catch (err) {
                    copyBtn.textContent = 'Copiá manual';
                }
                window.setTimeout(function() {
                    copyBtn.textContent = original;
                    copyBtn.classList.remove('text-white', 'bg-emerald-600');
                    copyBtn.classList.add('text-emerald-200');
                }, 1800);
            });
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
