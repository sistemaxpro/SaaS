<?php

/**
 * Forgot Password - Solicitar reset de contraseña
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Si ya está logueado, redirigir
if (!empty($_SESSION['id_login'])) {
    header('Location: /public/menu/menu.php');
    exit;
}

$errors = [];
$success = false;
$emailOrLogin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido.';
    } else {
        $emailOrLogin = trim($_POST['email_or_login'] ?? '');

        if (empty($emailOrLogin)) {
            $errors[] = 'Ingrese su email o usuario.';
        }

        if (empty($errors)) {
            $result = Auth::initiatePasswordReset($emailOrLogin);

            // Siempre mostrar éxito por seguridad (no revelar si existe)
            $success = true;

            // En desarrollo, mostrar el link de reset
            if (!empty($result['token'])) {
                $resetLink = "reset-password.php?token=" . urlencode($result['token']);
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
    <title>Recuperar Contraseña | Sistemax v1</title>

    <style id="dark-inputs-critical">
        html.dark input[type="text"],
        html.dark input[type="email"] {
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
                <i class="fas fa-key text-3xl md:text-4xl text-blue-600 dark:text-blue-400"></i>
            </div>
            <h1 class="text-2xl md:text-3xl font-bold text-white mb-1 dark:text-slate-100">Recuperar Contraseña</h1>
            <p class="text-sm text-white/80 dark:text-slate-400">Te enviaremos instrucciones por email</p>
        </div>

        <!-- Card -->
        <div class="bg-white/95 dark:bg-slate-900/90 
                    dark:backdrop-blur-2xl dark:border dark:border-slate-800/50
                    rounded-2xl shadow-2xl dark:shadow-slate-950/50 
                    p-6 md:p-8 backdrop-blur-lg">

            <?php if ($success): ?>
                <!-- Mensaje de éxito -->
                <div class="text-center">
                    <div class="bg-green-100 dark:bg-green-950/30 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check text-3xl text-green-500"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100 mb-2">Email Enviado</h2>
                    <p class="text-gray-600 dark:text-slate-400 mb-6">
                        Si existe una cuenta con ese email/usuario, recibirás instrucciones para restablecer tu contraseña.
                    </p>

                    <?php if (!empty($resetLink)): ?>
                        <!-- Solo para desarrollo -->
                        <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg p-4 mb-6 text-left">
                            <p class="text-amber-700 dark:text-amber-300 text-sm font-semibold mb-2">
                                <i class="fas fa-flask mr-1"></i> Modo Desarrollo
                            </p>
                            <p class="text-amber-600 dark:text-amber-400 text-xs break-all">
                                <a href="<?php echo h($resetLink); ?>" class="underline hover:no-underline">
                                    <?php echo h($resetLink); ?>
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <a href="login.php"
                        class="inline-flex items-center justify-center w-full border-2 border-blue-600 dark:border-blue-500
                              text-blue-600 dark:text-blue-400 font-semibold py-3 rounded-lg
                              hover:bg-blue-600 hover:text-white dark:hover:bg-blue-500 dark:hover:text-white
                              transition-all duration-200">
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

                    <div class="mb-6">
                        <label class="block text-gray-700 dark:text-slate-300 text-sm font-semibold mb-2" for="email_or_login">
                            <i class="fas fa-envelope mr-2 text-blue-600 dark:text-blue-400"></i>Email o Usuario
                        </label>
                        <input
                            type="text"
                            id="email_or_login"
                            name="email_or_login"
                            class="w-full px-4 py-3 text-base 
                                   border border-gray-300 dark:border-slate-600 
                                   bg-white dark:bg-slate-800 
                                   text-gray-900 dark:text-slate-100 
                                   placeholder:text-gray-400 dark:placeholder:text-slate-500
                                   rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 
                                   transition-all duration-200"
                            placeholder="tucorreo@ejemplo.com o usuario"
                            value="<?php echo h($emailOrLogin); ?>"
                            autofocus
                            required />
                    </div>

                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg
                                   transition-all duration-200 active:scale-95 mb-4">
                        <i class="fas fa-paper-plane mr-2"></i>Enviar Instrucciones
                    </button>

                    <a href="login.php"
                        class="inline-flex items-center justify-center w-full text-gray-600 dark:text-slate-400 
                              hover:text-gray-800 dark:hover:text-slate-200 text-sm py-2 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Volver al Login
                    </a>
                </form>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6 text-white/60 dark:text-slate-500 text-xs">
            <p>&copy; <?php echo date('Y'); ?> Sistemax v1.0</p>
        </div>
    </div>
</body>

</html>