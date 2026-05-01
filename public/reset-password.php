<?php

/**
 * Reset Password - Restablecer contraseña con token
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Si ya está logueado, redirigir
if (!empty($_SESSION['id_login'])) {
    header('Location: /public/menu/menu.php');
    exit;
}

$errors = [];
$success = false;
$tokenValid = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// Validar token
if (!empty($token)) {
    $resetData = Security::validatePasswordResetToken($token);
    $tokenValid = !empty($resetData);
}

// Procesar cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    // Validar CSRF
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido.';
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($newPassword)) {
            $errors[] = 'Ingrese la nueva contraseña.';
        } elseif (strlen($newPassword) < 6) {
            $errors[] = 'La contraseña debe tener al menos 6 caracteres.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Las contraseñas no coinciden.';
        }

        if (empty($errors)) {
            $result = Auth::completePasswordReset($token, $newPassword);

            if ($result['success']) {
                $success = true;
            } else {
                $errors[] = $result['error'];
            }
        }
    }
}

$csrfToken = Security::generateCSRFToken();

function h($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#3b82f6">
    <title>Restablecer Contraseña | Sistemax v1</title>

    <style>
        html.dark input[type="password"] {
            background-color: #1e293b !important;
            border-color: #475569 !important;
            color: #f1f5f9 !important;
        }

        /* Wallpaper dinámico */
        body {
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
            transition: background-image 0.5s ease-in-out;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: inherit;
            filter: blur(0px);
            z-index: -1;
        }
    </style>

    <link rel="stylesheet" href="assets/tailwind.css">
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
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();

        // Sistema de wallpapers
        function aplicarWallpaper() {
            const urlOscuro = localStorage.getItem('urlImagenOscuro');
            const urlClaro = localStorage.getItem('urlImagenClaro');

            if (!urlOscuro || !urlClaro) return;

            const tema = localStorage.getItem('theme');
            const isDark = tema === 'dark' || (!tema && document.documentElement.classList.contains('dark'));

            if (isDark) {
                document.body.style.backgroundImage = 'url(' + urlOscuro + ')';
            } else {
                document.body.style.backgroundImage = 'url(' + urlClaro + ')';
            }
        }

        document.addEventListener('DOMContentLoaded', aplicarWallpaper);
    </script>
</head>

<body class="bg-gradient-to-br from-blue-500 via-blue-500 to-cyan-500 
             dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-slate-950
             min-h-screen flex items-center justify-center p-4
             transition-colors duration-300">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-6 md:mb-8">
            <div class="bg-white dark:bg-slate-800/50 dark:backdrop-blur-xl dark:border dark:border-slate-700/50 
                        rounded-full w-16 h-16 md:w-20 md:h-20 flex items-center justify-center mx-auto mb-3 md:mb-4 
                        shadow-lg dark:shadow-blue-500/20">
                <i class="fas fa-lock-open text-3xl md:text-4xl text-blue-600 dark:text-blue-400"></i>
            </div>
            <h1 class="text-2xl md:text-3xl font-bold text-white mb-1 dark:text-slate-100">Nueva Contraseña</h1>
            <p class="text-sm text-white/80 dark:text-slate-400">Ingresa tu nueva contraseña</p>
        </div>

        <!-- Card -->
        <div class="bg-white/95 dark:bg-slate-900/90 
                    dark:backdrop-blur-2xl dark:border dark:border-slate-800/50
                    rounded-2xl shadow-2xl dark:shadow-slate-950/50 
                    p-6 md:p-8 backdrop-blur-lg">

            <?php if ($success): ?>
                <!-- Éxito -->
                <div class="text-center">
                    <div class="bg-green-100 dark:bg-green-950/30 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check text-3xl text-green-500"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100 mb-2">¡Contraseña Actualizada!</h2>
                    <p class="text-gray-600 dark:text-slate-400 mb-6">
                        Tu contraseña ha sido cambiada exitosamente. Ya puedes iniciar sesión.
                    </p>
                    <a href="login.php"
                        class="inline-flex items-center justify-center w-full bg-blue-600 hover:bg-blue-700
                              text-white font-semibold py-3 rounded-lg transition-all duration-200">
                        <i class="fas fa-sign-in-alt mr-2"></i>Iniciar Sesión
                    </a>
                </div>

            <?php elseif (!$tokenValid): ?>
                <!-- Token inválido -->
                <div class="text-center">
                    <div class="bg-red-100 dark:bg-red-950/30 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-times text-3xl text-red-500"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100 mb-2">Enlace Inválido</h2>
                    <p class="text-gray-600 dark:text-slate-400 mb-6">
                        El enlace ha expirado o es inválido. Solicita uno nuevo.
                    </p>
                    <a href="forgot-password.php"
                        class="inline-flex items-center justify-center w-full border-2 border-blue-600 dark:border-blue-500
                              text-blue-600 dark:text-blue-400 font-semibold py-3 rounded-lg
                              hover:bg-blue-600 hover:text-white transition-all duration-200 mb-4">
                        <i class="fas fa-redo mr-2"></i>Solicitar Nuevo Enlace
                    </a>
                    <a href="login.php"
                        class="inline-flex items-center justify-center w-full text-gray-600 dark:text-slate-400 
                              hover:text-gray-800 dark:hover:text-slate-200 text-sm py-2">
                        <i class="fas fa-arrow-left mr-2"></i>Volver al Login
                    </a>
                </div>

            <?php else: ?>
                <!-- Formulario -->
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 dark:bg-red-950/30 border-l-4 border-red-500 p-4 mb-6 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                            <div class="text-red-700 dark:text-red-300">
                                <?php foreach ($errors as $error): ?>
                                    <p><?php echo h($error); ?></p>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="token" value="<?php echo h($token); ?>">

                    <!-- Nueva contraseña -->
                    <div class="mb-4">
                        <label class="block text-gray-700 dark:text-slate-300 text-sm font-semibold mb-2" for="new_password">
                            <i class="fas fa-lock mr-2 text-blue-600 dark:text-blue-400"></i>Nueva Contraseña
                        </label>
                        <div class="relative">
                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                class="w-full px-4 py-3 pr-12 text-base 
                                       border border-gray-300 dark:border-slate-600 
                                       bg-white dark:bg-slate-800 
                                       text-gray-900 dark:text-slate-100 
                                       rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Mínimo 6 caracteres"
                                minlength="6"
                                required />
                            <button type="button" onclick="togglePassword('new_password', 'icon1')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-slate-400">
                                <i class="fas fa-eye" id="icon1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirmar contraseña -->
                    <div class="mb-6">
                        <label class="block text-gray-700 dark:text-slate-300 text-sm font-semibold mb-2" for="confirm_password">
                            <i class="fas fa-lock mr-2 text-blue-600 dark:text-blue-400"></i>Confirmar Contraseña
                        </label>
                        <div class="relative">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="w-full px-4 py-3 pr-12 text-base 
                                       border border-gray-300 dark:border-slate-600 
                                       bg-white dark:bg-slate-800 
                                       text-gray-900 dark:text-slate-100 
                                       rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Repite la contraseña"
                                minlength="6"
                                required />
                            <button type="button" onclick="togglePassword('confirm_password', 'icon2')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-slate-400">
                                <i class="fas fa-eye" id="icon2"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg
                                   transition-all duration-200 active:scale-95 mb-4">
                        <i class="fas fa-save mr-2"></i>Cambiar Contraseña
                    </button>

                    <a href="login.php"
                        class="inline-flex items-center justify-center w-full text-gray-600 dark:text-slate-400 
                              hover:text-gray-800 dark:hover:text-slate-200 text-sm py-2">
                        <i class="fas fa-arrow-left mr-2"></i>Cancelar
                    </a>
                </form>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6 text-white/60 dark:text-slate-500 text-xs">
            <p>&copy; <?php echo date('Y'); ?> Sistemax v1.0</p>
        </div>
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>

</html>