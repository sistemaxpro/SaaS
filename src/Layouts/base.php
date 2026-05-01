<?php
if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}

$pageTitle = $pageTitle ?? 'SistemaX';
$pageDescription = $pageDescription ?? '';
?>
<!DOCTYPE html>
<html lang="es" x-data="appData()" :class="{ 'dark': darkMode }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title><?= htmlspecialchars($pageTitle) ?> - Xpro</title>

    <!-- Alpine.js -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
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

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Detectar tema antes de renderizar (evita flash) -->
    <script>
        (function() {
            // Siempre heredar del navegador (sin localStorage)
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (prefersDark) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    <!-- Estilos Globales para Botones -->
    <style>
        /* Botón Primario Outline */
        .btn-primary {
            @apply border-2 border-blue-600 dark:border-blue-500 text-blue-600 dark:text-blue-400 font-semibold px-4 py-2 rounded-lg hover:bg-blue-600 hover:text-white dark:hover:bg-blue-500 dark:hover:text-white dark:hover:border-blue-500 active:scale-95 transition-all duration-200;
        }

        /* Botón Secundario Outline */
        .btn-secondary {
            @apply border-2 border-slate-400 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-semibold px-4 py-2 rounded-lg hover:bg-slate-400 hover:text-white dark:hover:bg-slate-600 dark:hover:text-white dark:hover:border-slate-600 active:scale-95 transition-all duration-200;
        }

        /* Botón Éxito Outline */
        .btn-success {
            @apply border-2 border-green-600 dark:border-green-500 text-green-600 dark:text-green-400 font-semibold px-4 py-2 rounded-lg hover:bg-green-600 hover:text-white dark:hover:bg-green-500 dark:hover:text-white dark:hover:border-green-500 active:scale-95 transition-all duration-200;
        }

        /* Botón Peligro Outline */
        .btn-danger {
            @apply border-2 border-red-600 dark:border-red-500 text-red-600 dark:text-red-400 font-semibold px-4 py-2 rounded-lg hover:bg-red-600 hover:text-white dark:hover:bg-red-500 dark:hover:text-white dark:hover:border-red-500 active:scale-95 transition-all duration-200;
        }

        /* Botón Advertencia Outline */
        .btn-warning {
            @apply border-2 border-amber-600 dark:border-amber-500 text-amber-600 dark:text-amber-400 font-semibold px-4 py-2 rounded-lg hover:bg-amber-600 hover:text-white dark:hover:bg-amber-500 dark:hover:text-white dark:hover:border-amber-500 active:scale-95 transition-all duration-200;
        }

        /* Botón Información Outline */
        .btn-info {
            @apply border-2 border-cyan-600 dark:border-cyan-500 text-cyan-600 dark:text-cyan-400 font-semibold px-4 py-2 rounded-lg hover:bg-cyan-600 hover:text-white dark:hover:bg-cyan-500 dark:hover:text-white dark:hover:border-cyan-500 active:scale-95 transition-all duration-200;
        }

        /* Tamaños de botones */
        .btn-sm {
            @apply px-3 py-1.5 text-sm;
        }

        .btn-lg {
            @apply px-6 py-3 text-lg;
        }

        .btn-block {
            @apply w-full;
        }
    </style>

    <!-- Configuración Global -->
    <script>
        window.SISTEMAX = {
            version: 'v1',
            baseUrl: '',
            apiUrl: '/api/v1',
            empresa: {
                id: <?= Session::getIdEmpresa() ?? 'null' ?>,
                dbase: '<?= Session::getDbase() ?? '' ?>'
            },
            user: {
                id: <?= Session::getIdLogin() ?? 'null' ?>,
                isAdmin: <?= Session::isAdmin() ? 'true' : 'false' ?>
            }
        };

        function appData() {
            return {
                darkMode: window.matchMedia('(prefers-color-scheme: dark)').matches,
                init() {
                    // Heredar tema del navegador
                    this.darkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', this.darkMode);

                    // Escuchar cambios en preferencias del navegador
                    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                        this.darkMode = e.matches;
                        document.documentElement.classList.toggle('dark', this.darkMode);
                    });
                }
            }
        }
    </script>
</head>

<body class="min-h-screen bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-slate-100 transition-colors duration-300">

    <!-- Navbar -->
    <?php include __DIR__ . '/components/navbar.php'; ?>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-6">
        <?= $content ?? '' ?>
    </main>

    <!-- Footer -->
    <?php include __DIR__ . '/components/footer.php'; ?>

</body>

</html>
