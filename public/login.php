<?php

/**
 * Login Principal - SistemaX
 * Detecta dispositivo móvil y redirige automáticamente
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/bootstrap.php';

// Capturar URL de retorno (redirect)
$redirectUrl = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
// Validar que sea una URL relativa segura (evitar open redirect)
if (!empty($redirectUrl) && (strpos($redirectUrl, '/') !== 0 || strpos($redirectUrl, '//') === 0)) {
    $redirectUrl = ''; // URL no segura, ignorar
}
$defaultRedirect = '/public/menu/menu.php';
$finalRedirect = !empty($redirectUrl) ? $redirectUrl : $defaultRedirect;

// Intentar auto-login desde cookie "Recordarme"
if (Auth::loginFromRememberCookie()) {
    header('Location: ' . $finalRedirect);
    exit;
}

// Si ya está logueado Y la sesión es persistente (recordarme), redirigir al menú
// Si no marcó "recordarme", no redirigir automáticamente al entrar a login.php
if (!empty($_SESSION['id_login']) && !empty($_SESSION['session_persistent'])) {
    header('Location: ' . $finalRedirect);
    exit;
}

$errors = [];
$warnings = [];
$usuario = '';
$isBlocked = false;
$remainingMinutes = 0;
$currentLocale = SmxI18n::getLocale();
$localeOptions = SmxI18n::getLocaleOptions();

// Verificar si IP está bloqueada
$clientIP = Security::getClientIP();
if (Security::isBlocked($clientIP)) {
    $isBlocked = true;
    $remainingMinutes = Security::getRemainingLockoutTime($clientIP);
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isBlocked) {
    // Validar CSRF token
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = t('login.csrf_error');
    } else {
        $usuario = trim($_POST['usuario'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $remember = isset($_POST['remember']);

        if (empty($usuario)) {
            $errors[] = t('login.user_required');
        }
        if (empty($password)) {
            $errors[] = t('login.password_required');
        }

        if (empty($errors)) {
            $result = Auth::login($usuario, $password, $remember);

            if ($result['success']) {
                header('Location: ' . $finalRedirect);
                exit;
            } else {
                $errors[] = $result['error'] ?? t('login.invalid_credentials');

                // Mostrar advertencia de intentos restantes
                if (isset($result['remaining_attempts']) && $result['remaining_attempts'] <= 3) {
                    $warnings[] = t('login.attempts_left', ['count' => (int)$result['remaining_attempts']]);
                }

                // Verificar si ahora está bloqueado
                if (!empty($result['blocked'])) {
                    $isBlocked = true;
                    $remainingMinutes = $result['remaining_minutes'] ?? 15;
                }
            }
        }
    }
}

function h($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Generar CSRF token
$csrfToken = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="<?php echo h($currentLocale); ?>" class="dark<?php echo (isset($_COOKIE['wallpaper_disabled']) && $_COOKIE['wallpaper_disabled'] === 'true') ? ' wallpaper-disabled' : ''; ?>">

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#3b82f6">
    <link rel="manifest" href="/public/manifest.json">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title><?php echo h(t('login.title')); ?></title>

    <!-- Estilos críticos ANTES de Tailwind -->
    <style id="dark-inputs-critical">
        html.dark input[type="text"],
        html.dark input[type="password"],
        .dark input[type="text"],
        .dark input[type="password"],
        :root.dark input[type="text"],
        :root.dark input[type="password"] {
            background-color: #1e293b !important;
            border-color: #475569 !important;
            color: #f1f5f9 !important;
        }

        /* Wallpaper desactivado - fondo oscuro limpio */
        html.wallpaper-disabled,
        html.wallpaper-disabled body {
            background: #0b1220 !important;
        }
        html.wallpaper-disabled #image-background,
        html.wallpaper-disabled #video-background,
        html.wallpaper-disabled #video-overlay {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }

        /* Background base image */
        #image-background {
            position: fixed;
            inset: 0;
            z-index: -3;
            background-image:
                linear-gradient(135deg, rgba(15, 23, 42, 0.18), rgba(15, 23, 42, 0.42)),
                url('/public/assets/img/landing/menu-modulos.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            transform: scale(1.04);
            filter: saturate(0.9) contrast(1.02) blur(1px);
            opacity: 0.92;
        }

        .dark #image-background {
            background-image:
                linear-gradient(135deg, rgba(2, 6, 23, 0.36), rgba(2, 6, 23, 0.62)),
                url('/public/assets/img/landing/menu-modulos.png');
            filter: saturate(0.82) contrast(1.04) brightness(0.76) blur(1px);
            opacity: 0.9;
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
            transition: opacity 1.5s ease-in-out;
        }
        
        #video-background.loaded {
            opacity: 1;
        }
        
        /* Overlay sobre el video - más sutil para ver el video */
        #video-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: -1;
            background: linear-gradient(135deg, 
                rgba(15, 23, 42, 0.38) 0%, 
                rgba(55, 65, 81, 0.24) 50%,
                rgba(15, 23, 42, 0.38) 100%);
            pointer-events: none;
        }
        
        .dark #video-overlay {
            background: linear-gradient(135deg, 
                rgba(2, 6, 23, 0.52) 0%, 
                rgba(31, 41, 55, 0.34) 50%,
                rgba(2, 6, 23, 0.52) 100%);
        }
        
        /* Fallback cuando no hay video */
        body {
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
        }

        /* ============================================
           LIQUID GLASS EFFECT - iOS/Apple Style
           ============================================ */
        
        /* Base Liquid Glass */
        .liquid-glass {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.26), rgba(255, 255, 255, 0.09));
            backdrop-filter: blur(56px) saturate(195%) brightness(1.08);
            -webkit-backdrop-filter: blur(56px) saturate(195%) brightness(1.08);
            border: 1px solid rgba(255, 255, 255, 0.34);
            box-shadow: 
                0 12px 45px -10px rgba(9, 25, 61, 0.24),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.4),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.16),
                0 0 0 1px rgba(255, 255, 255, 0.09);
        }

        .dark .liquid-glass {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.52), rgba(15, 23, 42, 0.3));
            backdrop-filter: blur(56px) saturate(220%) brightness(0.92);
            -webkit-backdrop-filter: blur(56px) saturate(220%) brightness(0.92);
            border: 1px solid rgba(226, 232, 240, 0.15);
            box-shadow: 
                0 16px 55px -12px rgba(0, 0, 0, 0.5),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.04),
                0 0 0 1px rgba(255, 255, 255, 0.03);
        }

        /* Main Card - Premium Frosted Glass */
        .liquid-glass-card {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0.46));
            backdrop-filter: blur(64px) saturate(210%) brightness(1.04);
            -webkit-backdrop-filter: blur(64px) saturate(210%) brightness(1.04);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 
                0 30px 70px -22px rgba(15, 23, 42, 0.35),
                0 8px 18px -8px rgba(15, 23, 42, 0.18),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.62),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.24);
            position: relative;
            overflow: hidden;
        }

        .dark .liquid-glass-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.62), rgba(15, 23, 42, 0.48));
            backdrop-filter: blur(64px) saturate(230%) brightness(0.9);
            -webkit-backdrop-filter: blur(64px) saturate(230%) brightness(0.9);
            border: 1px solid rgba(148, 163, 184, 0.22);
            color: #e2e8f0;
            box-shadow: 
                0 35px 80px -26px rgba(0, 0, 0, 0.7),
                0 10px 22px -10px rgba(0, 0, 0, 0.45),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.13),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.04),
                0 0 90px -26px rgba(148, 163, 184, 0.18);
        }

        /* Refraction highlight on card */
        .liquid-glass-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 56%;
            background: linear-gradient(
                180deg,
                rgba(255, 255, 255, 0.32) 0%,
                rgba(255, 255, 255, 0.11) 40%,
                transparent 100%
            );
            pointer-events: none;
            border-radius: inherit;
        }

        .dark .liquid-glass-card::before {
            background: linear-gradient(
                180deg,
                rgba(255, 255, 255, 0.14) 0%,
                rgba(255, 255, 255, 0.04) 40%,
                transparent 100%
            );
        }

        .liquid-glass-card::selection {
            background: rgba(148, 163, 184, 0.3);
        }

        /* Input Fields - Subtle Glass */
        .liquid-glass-input {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.78), rgba(255, 255, 255, 0.54)) !important;
            backdrop-filter: blur(30px) saturate(175%);
            -webkit-backdrop-filter: blur(30px) saturate(175%);
            border: 1px solid rgba(255, 255, 255, 0.48) !important;
            box-shadow: 
                inset 0 1px 0 rgba(255, 255, 255, 0.62),
                inset 0 -1px 0 rgba(255, 255, 255, 0.2),
                0 6px 18px -12px rgba(15, 23, 42, 0.22);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dark .liquid-glass-input {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.58)) !important;
            backdrop-filter: blur(30px) saturate(195%);
            -webkit-backdrop-filter: blur(30px) saturate(195%);
            border: 1px solid rgba(148, 163, 184, 0.24) !important;
            box-shadow: 
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 rgba(255, 255, 255, 0.04),
                0 6px 18px -12px rgba(0, 0, 0, 0.45);
        }

        .liquid-glass-input:focus {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.7)) !important;
            border: 1px solid rgba(100, 116, 139, 0.65) !important;
            box-shadow: 
                0 0 0 4px rgba(148, 163, 184, 0.2),
                0 12px 28px -16px rgba(71, 85, 105, 0.36),
                inset 0 1px 0 rgba(255, 255, 255, 0.72);
            transform: translateY(-1px);
        }

        .dark .liquid-glass-input:focus {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.68)) !important;
            border: 1px solid rgba(148, 163, 184, 0.62) !important;
            box-shadow: 
                0 0 0 4px rgba(148, 163, 184, 0.22),
                0 12px 28px -16px rgba(71, 85, 105, 0.42),
                inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        /* Button - Vibrant Glass */
        .liquid-glass-button {
            background: linear-gradient(135deg,
                rgba(37, 99, 235, 0.62) 0%,
                rgba(29, 78, 216, 0.54) 100%);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(191, 219, 254, 0.55);
            box-shadow: 
                0 10px 26px -6px rgba(37, 99, 235, 0.42),
                0 4px 10px -2px rgba(30, 64, 175, 0.28),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.25),
                inset 0 -1px 0 0 rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .liquid-glass-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(
                180deg,
                rgba(255, 255, 255, 0.2) 0%,
                transparent 100%
            );
            pointer-events: none;
        }

        .liquid-glass-button:hover {
            background: linear-gradient(135deg,
                rgba(37, 99, 235, 0.78) 0%,
                rgba(29, 78, 216, 0.72) 100%);
            box-shadow: 
                0 14px 34px -6px rgba(37, 99, 235, 0.5),
                0 6px 12px -2px rgba(30, 64, 175, 0.34),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.3),
                0 0 40px -8px rgba(59, 130, 246, 0.32);
            transform: translateY(-3px);
        }

        .liquid-glass-button:active {
            transform: translateY(-1px);
            box-shadow: 
                0 8px 18px -6px rgba(30, 64, 175, 0.34),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.2);
        }

        /* Alert - Tinted Glass */
        .liquid-glass-alert {
            background: rgba(254, 226, 226, 0.75);
            backdrop-filter: blur(20px) saturate(150%);
            -webkit-backdrop-filter: blur(20px) saturate(150%);
            border: 1px solid rgba(239, 68, 68, 0.25);
            box-shadow: 
                0 4px 16px rgba(239, 68, 68, 0.1),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.3);
        }

        .dark .liquid-glass-alert {
            background: rgba(127, 29, 29, 0.4);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(239, 68, 68, 0.3);
            box-shadow: 
                0 4px 16px rgba(239, 68, 68, 0.15),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.05);
        }

        /* Logo Container - Floating Glass Orb */
        .liquid-glass-logo {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(30px) saturate(200%) brightness(1.1);
            -webkit-backdrop-filter: blur(30px) saturate(200%) brightness(1.1);
            border: 2px solid rgba(255, 255, 255, 0.35);
            box-shadow: 
                0 15px 35px -5px rgba(71, 85, 105, 0.25),
                0 5px 15px -3px rgba(0, 0, 0, 0.1),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.4),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.1),
                0 0 60px -10px rgba(148, 163, 184, 0.28);
            position: relative;
            overflow: hidden;
        }

        .liquid-glass-logo::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(
                circle at 30% 30%,
                rgba(255, 255, 255, 0.3) 0%,
                transparent 50%
            );
            pointer-events: none;
        }

        .dark .liquid-glass-logo {
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(30px) saturate(220%) brightness(0.95);
            -webkit-backdrop-filter: blur(30px) saturate(220%) brightness(0.95);
            border: 2px solid rgba(148, 163, 184, 0.2);
            box-shadow: 
                0 15px 35px -5px rgba(71, 85, 105, 0.34),
                0 5px 15px -3px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.03),
                0 0 80px -10px rgba(148, 163, 184, 0.3);
        }

        /* Animated shimmer effect */
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .liquid-glass-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(
                90deg,
                transparent 0%,
                rgba(255, 255, 255, 0.11) 50%,
                transparent 100%
            );
            background-size: 200% 100%;
            animation: shimmer 12s infinite linear;
            pointer-events: none;
            border-radius: inherit;
        }

        .dark .liquid-glass-card::after {
            background: linear-gradient(
                90deg,
                transparent 0%,
                rgba(255, 255, 255, 0.05) 50%,
                transparent 100%
            );
            background-size: 200% 100%;
        }

        .locale-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 2.75rem;
            padding: 0.6rem 0.95rem;
            border-radius: 9999px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.62), rgba(255, 255, 255, 0.28));
            border: 1px solid rgba(255, 255, 255, 0.38);
            box-shadow: 0 10px 24px -16px rgba(15, 23, 42, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.42);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
        }

        .dark .locale-pill {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.68), rgba(15, 23, 42, 0.4));
            border-color: rgba(148, 163, 184, 0.18);
            box-shadow: 0 12px 28px -18px rgba(0, 0, 0, 0.65), inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }

        .locale-select {
            appearance: none;
            background: transparent;
            border: 0;
            color: inherit;
            font-weight: 600;
            padding-right: 1.5rem;
            outline: none;
        }

        .locale-chevron {
            pointer-events: none;
            opacity: 0.7;
        }

        /* Wallpaper Menu Styles */
        #wallpaper-btn {
            transition: all 0.2s ease;
        }

        #wallpaper-btn:hover {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.75), rgba(255, 255, 255, 0.35));
            border-color: rgba(255, 255, 255, 0.48);
        }

        .dark #wallpaper-btn:hover {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.78), rgba(15, 23, 42, 0.5));
            border-color: rgba(148, 163, 184, 0.28);
        }

        #wallpaper-menu {
            animation: dropdownSlideIn 0.2s ease-out;
        }

        #wallpaper-menu.hidden {
            animation: dropdownSlideOut 0.2s ease-out forwards;
        }

        @keyframes dropdownSlideIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes dropdownSlideOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-8px);
            }
        }

        #wallpaper-menu button:last-child {
            border-bottom: none;
        }

        .wallpaper-status {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 16px;
            background: rgba(34, 197, 94, 0.9);
            color: white;
            border-radius: 8px;
            font-size: 0.875rem;
            z-index: 1000;
            animation: slideInDown 0.3s ease-out;
            backdrop-filter: blur(20px);
        }

        .wallpaper-status.error {
            background: rgba(239, 68, 68, 0.9);
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>

    <link rel="stylesheet" href="assets/tailwind.css?v=<?php echo @filemtime(__DIR__ . '/assets/tailwind.css') ?: time(); ?>">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        window.__SISTEMAX_LOCALE__ = <?php echo json_encode(SmxI18n::config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="/public/assets/js/i18n.js?v=1"></script>
    <script src="/public/assets/js/sistemax-offline-db.js?v=1"></script>

    <script>
        // Detectar tema del navegador y aplicarlo antes de renderizar
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>

<body class="bg-gradient-to-br from-blue-500 via-blue-500 to-cyan-500 
             dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-slate-950
             min-h-screen flex items-center justify-center px-4 py-0
             md:px-4 md:py-0 
             supports-[height:100dvh]:min-h-[100dvh]
             transition-colors duration-300">
    <div id="image-background" aria-hidden="true"></div>

    <!-- Video Background -->
    <video id="video-background" muted loop playsinline>
        <source src="" type="video/mp4">
    </video>
    <div id="video-overlay"></div>
    
    <div class="w-full max-w-md 
                md:max-w-md
                mobile-safe-area">
        <!-- Login Card -->
        <div class="liquid-glass-card relative overflow-hidden
                    rounded-2xl md:rounded-2xl 
                    p-6 md:p-8 animate-slide-up
                    transition-all duration-500 hover:shadow-2xl">
            <div class="text-center mb-4 md:mb-6">
                <div class="flex justify-center md:justify-end gap-2 mb-4">
                    <!-- Selector de Idioma -->
                    <label class="locale-pill text-sm text-slate-700 dark:text-slate-200">
                        <i class="fas fa-globe-americas text-cyan-600 dark:text-cyan-300"></i>
                        <span><?php echo h(t('login.language')); ?></span>
                        <div class="relative">
                            <select id="locale-switcher" class="locale-select">
                                <?php foreach ($localeOptions as $option): ?>
                                    <option value="<?php echo h($option['code']); ?>" <?php echo $currentLocale === $option['code'] ? 'selected' : ''; ?>>
                                        <?php echo h($option['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down locale-chevron absolute right-0 top-1/2 -translate-y-1/2 text-xs"></i>
                        </div>
                    </label>

                    <!-- Wallpaper Menu -->
                    <div class="relative">
                        <button id="wallpaper-btn" type="button" class="locale-pill text-sm text-slate-700 dark:text-slate-200 flex items-center">
                            <i class="fas fa-images text-blue-600 dark:text-blue-300"></i>
                            <i class="fas fa-chevron-down text-xs ml-1"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div id="wallpaper-menu" class="hidden absolute right-0 mt-2 w-48 rounded-lg
                                    liquid-glass shadow-xl z-50 overflow-hidden">
                            <button type="button" id="pin-video-btn" class="w-full text-left px-4 py-3 text-sm
                                       text-slate-700 dark:text-slate-200 hover:bg-blue-500/20
                                       border-b border-slate-200/20 dark:border-slate-600/30
                                       transition-colors flex items-center gap-2">
                                <i class="fas fa-thumbtack text-sm"></i>
                                <span>Fijar video actual</span>
                            </button>
                            <button type="button" id="change-video-btn" class="w-full text-left px-4 py-3 text-sm
                                       text-slate-700 dark:text-slate-200 hover:bg-blue-500/20
                                       border-b border-slate-200/20 dark:border-slate-600/30
                                       transition-colors flex items-center gap-2">
                                <i class="fas fa-sync-alt text-sm"></i>
                                <span>Cambiar video</span>
                            </button>
                            <button type="button" id="disable-video-btn" class="w-full text-left px-4 py-3 text-sm
                                       text-slate-700 dark:text-slate-200 hover:bg-red-500/20
                                       transition-colors flex items-center gap-2">
                                <i class="fas fa-ban text-sm"></i>
                                <span id="disable-btn-text">Desactivar video</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="flex items-start justify-center select-none" style="line-height:1;">
                    <span style="font-size:2.6rem;font-weight:800;background:linear-gradient(135deg,#60a5fa 0%,#2563eb 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;letter-spacing:-0.03em;">Sistemax</span><span style="font-size:0.6rem;font-weight:700;background:linear-gradient(135deg,#818cf8,#4f46e5);color:#fff;padding:2px 7px;border-radius:99px;margin-left:2px;margin-top:4px;letter-spacing:0.06em;line-height:1.5;box-shadow:0 2px 8px rgba(99,102,241,0.4);">PRO</span>
                </div>
                <h1 class="mt-5 text-2xl md:text-3xl font-semibold text-slate-900 dark:text-white"><?php echo h(t('login.welcome')); ?></h1>
                <p class="mt-2 text-sm md:text-base text-slate-600 dark:text-slate-300"><?php echo h(t('login.subtitle')); ?></p>
            </div>

            <?php if ($isBlocked): ?>
                <div class="liquid-glass-alert
                            border-l-4 border-red-500 
                            p-4 mb-6 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-ban text-red-500 dark:text-red-400 mr-3 mt-0.5"></i>
                        <div class="text-red-700 dark:text-red-300">
                            <p class="font-semibold"><?php echo h(t('login.blocked_title')); ?></p>
                            <p class="text-sm mt-1"><?php echo h(t('login.blocked_body', ['minutes' => $remainingMinutes])); ?></p>
                        </div>
                    </div>
                </div>
            <?php elseif (!empty($errors)): ?>
                <div class="liquid-glass-alert
                            border-l-4 border-red-500 
                            p-4 mb-6 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle text-red-500 dark:text-red-400 mr-3"></i>
                        <div class="text-red-700 dark:text-red-300">
                            <?php foreach ($errors as $error): ?>
                                <p><?php echo h($error); ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($warnings)): ?>
                <div class="bg-amber-50/70 dark:bg-amber-950/30 backdrop-blur-md
                            border-l-4 border-amber-500 dark:border-amber-500/70 
                            p-4 mb-6 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-amber-500 dark:text-amber-400 mr-3"></i>
                        <div class="text-amber-700 dark:text-amber-300">
                            <?php foreach ($warnings as $warning): ?>
                                <p><?php echo h($warning); ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                <input type="hidden" name="redirect" value="<?php echo h($redirectUrl); ?>">
                <input type="hidden" name="locale" id="locale-input" value="<?php echo h($currentLocale); ?>">

                <!-- Usuario -->
                <div class="mb-3 md:mb-4">
                    <label class="block text-gray-700 dark:text-slate-300 text-sm font-semibold mb-2" for="usuario">
                        <i class="fas fa-user mr-2 text-blue-600 dark:text-blue-400"></i><?php echo h(t('login.user')); ?>
                    </label>
                    <input
                        type="text"
                        id="usuario"
                        name="usuario"
                        class="w-full px-4 py-3 md:py-3 text-base 
                               border border-gray-300 dark:border-slate-600 
                               bg-white dark:bg-slate-800 
                               text-gray-900 dark:text-slate-100 
                               placeholder:text-gray-400 dark:placeholder:text-slate-500
                               rounded-lg md:rounded-lg 
                               focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 
                               focus:border-transparent 
                               transition-all duration-200
                               appearance-none touch-manipulation"
                        placeholder="<?php echo h(t('login.user_placeholder')); ?>"
                        value="<?php echo h($usuario); ?>"
                        autofocus
                        required />
                </div>

                <!-- Contraseña -->
                <div class="mb-4 md:mb-6">
                    <label class="block text-gray-700 dark:text-slate-300 text-sm font-semibold mb-2" for="password">
                        <i class="fas fa-lock mr-2 text-blue-600 dark:text-blue-400"></i><?php echo h(t('login.password')); ?>
                    </label>
                    <div class="relative">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="liquid-glass-input w-full px-4 py-3 md:py-3 pr-12 text-base 
                                   text-gray-900 dark:text-slate-100 
                                   placeholder:text-gray-400 dark:placeholder:text-slate-400
                                   rounded-lg md:rounded-lg 
                                   focus:outline-none
                                   transition-all duration-300
                                   appearance-none touch-manipulation"
                            placeholder="<?php echo h(t('login.password_placeholder')); ?>"
                            required />
                        <button
                            type="button"
                            onclick="togglePassword()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300 focus:outline-none"
                            tabindex="-1">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Recordarme y Olvidé mi contraseña -->
                <div class="flex items-center justify-between mb-4 md:mb-6">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="remember"
                            class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded 
                                      focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 
                                      focus:ring-2 dark:bg-gray-700 dark:border-gray-600
                                      cursor-pointer">
                        <span class="ml-2 text-sm text-gray-600 dark:text-slate-400"><?php echo h(t('login.remember')); ?></span>
                    </label>
                    <a href="forgot-password.php"
                        class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                        <?php echo h(t('login.forgot')); ?>
                    </a>
                </div>

                <!-- Botón -->
                <button
                    type="submit"
                    <?php if ($isBlocked): ?>disabled<?php endif; ?>
                    class="liquid-glass-button w-full 
                           text-white font-semibold py-3 md:py-3 text-base
                           rounded-lg md:rounded-lg
                           focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-2
                           active:scale-95
                           disabled:opacity-50 disabled:cursor-not-allowed
                           touch-manipulation">
                    <i class="fas fa-sign-in-alt mr-2"></i><?php echo h(t('login.submit')); ?>
                </button>
            </form>

            <!-- Footer -->
            <div class="mt-4 md:mt-6 text-center text-xs md:text-sm text-gray-500 dark:text-slate-400">
                <p>&copy; <?php echo date('Y'); ?> sistemax.pro</p>
            </div>
        </div>
    </div>

    <style>
        /* Forzar dark mode en inputs */
        html.dark input[type="text"],
        html.dark input[type="password"] {
            background-color: #1e293b !important;
            border-color: #475569 !important;
            color: #f1f5f9 !important;
        }

        html.dark input[type="text"]::placeholder,
        html.dark input[type="password"]::placeholder {
            color: #64748b !important;
        }

        html.dark input[type="text"]:focus,
        html.dark input[type="password"]:focus {
            border-color: #60a5fa !important;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2) !important;
        }

        /* Animaciones suaves para mobile */
        @keyframes slide-up {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-slide-up {
            animation: slide-up 0.6s ease-out;
        }

        /* Safe area para iPhone con notch */
        .mobile-safe-area {
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }

        /* Mejorar experiencia táctil */
        input,
        button {
            -webkit-tap-highlight-color: transparent;
        }

        /* Prevenir zoom en inputs (iOS) */
        @media screen and (max-width: 768px) {

            #image-background {
                transform: none;
                filter: saturate(0.9) contrast(1.01);
                opacity: 1;
            }

            #video-background,
            #video-overlay {
                display: none !important;
            }

            input[type="text"],
            input[type="password"] {
                font-size: 16px !important;
            }
        }

        /* Estilo nativo para iOS */
        @supports (-webkit-touch-callout: none) {
            body {
                -webkit-user-select: none;
                user-select: none;
            }

            input,
            textarea {
                -webkit-user-select: text;
                user-select: text;
            }
        }
    </style>

    <script>
        window.__SISTEMAX_OFFLINE_CONFIG__ = {
            precacheUrls: [
                '/public/login.php',
                '/public/menu/menu.php',
                '/public/panel/index.php',
                '/public/pos/index.php',
                '/public/pos/mobile.php',
                '/public/productos/index.php',
                '/public/productos/mobile.php',
                '/public/inventario/index.php',
                '/public/inventario/mobile.php',
                '/public/compras/index.php',
                '/public/compras/mobile.php',
                '/public/gastos/index.php',
                '/public/gastos/mobile.php',
                '/public/contactos/index.php',
                '/public/contactos/mobile.php',
                '/public/cajas/index.php',
                '/public/cajas/mobile.php',
                '/public/ventas/index.php',
                '/public/usuarios/index.php',
                '/public/usuarios/mobile.php',
                '/public/taller/index.php',
                '/public/taller/mobile.php',
                '/public/misventas/index.php',
                '/public/sucursales/index.php',
                '/public/soporte/index.php',
                '/public/suscripciones.php'
            ]
        };
    </script>
    <script src="/public/assets/js/sistemax-offline-shell.js?v=1" defer></script>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // ============================================
        // Sistema de Video Wallpaper - Pixabay
        // Un video aleatorio por cada sesión
        // ============================================
        
        const PIXABAY_API_KEY = '54541717-3563ce276d45c226152a49d0b';
        const PIXABAY_API = 'https://pixabay.com/api/videos/';
        const DISABLE_VIDEO_ON_MOBILE = window.matchMedia('(max-width: 768px)').matches;
        const LOGIN_FALLBACK_IMAGE = '/public/assets/img/landing/menu-modulos.png';
        let DISABLE_VIDEO_BY_EMPRESA = false;

        let DISABLE_VIDEO_BY_CONTEXT =
            DISABLE_VIDEO_ON_MOBILE ||
            (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) ||
            !!(navigator.connection && navigator.connection.saveData) ||
            DISABLE_VIDEO_BY_EMPRESA;
        
        // Lista de búsquedas para videos de fondo
        const VIDEO_QUERIES = [
            'nature+landscape',
            'abstract+technology', 
            'ocean+waves',
            'night+sky+stars',
            'forest+trees',
            'city+lights+night',
            'clouds+sky+timelapse',
            'water+abstract',
            'aurora+borealis',
            'sunset+mountains'
        ];
        
        // Obtener query aleatorio
        function getRandomQuery() {
            return VIDEO_QUERIES[Math.floor(Math.random() * VIDEO_QUERIES.length)];
        }

        function applyImageFallback(url = LOGIN_FALLBACK_IMAGE) {
            const imageLayer = document.getElementById('image-background');
            if (!imageLayer) return;
            imageLayer.style.backgroundImage = `
                linear-gradient(135deg, rgba(15, 23, 42, 0.18), rgba(15, 23, 42, 0.42)),
                url('${url}')
            `;
            if (document.documentElement.classList.contains('dark')) {
                imageLayer.style.backgroundImage = `
                    linear-gradient(135deg, rgba(2, 6, 23, 0.36), rgba(2, 6, 23, 0.62)),
                    url('${url}')
                `;
            }
        }

        function shouldUseVideoBackground() {
            const isUserDisabled = localStorage.getItem('wallpaper_disabled') === 'true';
            return !DISABLE_VIDEO_BY_CONTEXT && !isUserDisabled;
        }
        
        async function obtenerVideoSesion() {
            if (!shouldUseVideoBackground()) {
                return;
            }
            try {
                const cacheKey = 'pixabay_video_session';
                const cache = JSON.parse(sessionStorage.getItem(cacheKey) || '{}');
                
                // Verificar si tenemos video cacheado en esta sesión
                if (cache.videoUrl) {
                    console.log('[Video Wallpaper] Usando video de la sesión actual');
                    aplicarVideo(cache.videoUrl);
                    return;
                }
                
                // Obtener nuevo video de Pixabay
                const query = getRandomQuery();
                const url = `${PIXABAY_API}?key=${PIXABAY_API_KEY}&q=${query}&video_type=film&per_page=50&safesearch=true&min_width=1280`;
                
                console.log('[Video Wallpaper] Buscando video:', query);
                
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error('Error en API de Pixabay');
                }
                
                const data = await response.json();
                
                if (!data.hits || data.hits.length === 0) {
                    console.warn('[Video Wallpaper] No se encontraron videos');
                    return;
                }
                
                // Seleccionar video aleatorio
                const videoIndex = Math.floor(Math.random() * data.hits.length);
                const video = data.hits[videoIndex];
                
                // Preferir calidad medium (buen balance tamaño/calidad)
                const videoUrl = video.videos.medium?.url || 
                                 video.videos.small?.url || 
                                 video.videos.tiny?.url;
                
                if (!videoUrl) {
                    throw new Error('No se encontró URL de video');
                }
                
                // Guardar en sessionStorage (se borra al cerrar navegador)
                sessionStorage.setItem(cacheKey, JSON.stringify({
                    videoUrl: videoUrl,
                    query: query,
                    videoId: video.id
                }));
                
                console.log('[Video Wallpaper] Nuevo video cargado:', video.id);
                aplicarVideo(videoUrl);
                
            } catch (error) {
                console.error('[Video Wallpaper] Error:', error);
                applyImageFallback();
            }
        }
        
        function aplicarVideo(url) {
            if (!shouldUseVideoBackground()) {
                return;
            }
            const video = document.getElementById('video-background');
            if (!video) return;
            
            const source = video.querySelector('source');
            source.src = url;
            
            video.load();
            const safetyTimer = setTimeout(() => {
                video.classList.remove('loaded');
            }, 4500);
            
            video.oncanplaythrough = function() {
                clearTimeout(safetyTimer);
                video.classList.add('loaded');
                video.play().catch(e => {
                    console.warn('[Video Wallpaper] Autoplay bloqueado:', e);
                    video.classList.remove('loaded');
                });
            };
            
            video.onerror = function() {
                clearTimeout(safetyTimer);
                console.warn('[Video Wallpaper] Error cargando video');
                video.classList.remove('loaded');
                applyImageFallback();
            };
        }
        
        // Verificar configuración de video por empresa
        async function verificarConfiguracionEmpresa() {
            const empresaId = new URLSearchParams(window.location.search).get('empresa_id');
            if (!empresaId) return;

            try {
                const response = await fetch(`/public/empresa/editar_empresa.php?action=get_video_config&id=${encodeURIComponent(empresaId)}`);
                const data = await response.json();
                if (data.success && data.disable_video) {
                    DISABLE_VIDEO_BY_EMPRESA = true;
                }
            } catch (e) {
                console.warn('[Video Config] Error verificando configuración de empresa');
            }
        }

        // Aplicar video cacheado inmediatamente (evita flash)
        (function() {
            applyImageFallback();
            verificarConfiguracionEmpresa().then(() => {
                if (!shouldUseVideoBackground()) {
                    return;
                }
                const cache = JSON.parse(sessionStorage.getItem('pixabay_video_session') || '{}');
                if (cache.videoUrl) {
                    aplicarVideo(cache.videoUrl);
                }
            });
        })();

        (function() {
            const localeSwitcher = document.getElementById('locale-switcher');
            const localeInput = document.getElementById('locale-input');
            if (!localeSwitcher || !localeInput || !window.SmxI18n) return;

            localeSwitcher.addEventListener('change', async function() {
                const selected = this.value || 'es';
                localeInput.value = selected;
                await window.SmxI18n.setLocale(selected);
                window.location.reload();
            });
        })();

        // Ejecutar al cargar la página
        document.addEventListener('DOMContentLoaded', async function() {
            applyImageFallback();
            await verificarConfiguracionEmpresa();
            if (shouldUseVideoBackground()) {
                obtenerVideoSesion();
            }
            initializeWallpaperMenu();
        });

        // ============================================
        // Sistema de Control de Wallpaper
        // ============================================
        function initializeWallpaperMenu() {
            const wallpaperBtn = document.getElementById('wallpaper-btn');
            const wallpaperMenu = document.getElementById('wallpaper-menu');
            const pinVideoBtn = document.getElementById('pin-video-btn');
            const changeVideoBtn = document.getElementById('change-video-btn');
            const disableVideoBtn = document.getElementById('disable-video-btn');
            const disableBtnText = document.getElementById('disable-btn-text');

            if (!wallpaperBtn || !wallpaperMenu) return;

            // Actualizar estado del botón de desactivar
            updateDisableButtonState();

            // Alternar menú
            wallpaperBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                wallpaperMenu.classList.toggle('hidden');
            });

            // Cerrar menú al hacer clic fuera
            document.addEventListener('click', function(e) {
                if (!wallpaperBtn.contains(e.target) && !wallpaperMenu.contains(e.target)) {
                    wallpaperMenu.classList.add('hidden');
                }
            });

            // Fijar video actual
            pinVideoBtn.addEventListener('click', function() {
                const cache = JSON.parse(sessionStorage.getItem('pixabay_video_session') || '{}');
                if (!cache.videoUrl) {
                    showWallpaperStatus('No hay video cargado', true);
                    return;
                }
                localStorage.setItem('wallpaper_pinned_video', JSON.stringify({
                    videoUrl: cache.videoUrl,
                    query: cache.query,
                    videoId: cache.videoId,
                    pinnedAt: new Date().toISOString()
                }));
                showWallpaperStatus('✓ Video fijado correctamente');
                wallpaperMenu.classList.add('hidden');
            });

            // Cambiar video
            changeVideoBtn.addEventListener('click', async function() {
                // Limpiar video fijado si existe
                localStorage.removeItem('wallpaper_pinned_video');

                // Limpiar caché de sesión para forzar nuevo video
                sessionStorage.removeItem('pixabay_video_session');

                // Mostrar estado
                changeVideoBtn.disabled = true;
                const originalHTML = changeVideoBtn.innerHTML;
                changeVideoBtn.innerHTML = '<i class="fas fa-spinner fa-spin text-sm"></i><span>Cargando...</span>';

                try {
                    // Obtener nuevo video
                    await obtenerVideoSesion();
                    showWallpaperStatus('✓ Nuevo video cargado');
                } catch (e) {
                    showWallpaperStatus('Error cargando nuevo video', true);
                } finally {
                    changeVideoBtn.disabled = false;
                    changeVideoBtn.innerHTML = originalHTML;
                }

                wallpaperMenu.classList.add('hidden');
            });

            // Desactivar/Reactivar video
            disableVideoBtn.addEventListener('click', function() {
                const isDisabled = localStorage.getItem('wallpaper_disabled') === 'true';

                if (isDisabled) {
                    // Reactivar
                    localStorage.removeItem('wallpaper_disabled');
                    sessionStorage.removeItem('pixabay_video_session');
                    document.cookie = 'wallpaper_disabled=; Path=/; Max-Age=0; SameSite=Lax';
                    document.documentElement.classList.remove('wallpaper-disabled');
                    DISABLE_VIDEO_BY_CONTEXT = false;

                    // Restaurar backgrounds
                    const video = document.getElementById('video-background');
                    const imageBg = document.getElementById('image-background');
                    if (video) {
                        video.style.display = 'block';
                        video.classList.remove('loaded');
                        video.style.opacity = '0';
                    }
                    if (imageBg) {
                        imageBg.style.display = 'block';
                        imageBg.style.opacity = '0.92';
                        imageBg.style.backgroundImage = `
                            linear-gradient(135deg, rgba(15, 23, 42, 0.18), rgba(15, 23, 42, 0.42)),
                            url('/public/assets/img/landing/menu-modulos.png')
                        `;
                    }
                    showWallpaperStatus('✓ Video reactivado');
                    obtenerVideoSesion();
                } else {
                    // Desactivar - fondo oscuro limpio
                    localStorage.setItem('wallpaper_disabled', 'true');
                    document.cookie = 'wallpaper_disabled=true; Path=/; Max-Age=31536000; SameSite=Lax';
                    document.documentElement.classList.add('wallpaper-disabled');
                    DISABLE_VIDEO_BY_CONTEXT = true;

                    const video = document.getElementById('video-background');
                    const imageBg = document.getElementById('image-background');

                    if (video) {
                        video.classList.remove('loaded');
                        video.style.opacity = '0';
                        video.style.display = 'none';
                    }

                    if (imageBg) {
                        imageBg.style.opacity = '0';
                        imageBg.style.backgroundImage = 'none';
                        imageBg.style.display = 'none';
                    }

                    showWallpaperStatus('✓ Video desactivado');
                }

                updateDisableButtonState();
                wallpaperMenu.classList.add('hidden');
            });
        }

        function updateDisableButtonState() {
            const disableBtnText = document.getElementById('disable-btn-text');
            const disableVideoBtn = document.getElementById('disable-video-btn');
            const isDisabled = localStorage.getItem('wallpaper_disabled') === 'true';

            if (isDisabled) {
                disableBtnText.textContent = 'Activar video';
                disableVideoBtn.classList.remove('hover:bg-red-500/20');
                disableVideoBtn.classList.add('hover:bg-green-500/20');
            } else {
                disableBtnText.textContent = 'Desactivar video';
                disableVideoBtn.classList.add('hover:bg-red-500/20');
                disableVideoBtn.classList.remove('hover:bg-green-500/20');
            }
        }

        function showWallpaperStatus(message, isError = false) {
            const status = document.createElement('div');
            status.className = `wallpaper-status ${isError ? 'error' : ''}`;
            status.textContent = message;
            document.body.appendChild(status);

            setTimeout(() => {
                status.style.animation = 'slideInDown 0.3s ease-out reverse';
                setTimeout(() => status.remove(), 300);
            }, 3000);
        }

        // Aplicar estado de desactivación al cargar
        (function() {
            if (localStorage.getItem('wallpaper_disabled') === 'true') {
                DISABLE_VIDEO_BY_CONTEXT = true;
            }
            const pinnedVideo = localStorage.getItem('wallpaper_pinned_video');
            if (pinnedVideo) {
                try {
                    const pinned = JSON.parse(pinnedVideo);
                    setTimeout(() => {
                        aplicarVideo(pinned.videoUrl);
                    }, 500);
                } catch (e) {
                    console.error('Error aplicando video fijado:', e);
                }
            }
        })();
    </script>
</body>

</html>
