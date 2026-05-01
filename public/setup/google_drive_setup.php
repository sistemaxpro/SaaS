<?php
/**
 * Google Drive Setup Wizard — OAuth 2.0
 *
 * Flujo:
 *  1. Usuario crea OAuth Client ID en Google Cloud Console
 *  2. Pega client_id + client_secret aquí
 *  3. Click "Autorizar" → redirige a Google → vuelve con code
 *  4. Se intercambia code por refresh_token (persistente)
 *  5. Se crea la carpeta SistemaXPro automáticamente
 *  6. Test de subida/eliminación
 */
session_start();
$configDir  = realpath(__DIR__ . '/../../config');
$cfgPath    = $configDir . '/google_drive.php';

// Detectar URL base para redirect_uri
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'sistemax.pro';
$self   = $_SERVER['REQUEST_URI'] ?? '/public/setup/google_drive_setup.php';
// Limpiar query string del self
$selfClean = strtok($self, '?');
$redirectUri = "{$scheme}://{$host}{$selfClean}";

// ── Cargar config actual ──
$cfg = @include $cfgPath;
if (!is_array($cfg)) $cfg = [];

// ── Manejar callback de Google OAuth ──
if (isset($_GET['code']) && !empty($cfg['oauth_client_id'])) {
    $code = $_GET['code'];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'code'          => $code,
            'client_id'     => $cfg['oauth_client_id'],
            'client_secret' => $cfg['oauth_client_secret'],
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]),
    ]);
    $body = curl_exec($ch); curl_close($ch);
    $data = json_decode($body, true);

    if (!empty($data['refresh_token'])) {
        $cfg['oauth_refresh_token'] = $data['refresh_token'];

        // También crear carpeta automáticamente con el access_token
        $accessToken = $data['access_token'];
        $folderId = createOrFindRootFolder($accessToken);
        if ($folderId) {
            $cfg['root_folder_id'] = $folderId;
        }

        saveConfig($cfgPath, $cfg);
        $_SESSION['oauth_success'] = true;
        $_SESSION['oauth_msg'] = '¡Autorización exitosa! Refresh token guardado.';
        if ($folderId) $_SESSION['oauth_msg'] .= " Carpeta creada: {$folderId}";
    } else {
        $_SESSION['oauth_error'] = 'No se recibió refresh_token. Error: ' . ($data['error_description'] ?? $data['error'] ?? json_encode($data));
    }

    // Redirect para limpiar el ?code= de la URL
    header("Location: {$selfClean}");
    exit;
}

// ── Manejar callback de error de OAuth ──
if (isset($_GET['error'])) {
    $_SESSION['oauth_error'] = 'Error OAuth: ' . ($_GET['error_description'] ?? $_GET['error']);
    header("Location: {$selfClean}");
    exit;
}

// ── Acciones AJAX ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['wizard_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['wizard_action'];

    // -- Guardar OAuth credentials --
    if ($action === 'save_oauth') {
        $clientId     = trim($_POST['client_id'] ?? '');
        $clientSecret = trim($_POST['client_secret'] ?? '');

        if (empty($clientId) || empty($clientSecret)) {
            echo json_encode(['ok' => false, 'msg' => 'Completá Client ID y Client Secret.']);
            exit;
        }
        if (!str_contains($clientId, '.apps.googleusercontent.com')) {
            echo json_encode(['ok' => false, 'msg' => 'Client ID no parece válido. Debe terminar en .apps.googleusercontent.com']);
            exit;
        }

        $cfg['oauth_client_id']     = $clientId;
        $cfg['oauth_client_secret'] = $clientSecret;
        saveConfig($cfgPath, $cfg);

        // Generar URL de autorización
        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        echo json_encode(['ok' => true, 'msg' => 'Credenciales guardadas.', 'auth_url' => $authUrl]);
        exit;
    }

    // -- Test completo --
    if ($action === 'test') {
        try {
            require_once __DIR__ . '/../../src/Services/GoogleDriveService.php';
            $drive = new GoogleDriveService();

            // Crear imagen de prueba
            $img = imagecreatetruecolor(80, 80);
            $cyan = imagecolorallocate($img, 0, 200, 200);
            imagefill($img, 0, 0, $cyan);
            imagestring($img, 4, 15, 30, 'TEST', imagecolorallocate($img, 255, 255, 255));
            ob_start(); imagepng($img); $bin = ob_get_clean(); imagedestroy($img);

            // Subir
            $result = $drive->uploadProductImage('_test_wizard', 0, $bin, 'test.png');

            // Verificar URL pública
            sleep(1);
            $ch = curl_init($result['url']);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Limpiar
            $drive->deleteImage($result['id']);
            // Limpiar carpeta _test_wizard
            cleanupTestFolder($cfg);

            echo json_encode([
                'ok'        => true,
                'msg'       => '¡Todo funciona perfectamente!',
                'test_url'  => $result['url'],
                'public_ok' => in_array($httpCode, [200, 302, 303]),
                'http_code' => $httpCode,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
    exit;
}

// ── Funciones auxiliares ──

function createOrFindRootFolder(string $token): ?string {
    // Buscar si ya existe
    $q = urlencode("name = 'SistemaXPro' and 'root' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
    $ch = curl_init("https://www.googleapis.com/drive/v3/files?q={$q}&fields=files(id,name)");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
    $body = curl_exec($ch); curl_close($ch);
    $data = json_decode($body, true);
    if (!empty($data['files'][0]['id'])) return $data['files'][0]['id'];

    // Crear
    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(['name' => 'SistemaXPro', 'mimeType' => 'application/vnd.google-apps.folder']),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
    ]);
    $body = curl_exec($ch); curl_close($ch);
    $data = json_decode($body, true);
    return $data['id'] ?? null;
}

function cleanupTestFolder(array $cfg): void {
    try {
        // Refresh token para obtener access_token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $cfg['oauth_client_id'], 'client_secret' => $cfg['oauth_client_secret'],
                'refresh_token' => $cfg['oauth_refresh_token'], 'grant_type' => 'refresh_token',
            ]),
        ]);
        $tok = json_decode(curl_exec($ch), true); curl_close($ch);
        $token = $tok['access_token'] ?? '';
        if (!$token) return;

        $rootId = $cfg['root_folder_id'] ?? '';
        if (!$rootId) return;

        $q = urlencode("name = '_test_wizard' and '{$rootId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
        $ch = curl_init("https://www.googleapis.com/drive/v3/files?q={$q}&fields=files(id)");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
        $body = curl_exec($ch); curl_close($ch);
        $data = json_decode($body, true);
        foreach (($data['files'] ?? []) as $f) {
            $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$f['id']}");
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
            curl_exec($ch); curl_close($ch);
        }
    } catch (Throwable $e) { }
}

function saveConfig(string $path, array $cfg): void {
    $php = "<?php\nreturn [\n";
    $php .= "    'oauth_client_id'     => " . var_export($cfg['oauth_client_id'] ?? '', true) . ",\n";
    $php .= "    'oauth_client_secret' => " . var_export($cfg['oauth_client_secret'] ?? '', true) . ",\n";
    $php .= "    'oauth_refresh_token' => " . var_export($cfg['oauth_refresh_token'] ?? '', true) . ",\n";
    $php .= "    'root_folder_id'      => " . var_export($cfg['root_folder_id'] ?? '', true) . ",\n";
    $php .= "    'image' => [\n";
    $img = $cfg['image'] ?? [];
    $php .= "        'max_size_mb'     => " . (int)($img['max_size_mb'] ?? 5) . ",\n";
    $php .= "        'max_width'       => " . (int)($img['max_width'] ?? 1200) . ",\n";
    $php .= "        'max_height'      => " . (int)($img['max_height'] ?? 1200) . ",\n";
    $php .= "        'quality'         => " . (int)($img['quality'] ?? 82) . ",\n";
    $php .= "        'format'          => " . var_export($img['format'] ?? 'webp', true) . ",\n";
    $php .= "        'thumb_size'      => " . (int)($img['thumb_size'] ?? 200) . ",\n";
    $php .= "        'max_per_product' => " . (int)($img['max_per_product'] ?? 5) . ",\n";
    $php .= "    ],\n";
    $php .= "    'public_access'    => true,\n";
    $php .= "    'token_cache_path' => sys_get_temp_dir() . '/sistemax_gdrive_token.json',\n";
    $php .= "];\n";
    file_put_contents($path, $php);
}

// ── Estado actual ──
$cfg = @include $cfgPath;
if (!is_array($cfg)) $cfg = [];
$hasOAuth     = !empty($cfg['oauth_client_id']) && !empty($cfg['oauth_client_secret']);
$hasToken     = !empty($cfg['oauth_refresh_token']);
$hasFolder    = !empty($cfg['root_folder_id']);
$isConfigured = $hasOAuth && $hasToken && $hasFolder;

$oauthSuccess = $_SESSION['oauth_success'] ?? false;
$oauthError   = $_SESSION['oauth_error'] ?? '';
$oauthMsg     = $_SESSION['oauth_msg'] ?? '';
unset($_SESSION['oauth_success'], $_SESSION['oauth_error'], $_SESSION['oauth_msg']);

// Generar auth URL si ya tenemos client_id
$authUrl = '';
if ($hasOAuth && !$hasToken) {
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $cfg['oauth_client_id'],
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/drive',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Google Drive — SistemaXPro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; }
        .step-card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; }
        .step-done { border-color: #10b981; }
        .step-active { border-color: #06b6d4; box-shadow: 0 0 20px rgba(6,182,212,0.15); }
        .btn-primary { background: linear-gradient(135deg, #06b6d4, #0284c7); }
        .btn-primary:hover { background: linear-gradient(135deg, #0891b2, #0369a1); }
        .btn-google { background: #4285f4; }
        .btn-google:hover { background: #3367d6; }
        .glow { animation: glow 2s ease-in-out infinite alternate; }
        @keyframes glow { from { box-shadow: 0 0 5px rgba(6,182,212,0.2); } to { box-shadow: 0 0 20px rgba(6,182,212,0.4); } }
        .toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 100; }
        code { font-size: 11px; }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8">
    <div class="max-w-2xl mx-auto">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center gap-3 mb-3">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center">
                    <i class="fab fa-google-drive text-white text-xl"></i>
                </div>
                <div class="text-left">
                    <h1 class="text-2xl font-bold text-white">Google Drive Setup</h1>
                    <p class="text-xs text-gray-400">SistemaXPro — Imágenes de Productos (OAuth 2.0)</p>
                </div>
            </div>
            <?php if ($isConfigured): ?>
                <span class="px-3 py-1 rounded-full bg-emerald-900/40 text-emerald-400 text-xs font-medium">
                    <i class="fas fa-check-circle"></i> Completamente configurado
                </span>
            <?php endif; ?>
        </div>

        <?php if ($oauthError): ?>
            <div class="bg-red-900/30 border border-red-700 rounded-xl p-4 mb-4">
                <div class="flex items-center gap-2 text-red-400 text-sm">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($oauthError) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($oauthSuccess): ?>
            <div class="bg-emerald-900/30 border border-emerald-700 rounded-xl p-4 mb-4">
                <div class="flex items-center gap-2 text-emerald-400 text-sm">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($oauthMsg) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Progress -->
        <div class="flex items-center justify-center gap-2 mb-8">
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold <?= $hasOAuth ? 'bg-emerald-600 text-white' : 'bg-cyan-600 text-white glow' ?>">
                <?= $hasOAuth ? '<i class="fas fa-check text-xs"></i>' : '1' ?>
            </div>
            <div class="w-12 h-0.5 <?= $hasOAuth ? 'bg-emerald-600' : 'bg-gray-700' ?>"></div>
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold <?= $hasToken ? 'bg-emerald-600 text-white' : ($hasOAuth ? 'bg-cyan-600 text-white glow' : 'bg-gray-700 text-gray-400') ?>">
                <?= $hasToken ? '<i class="fas fa-check text-xs"></i>' : '2' ?>
            </div>
            <div class="w-12 h-0.5 <?= $hasToken ? 'bg-emerald-600' : 'bg-gray-700' ?>"></div>
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold <?= $isConfigured ? 'bg-cyan-600 text-white glow' : 'bg-gray-700 text-gray-400' ?>">
                3
            </div>
        </div>

        <!-- ═══════ PASO 1: OAuth Credentials ═══════ -->
        <div class="step-card <?= $hasOAuth ? 'step-done' : 'step-active' ?> p-5 mb-4">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 rounded-lg <?= $hasOAuth ? 'bg-emerald-600' : 'bg-cyan-600' ?> flex items-center justify-center text-white">
                    <?= $hasOAuth ? '<i class="fas fa-check"></i>' : '<i class="fas fa-key"></i>' ?>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white">Paso 1: Crear OAuth Client</h2>
                    <p class="text-xs text-gray-400">Credenciales OAuth 2.0 en Google Cloud (5 min)</p>
                </div>
            </div>

            <?php if (!$hasOAuth): ?>
                <div class="space-y-4">
                    <div class="bg-slate-900/50 rounded-xl p-4 space-y-3">

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-cyan-900/50 text-cyan-400 flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">A</span>
                            <div>
                                <p class="text-sm text-gray-300">Abrí Google Cloud Console y habilitá Drive API:</p>
                                <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank"
                                   class="inline-flex items-center gap-1.5 mt-1 px-3 py-1.5 rounded-lg bg-blue-600/20 text-blue-400 hover:bg-blue-600/30 text-xs font-medium">
                                    <i class="fas fa-external-link-alt text-[10px]"></i> Habilitar Google Drive API
                                </a>
                                <p class="text-[11px] text-gray-500 mt-1">Si ya lo hiciste antes, ignorá este paso.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-cyan-900/50 text-cyan-400 flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">B</span>
                            <div>
                                <p class="text-sm text-gray-300">Configurá la pantalla de consentimiento OAuth:</p>
                                <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank"
                                   class="inline-flex items-center gap-1.5 mt-1 px-3 py-1.5 rounded-lg bg-blue-600/20 text-blue-400 hover:bg-blue-600/30 text-xs font-medium">
                                    <i class="fas fa-external-link-alt text-[10px]"></i> Pantalla de consentimiento
                                </a>
                                <div class="text-[11px] text-gray-500 mt-1 space-y-0.5">
                                    <p>• Tipo: <code class="text-cyan-400">Externo</code></p>
                                    <p>• Nombre de la app: <code class="text-cyan-400">SistemaXPro</code></p>
                                    <p>• Email de soporte: <code class="text-cyan-400">sistemaxpro5@gmail.com</code></p>
                                    <p>• Scopes: Agregá <code class="text-cyan-400">../auth/drive</code></p>
                                    <p>• Test users: Agregá <code class="text-cyan-400">sistemaxpro5@gmail.com</code></p>
                                    <p>• Guardá y continuá</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="w-6 h-6 rounded-full bg-amber-900/50 text-amber-400 flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">C</span>
                            <div>
                                <p class="text-sm text-gray-300"><strong class="text-amber-300">Creá el OAuth Client ID:</strong></p>
                                <a href="https://console.cloud.google.com/apis/credentials/oauthclient" target="_blank"
                                   class="inline-flex items-center gap-1.5 mt-1 px-3 py-1.5 rounded-lg bg-blue-600/20 text-blue-400 hover:bg-blue-600/30 text-xs font-medium">
                                    <i class="fas fa-external-link-alt text-[10px]"></i> Crear OAuth Client ID
                                </a>
                                <div class="text-[11px] text-gray-500 mt-1 space-y-0.5">
                                    <p>• Tipo: <code class="text-cyan-400">Aplicación web</code></p>
                                    <p>• Nombre: <code class="text-cyan-400">SistemaXPro Web</code></p>
                                    <p>• URIs de redireccionamiento autorizados:<br>
                                       <code class="text-cyan-400 text-[10px] select-all"><?= htmlspecialchars($redirectUri) ?></code></p>
                                    <p>• Click "Crear" → <strong class="text-white">copiá Client ID y Client Secret</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario -->
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-400 mb-1">Client ID:</label>
                            <input type="text" id="clientId" placeholder="xxxxx.apps.googleusercontent.com"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-xl text-white text-sm font-mono placeholder-gray-600 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-400 mb-1">Client Secret:</label>
                            <input type="password" id="clientSecret" placeholder="GOCSPX-..."
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-xl text-white text-sm font-mono placeholder-gray-600 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none">
                        </div>
                        <button onclick="saveOAuth()" id="btnSaveOAuth"
                                class="w-full py-3 btn-primary text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-2">
                            <i class="fas fa-save"></i> Guardar y Continuar
                        </button>
                        <p id="step1Status" class="text-xs hidden"></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-emerald-900/20 border border-emerald-800/30 rounded-xl p-4">
                    <div class="flex items-center gap-2 text-emerald-400 mb-2">
                        <i class="fas fa-check-circle"></i>
                        <span class="font-semibold text-sm">OAuth configurado</span>
                    </div>
                    <p class="text-xs text-gray-400">
                        Client ID: <code class="text-cyan-400"><?= htmlspecialchars(substr($cfg['oauth_client_id'], 0, 30)) ?>...</code>
                    </p>
                    <button onclick="document.getElementById('reconfigOAuth').classList.toggle('hidden')"
                            class="mt-2 text-[11px] text-gray-500 hover:text-gray-300 underline">Reconfigurar</button>
                    <div id="reconfigOAuth" class="hidden mt-3 space-y-3">
                        <div>
                            <input type="text" id="clientId" placeholder="Nuevo Client ID"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-white text-xs font-mono">
                        </div>
                        <div>
                            <input type="password" id="clientSecret" placeholder="Nuevo Client Secret"
                                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-white text-xs font-mono">
                        </div>
                        <button onclick="saveOAuth()" class="px-4 py-2 btn-primary text-white rounded-lg text-xs font-medium">Guardar</button>
                        <p id="step1Status" class="text-xs hidden"></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══════ PASO 2: Autorizar con Google ═══════ -->
        <div class="step-card <?= $hasToken ? 'step-done' : ($hasOAuth ? 'step-active' : '') ?> p-5 mb-4 <?= !$hasOAuth ? 'opacity-50 pointer-events-none' : '' ?>">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 rounded-lg <?= $hasToken ? 'bg-emerald-600' : 'bg-cyan-600' ?> flex items-center justify-center text-white">
                    <?= $hasToken ? '<i class="fas fa-check"></i>' : '<i class="fab fa-google"></i>' ?>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white">Paso 2: Autorizar</h2>
                    <p class="text-xs text-gray-400">Iniciá sesión con tu cuenta de Google (1 click)</p>
                </div>
            </div>

            <?php if ($hasToken): ?>
                <div class="bg-emerald-900/20 border border-emerald-800/30 rounded-xl p-4">
                    <div class="flex items-center gap-2 text-emerald-400 mb-2">
                        <i class="fas fa-check-circle"></i>
                        <span class="font-semibold text-sm">Autorizado ✓</span>
                    </div>
                    <?php if ($hasFolder): ?>
                        <p class="text-xs text-gray-400">
                            Carpeta: <code class="text-cyan-400"><?= htmlspecialchars($cfg['root_folder_id']) ?></code>
                            <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($cfg['root_folder_id']) ?>" target="_blank"
                               class="ml-2 text-blue-400 hover:text-blue-300"><i class="fas fa-external-link-alt text-[10px]"></i> Ver</a>
                        </p>
                    <?php endif; ?>
                    <p class="text-[11px] text-gray-500 mt-2">La carpeta "SistemaXPro" fue creada automáticamente en tu Drive.</p>
                </div>
            <?php elseif ($hasOAuth): ?>
                <div class="space-y-4">
                    <p class="text-sm text-gray-400">
                        Hacé click abajo para iniciar sesión con <strong class="text-white">sistemaxpro5@gmail.com</strong>.
                        Se creará automáticamente la carpeta "SistemaXPro" en tu Google Drive.
                    </p>

                    <div class="bg-slate-900/50 rounded-xl p-3 text-[11px] text-gray-500">
                        <p>⚠️ Si aparece <em>"Esta app no está verificada"</em>:</p>
                        <p class="mt-1">Click <strong class="text-white">"Configuración avanzada"</strong> → <strong class="text-white">"Ir a SistemaXPro (no seguro)"</strong></p>
                        <p class="mt-1">Esto es normal para apps en modo de prueba (tu propia app, tu propia cuenta).</p>
                    </div>

                    <?php if ($authUrl): ?>
                        <a href="<?= htmlspecialchars($authUrl) ?>"
                           class="w-full py-3 btn-google text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-3 hover:scale-[1.02] transition-transform">
                            <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#fff" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#fff" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" opacity=".7"/><path fill="#fff" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" opacity=".5"/><path fill="#fff" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" opacity=".7"/></svg>
                            Autorizar con Google
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══════ PASO 3: Test ═══════ -->
        <div class="step-card <?= $isConfigured ? 'step-active' : '' ?> p-5 mb-4 <?= !$isConfigured ? 'opacity-50 pointer-events-none' : '' ?>">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 rounded-lg bg-cyan-600 flex items-center justify-center text-white">
                    <i class="fas fa-flask"></i>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-white">Paso 3: Verificar</h2>
                    <p class="text-xs text-gray-400">Test de subida y acceso público</p>
                </div>
            </div>

            <p class="text-sm text-gray-400 mb-4">Sube una imagen de prueba, verifica acceso público, y limpia.</p>

            <button onclick="runTest()" id="btnTest"
                    class="w-full py-3 btn-primary text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-2 hover:scale-[1.02] transition-transform">
                <i class="fas fa-play"></i> Ejecutar Test Completo
            </button>
            <div id="testResult" class="mt-4 hidden"></div>
        </div>

        <!-- Resumen -->
        <?php if ($isConfigured): ?>
        <div class="step-card step-done p-5 mb-4">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center text-white text-lg">
                    <i class="fas fa-check-double"></i>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-emerald-400">¡Configurado!</h2>
                    <p class="text-xs text-gray-400">Ejecutá el Test para confirmar</p>
                </div>
            </div>
            <div class="bg-slate-900/50 rounded-xl p-4 text-xs text-gray-400 space-y-2">
                <p>📁 <code class="text-cyan-400">SistemaXPro/{empresa}/productos/{id}_N.webp</code></p>
                <p>📷 Máx: <code class="text-cyan-400"><?= $cfg['image']['max_per_product'] ?? 5 ?> fotos/producto</code></p>
                <p>📐 <code class="text-cyan-400"><?= $cfg['image']['max_width'] ?? 1200 ?>×<?= $cfg['image']['max_height'] ?? 1200 ?>px</code> · <?= strtoupper($cfg['image']['format'] ?? 'webp') ?> <?= $cfg['image']['quality'] ?? 82 ?>%</p>
                <p class="pt-2 border-t border-slate-700 text-gray-500">
                    Usá desde: <strong class="text-gray-300">Productos → Editar → Fotos del producto</strong>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast hidden">
        <div id="toastInner" class="px-5 py-3 rounded-xl shadow-2xl text-sm font-medium flex items-center gap-2"></div>
    </div>

    <script>
    function showToast(msg, type = 'success') {
        const t = document.getElementById('toast');
        const inner = document.getElementById('toastInner');
        inner.className = 'px-5 py-3 rounded-xl shadow-2xl text-sm font-medium flex items-center gap-2 ' +
            (type === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white');
        inner.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}`;
        t.classList.remove('hidden');
        clearTimeout(window._tt);
        window._tt = setTimeout(() => t.classList.add('hidden'), 6000);
    }

    async function saveOAuth() {
        const clientId = document.getElementById('clientId')?.value?.trim();
        const clientSecret = document.getElementById('clientSecret')?.value?.trim();
        if (!clientId || !clientSecret) { showToast('Completá ambos campos', 'error'); return; }

        const status = document.getElementById('step1Status');
        status.className = 'text-xs text-cyan-400';
        status.textContent = '⏳ Guardando...';
        status.classList.remove('hidden');

        const fd = new FormData();
        fd.append('wizard_action', 'save_oauth');
        fd.append('client_id', clientId);
        fd.append('client_secret', clientSecret);

        try {
            const res = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                showToast('Credenciales guardadas ✓');
                if (data.auth_url) {
                    status.className = 'text-xs text-emerald-400';
                    status.textContent = '✅ Redirigiendo a Google...';
                    setTimeout(() => window.location.href = data.auth_url, 800);
                } else {
                    setTimeout(() => location.reload(), 1000);
                }
            } else {
                status.className = 'text-xs text-red-400';
                status.textContent = '❌ ' + data.msg;
                showToast(data.msg, 'error');
            }
        } catch(e) {
            status.className = 'text-xs text-red-400';
            status.textContent = '❌ Error de conexión';
        }
    }

    async function runTest() {
        const btn = document.getElementById('btnTest');
        const result = document.getElementById('testResult');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Probando...';
        result.classList.add('hidden');

        const fd = new FormData();
        fd.append('wizard_action', 'test');

        try {
            const res = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();
            result.classList.remove('hidden');

            if (data.ok) {
                result.innerHTML = `
                    <div class="bg-emerald-900/20 border border-emerald-800/30 rounded-xl p-4 space-y-2">
                        <div class="flex items-center gap-2 text-emerald-400">
                            <i class="fas fa-check-circle text-lg"></i>
                            <span class="font-bold text-sm">${data.msg}</span>
                        </div>
                        <div class="text-xs text-gray-400 space-y-1">
                            <p>✅ Token OAuth 2.0 válido</p>
                            <p>✅ Crear subcarpetas</p>
                            <p>✅ Subida de imagen</p>
                            <p>${data.public_ok ? '✅' : '⚠️'} Acceso público: ${data.public_ok ? 'OK' : 'HTTP ' + data.http_code}</p>
                            <p>✅ Eliminación</p>
                        </div>
                    </div>`;
                showToast('¡Test exitoso! 🎉');
            } else {
                result.innerHTML = `
                    <div class="bg-red-900/20 border border-red-800/30 rounded-xl p-4">
                        <div class="flex items-center gap-2 text-red-400 mb-2">
                            <i class="fas fa-times-circle"></i>
                            <span class="font-semibold text-sm">Error</span>
                        </div>
                        <p class="text-xs text-gray-400">${data.msg}</p>
                    </div>`;
                showToast(data.msg.substring(0, 80), 'error');
            }
        } catch(e) {
            result.classList.remove('hidden');
            result.innerHTML = '<p class="text-xs text-red-400">Error de conexión</p>';
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play"></i> Ejecutar Test Completo';
    }

    // Enter en inputs
    document.querySelectorAll('#clientId, #clientSecret').forEach(el => {
        el?.addEventListener('keydown', e => { if (e.key === 'Enter') saveOAuth(); });
    });
    </script>
</body>
</html>
