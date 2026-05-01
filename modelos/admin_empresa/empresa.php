<?php

/**
 * Admin Empresas - serproc1.empresa
 * Grid homologado con facturas_sifen.php
 */
if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}

$masterDb = defined('MASTER_DB') ? MASTER_DB : ($_SESSION['dbu'] ?? 'serproc1');
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

// Verificar permisos: solo admin de empresa 169
$id_empresa = (int)($_SESSION['id_empresa'] ?? 0);
$usr_priv_admin = (bool)($_SESSION['usr_priv_admin'] ?? false);
$hasAccess = ($id_empresa == 169 && $usr_priv_admin);

// Manejar login TI via AJAX
$action = $_GET['action'] ?? '';
if ($action === 'ti_login') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $loginUser = trim($input['usuario'] ?? '');
    $loginPass = trim($input['password'] ?? '');

    if (empty($loginUser) || empty($loginPass)) {
        echo json_encode(['success' => false, 'message' => 'Complete todos los campos']);
        exit;
    }

    try {
        // Usar credenciales fijas para conexión master
        $pdo = new PDO("mysql:host=168.231.95.50;port=3306;dbname=" . MASTER_DB, "sistemax", "Armagedon123");
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Verificar usuario TI
        $stmt = $pdo->prepare("SELECT id_login, login, ti, id_empresa, priv_admin FROM sec_users WHERE login = :login AND pswd = MD5(:password) AND ti = 1");
        $stmt->execute([':login' => $loginUser, ':password' => $loginPass]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Actualizar sesión con permisos TI
            $_SESSION['id_login'] = $user['id_login'];
            $_SESSION['id_empresa'] = 169; // Empresa Sistemax
            $_SESSION['usr_priv_admin'] = true;
            $_SESSION['usr_ti'] = true;
            $_SESSION['usuario'] = $user['login'];

            echo json_encode(['success' => true, 'message' => 'Acceso concedido']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Credenciales inválidas o no es usuario TI']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$hasAccess) {
        echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
        exit;
    }
    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SELECT * FROM {$masterDb}.empresa ORDER BY empresa");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'rows' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si no tiene acceso, mostrar pantalla de bloqueo
if (!$hasAccess):
?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="utf-8">
        <title>Acceso Restringido</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>

    <body class="bg-slate-900 min-h-screen flex items-center justify-center">
        <div x-data="{ 
            show: false, 
            showLogin: false,
            showPass: false,
            usuario: '',
            password: '',
            loading: false,
            error: '',
            async login() {
                if (!this.usuario || !this.password) {
                    this.error = 'Complete todos los campos';
                    return;
                }
                this.loading = true;
                this.error = '';
                try {
                    const res = await fetch('?action=ti_login', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ usuario: this.usuario, password: this.password })
                    });
                    const data = await res.json();
                    if (data.success) {
                        window.location.reload();
                    } else {
                        this.error = data.message;
                    }
                } catch (e) {
                    this.error = 'Error de conexión';
                }
                this.loading = false;
            }
        }" x-init="setTimeout(() => show = true, 100)" class="text-center">
            <div x-show="show"
                x-transition:enter="transition ease-out duration-500"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                class="bg-slate-800 border border-slate-700 rounded-2xl p-12 shadow-2xl max-w-md mx-4">

                <div class="mb-6">
                    <div class="w-24 h-24 mx-auto bg-gradient-to-br from-red-500 to-orange-500 rounded-full flex items-center justify-center shadow-lg">
                        <i class="fas fa-lock text-white text-4xl"></i>
                    </div>
                </div>

                <h1 class="text-2xl font-bold text-white mb-3">Acceso Restringido</h1>

                <p class="text-slate-400 mb-6 text-lg">
                    App de uso exclusivo de <span class="text-cyan-400 font-semibold">Sistemax</span>
                </p>

                <!-- Mensaje info -->
                <div x-show="!showLogin" class="bg-slate-700/50 rounded-lg p-4 mb-6">
                    <p class="text-slate-500 text-sm">
                        <i class="fas fa-info-circle mr-2"></i>
                        Esta aplicación requiere permisos especiales de administrador TI
                    </p>
                </div>

                <!-- Formulario Login TI -->
                <div x-show="showLogin" x-transition class="mb-6">
                    <div class="space-y-4">
                        <div>
                            <input type="text"
                                x-model="usuario"
                                @keydown.enter="login()"
                                placeholder="Usuario"
                                class="w-full px-4 py-3 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition-all">
                        </div>
                        <div class="relative">
                            <input :type="showPass ? 'text' : 'password'"
                                x-model="password"
                                @keydown.enter="login()"
                                placeholder="Contraseña"
                                class="w-full px-4 py-3 pr-12 bg-slate-700 border border-slate-600 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition-all">
                            <button type="button" @click="showPass = !showPass"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white transition-colors">
                                <i :class="showPass ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                            </button>
                        </div>
                        <!-- Error message -->
                        <div x-show="error" x-transition class="bg-red-500/20 border border-red-500/50 rounded-lg p-3">
                            <p class="text-red-400 text-sm" x-text="error"></p>
                        </div>
                    </div>
                </div>

                <!-- Botones -->
                <div class="flex gap-3 justify-center">
                    <button @click="showLogin ? (showLogin = false, error = '') : history.back()"
                        class="px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg font-medium transition-all duration-200 hover:scale-105">
                        <i class="fas fa-arrow-left mr-2"></i>Volver
                    </button>

                    <button x-show="!showLogin" @click="showLogin = true"
                        class="px-6 py-3 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg font-medium transition-all duration-200 hover:scale-105">
                        <i class="fas fa-key mr-2"></i>Acceso TI
                    </button>

                    <button x-show="showLogin" @click="login()" :disabled="loading"
                        class="px-6 py-3 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg font-medium transition-all duration-200 hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span x-show="!loading"><i class="fas fa-sign-in-alt mr-2"></i>Ingresar</span>
                        <span x-show="loading"><i class="fas fa-spinner fa-spin mr-2"></i>Verificando...</span>
                    </button>
                </div>
            </div>

            <p class="text-slate-600 text-sm mt-8">
                © <?php echo date('Y'); ?> Sistemax - Todos los derechos reservados
            </p>
        </div>
    </body>

    </html>
<?php
    exit;
endif;

$nombreEmpresa = 'Admin Empresas';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Admin Empresas</title>
    <link rel="stylesheet" href="../_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="../_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="../_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js"></script>
    <script src="../_lib/ag-grid/license.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: {
                            850: '#151e2e'
                        }
                    }
                }
            }
        }
    </script>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && systemDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    <style>
        .ag-theme-quartz {
            --ag-font-family: "Poppins", sans-serif;
            --ag-font-size: 13px;
            --ag-odd-row-background-color: rgba(0, 0, 0, 0.05);
        }

        .ag-theme-quartz-dark {
            --ag-font-family: "Poppins", sans-serif;
            --ag-font-size: 13px;
            --ag-odd-row-background-color: rgba(255, 255, 255, 0.04);
        }

        html.dark .ag-theme-quartz-dark,
        html.dark .ag-theme-quartz {
            --ag-background-color: #0b121e !important;
            --ag-foreground-color: #e2e8f0 !important;
            --ag-header-background-color: #0f172a !important;
            --ag-header-foreground-color: #94a3b8 !important;
            --ag-border-color: #1e293b !important;
            --ag-row-border-color: #1e293b !important;
            --ag-row-hover-color: #1e293b !important;
            --ag-selected-row-background-color: rgba(59, 130, 246, 0.15) !important;
            --ag-input-focus-border-color: #3b82f6 !important;
            --ag-data-color: #f1f5f9 !important;
            --ag-font-family: "Poppins", sans-serif;
        }

        :root {
            color-scheme: light;
            --bg: transparent;
            --text: #1f2933;
            --border-color: rgba(0, 0, 0, 0.4);
            --ag-background: rgba(255, 255, 255, 0.2) !important;
            --ag-header-background: rgba(241, 245, 249, 0.6) !important;
        }

        html.dark {
            color-scheme: dark;
            --bg: transparent !important;
            --text: #f1f5f9;
            --border-color: rgba(255, 255, 255, 0.4);
            --ag-background: rgba(30, 41, 59, 0.4) !important;
            --ag-header-background: rgba(15, 23, 42, 0.6) !important;
            background: transparent !important;
            background-color: transparent !important;
        }

        body {
            font-family: "Poppins", sans-serif;
            margin: 12px;
            background: transparent !important;
            color: var(--text);
            min-height: calc(100vh - 0px);
            overflow: hidden;
        }

        html.dark body {
            margin: 0;
        }

        .container {
            height: 100vh;
            background-color: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(2px);
            border: 0px;
            border-radius: 20px;
            margin: 0;
            padding: 12px;
            width: 100% !important;
            max-width: 100% !important;
        }

        html.dark .container {
            background-color: rgba(12, 23, 39, 0.9) !important;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: nowrap;
            gap: 10px;
        }

        h1 {
            color: #2d7be5;
            font-size: 1.2rem !important;
            font-weight: 500 !important;
        }

        .toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .btn-chip {
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 400;
            cursor: pointer;
            border: 1px solid #3b82f6;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            color: #2d7be5;
            box-shadow: none;
            transition: all 0.2s;
            font-size: 13px;
        }

        .btn-chip:hover {
            background: rgba(59, 130, 246, 0.05);
            transform: translateY(-1px);
        }

        .btn-chip--primary {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .btn-chip--primary:hover {
            background: rgba(59, 130, 246, 0.08);
        }

        .btn-chip--success {
            border-color: #22c55e;
            color: #22c55e;
        }

        .btn-chip--success:hover {
            background: rgba(34, 197, 94, 0.08);
        }

        .btn-chip--danger {
            border-color: #ef4444;
            color: #ef4444;
        }

        .btn-chip--danger:hover {
            background: rgba(239, 68, 68, 0.08);
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 10px 10px 35px;
            border-radius: 8px;
            border: 1px solid #ccc;
            width: 220px;
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        /* Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }

        .badge-warning {
            background: rgba(234, 179, 8, 0.15);
            color: #eab308;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            height: calc(100vh - 78px);
            min-height: 500px;
            border-radius: 8px;
        }

        /* Fix para menú contextual y popups de AG Grid */
        .ag-popup {
            z-index: 9999 !important;
        }

        .ag-popup-child {
            background: var(--ag-background-color, #fff) !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15) !important;
        }

        html.dark .ag-popup-child {
            background: #1e293b !important;
        }

        .modal-card {
            background: var(--modal-bg, #fff);
            border-radius: 12px;
        }

        .modal-header {
            color: var(--text);
            border-bottom: 1px solid var(--border-color);
            background: transparent;
        }

        html.dark .modal-card {
            background: rgba(12, 23, 39, 0.96);
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="page-header">
            <div>
                <h1><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($nombreEmpresa); ?></h1>
            </div>
            <div class="toolbar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input id="searchInput" type="text" placeholder="Buscar empresa...">
                </div>
                <button class="btn-chip btn-chip--primary" id="refreshBtn" type="button">
                    <i class="fas fa-rotate"></i> Actualizar
                </button>
                <button class="btn-chip btn-chip--success" id="newEmpresaBtn" type="button">
                    <i class="fas fa-plus"></i> Nueva
                </button>
                <button class="btn-chip" id="exportExcelBtn" type="button" onclick="exportExcel()">
                    <i class="fas fa-file-excel text-green-600"></i> XLS
                </button>
                <button class="btn-chip" id="exportPdfBtn" type="button" onclick="exportPDF()">
                    <i class="fas fa-file-pdf text-red-600"></i> PDF
                </button>
                <button class="btn-chip btn-chip--danger" id="salirBtn" type="button" onclick="if(window.parent && window.parent !== window && typeof window.parent.cerrarApp === 'function') { window.parent.cerrarApp(); } else { window.history.back(); }">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </button>
            </div>
        </div>

        <div id="gridEmpresas" class="ag-theme-quartz"></div>
    </div>

    <!-- Modal Empresa -->
    <div id="empresaModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;">
        <div class="modal-card" style="width:90%;max-width:1200px;height:90vh;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.3);border-radius:16px;">
            <iframe id="empresaModalFrame" src="" style="width:100%;height:100%;border:0;"></iframe>
        </div>
    </div>
    <h2 id="empresaModalTitle" style="display:none;"></h2>
    <button id="closeEmpresaModal" style="display:none;"></button>

    <!-- Sistema de Notificaciones Alpine.js -->
    <div id="notifContainer" class="fixed top-4 right-4 z-[10000] space-y-2"></div>

    <!-- Modal de Confirmación Alpine.js -->
    <div id="confirmModal" x-data="confirmModalData()" x-show="open" x-cloak
        class="fixed inset-0 z-[10001] flex items-center justify-center p-4"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-black/50" @click="cancel()"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-xl shadow-2xl max-w-md w-full p-6"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100">
            <div class="flex items-start gap-4">
                <div :class="iconClass" class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0">
                    <i :class="icon" class="text-xl"></i>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white" x-text="title"></h3>
                    <div class="mt-2 text-sm text-slate-600 dark:text-slate-300" x-html="message"></div>
                    <template x-if="showInput">
                        <div class="mt-3">
                            <input type="text" x-model="inputValue" :placeholder="inputPlaceholder"
                                class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                @keyup.enter="confirm()">
                            <p x-show="inputError" class="mt-1 text-sm text-red-500" x-text="inputError"></p>
                        </div>
                    </template>
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button @click="cancel()" class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-lg transition-colors" x-text="cancelText"></button>
                <button @click="confirm()" :class="confirmClass" class="px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors flex items-center gap-2">
                    <i :class="confirmIcon"></i>
                    <span x-text="confirmText"></span>
                </button>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    <script>
        // Sistema de notificaciones toast
        function showNotif(title, message, type = 'info', duration = 4000) {
            const container = document.getElementById('notifContainer');
            const id = 'notif-' + Date.now();
            const icons = {
                success: 'fas fa-check-circle text-green-500',
                error: 'fas fa-times-circle text-red-500',
                warning: 'fas fa-exclamation-triangle text-amber-500',
                info: 'fas fa-info-circle text-blue-500'
            };
            const bgColors = {
                success: 'bg-green-50 dark:bg-green-900/30 border-green-200 dark:border-green-800',
                error: 'bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800',
                warning: 'bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800',
                info: 'bg-blue-50 dark:bg-blue-900/30 border-blue-200 dark:border-blue-800'
            };

            const notif = document.createElement('div');
            notif.id = id;
            notif.className = `${bgColors[type]} border rounded-xl p-4 shadow-lg max-w-sm transform transition-all duration-300 translate-x-full`;
            notif.innerHTML = `
                <div class="flex items-start gap-3">
                    <i class="${icons[type]} text-lg mt-0.5"></i>
                    <div class="flex-1">
                        <p class="font-medium text-slate-900 dark:text-white text-sm">${title}</p>
                        <p class="text-slate-600 dark:text-slate-300 text-xs mt-0.5">${message}</p>
                    </div>
                    <button onclick="document.getElementById('${id}').remove()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>
            `;
            container.appendChild(notif);

            requestAnimationFrame(() => {
                notif.classList.remove('translate-x-full');
                notif.classList.add('translate-x-0');
            });

            if (duration > 0) {
                setTimeout(() => {
                    notif.classList.add('translate-x-full', 'opacity-0');
                    setTimeout(() => notif.remove(), 300);
                }, duration);
            }
        }

        // Sistema de modales de confirmación
        let confirmModalResolver = null;

        function confirmModalData() {
            return {
                open: false,
                title: '',
                message: '',
                icon: 'fas fa-question',
                iconClass: 'bg-blue-100 dark:bg-blue-900/50 text-blue-600',
                confirmText: 'Aceptar',
                confirmIcon: 'fas fa-check',
                confirmClass: 'bg-blue-600 hover:bg-blue-700',
                cancelText: 'Cancelar',
                showInput: false,
                inputValue: '',
                inputPlaceholder: '',
                inputError: '',
                expectedInput: '',

                show(options) {
                    this.title = options.title || 'Confirmar';
                    this.message = options.message || '';
                    this.icon = options.icon || 'fas fa-question';
                    this.iconClass = options.iconClass || 'bg-blue-100 dark:bg-blue-900/50 text-blue-600';
                    this.confirmText = options.confirmText || 'Aceptar';
                    this.confirmIcon = options.confirmIcon || 'fas fa-check';
                    this.confirmClass = options.confirmClass || 'bg-blue-600 hover:bg-blue-700';
                    this.cancelText = options.cancelText || 'Cancelar';
                    this.showInput = options.showInput || false;
                    this.inputValue = '';
                    this.inputPlaceholder = options.inputPlaceholder || '';
                    this.inputError = '';
                    this.expectedInput = options.expectedInput || '';
                    this.open = true;
                },

                confirm() {
                    if (this.showInput && this.expectedInput && this.inputValue !== this.expectedInput) {
                        this.inputError = 'El valor no coincide';
                        return;
                    }
                    this.open = false;
                    if (confirmModalResolver) {
                        confirmModalResolver({
                            confirmed: true,
                            value: this.inputValue
                        });
                        confirmModalResolver = null;
                    }
                },

                cancel() {
                    this.open = false;
                    if (confirmModalResolver) {
                        confirmModalResolver({
                            confirmed: false,
                            value: null
                        });
                        confirmModalResolver = null;
                    }
                }
            };
        }

        function showConfirm(options) {
            return new Promise((resolve) => {
                confirmModalResolver = resolve;
                const modal = document.getElementById('confirmModal');
                if (modal && modal.__x) {
                    modal.__x.$data.show(options);
                }
            });
        }
        const API_URL = 'empresa.php';

        const columnDefs = [{
                headerName: '',
                field: 'logos',
                width: 60,
                sortable: false,
                filter: false,
                cellRenderer: p => {
                    if (p.data && p.data.logos) {
                        return `<div style="display:flex;align-items:center;justify-content:center;height:100%;">
                            <img src="/_lib/file/img/empresa/${p.data.logos}" 
                                 style="width:32px;height:32px;object-fit:contain;border-radius:4px;" 
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                 alt="">
                            <div style="display:none;width:32px;height:32px;background:#1e293b;border-radius:4px;align-items:center;justify-content:center;">
                                <i class="fas fa-building" style="color:#475569;font-size:14px;"></i>
                            </div>
                        </div>`;
                    }
                    return `<div style="display:flex;align-items:center;justify-content:center;height:100%;">
                        <div style="width:32px;height:32px;background:#1e293b;border-radius:4px;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-building" style="color:#475569;font-size:14px;"></i>
                        </div>
                    </div>`;
                }
            },
            {
                headerName: 'ID',
                field: 'id_empresa',
                width: 70,
                sort: 'desc'
            },
            {
                headerName: 'DB',
                field: 'dbase',
                width: 120
            },
            {
                headerName: 'Empresa',
                field: 'empresa',
                minWidth: 250,
                flex: 1
            },
            {
                headerName: 'RUC',
                field: 'ruc',
                width: 150,
                valueFormatter: p => p.data ? `${p.data.ruc}-${p.data.dv ?? ''}` : ''
            },
            {
                headerName: 'Dirección',
                field: 'direccion',
                minWidth: 200,
                flex: 1
            },
            {
                headerName: 'WhatsApp',
                field: 'telefono',
                width: 140
            },
            {
                headerName: 'Email',
                field: 'email',
                minWidth: 200
            },
            {
                headerName: 'Estado',
                field: 'activo',
                width: 100,
                cellRenderer: p => `<span class="badge ${p.value == 1 ? 'badge-success' : 'badge-danger'}">${p.value == 1 ? 'Activo' : 'Inactivo'}</span>`
            }
        ];

        const gridOptions = {
            theme: "legacy",
            columnDefs,
            rowData: [],
            defaultColDef: {
                resizable: true,
                sortable: true,
                filter: true,
                minWidth: 100
            },
            animateRows: true,
            rowHeight: 42,
            headerHeight: 38,
            rowSelection: 'single',
            suppressMenuHide: false,
            columnMenu: 'legacy',
            getContextMenuItems: (params) => {
                const result = [{
                        name: 'Editar',
                        icon: '<i class="fas fa-edit text-blue-500"></i>',
                        action: () => {
                            if (params.node && params.node.data) {
                                openEmpresaModal(params.node.data.id_empresa);
                            }
                        }
                    },
                    {
                        name: 'Conf. SIFEN',
                        icon: '<i class="fas fa-file-invoice text-green-500"></i>',
                        action: () => {
                            if (params.node && params.node.data) {
                                openSifenConfig(params.node.data.id_empresa);
                            }
                        }
                    },
                    {
                        name: 'Suprimir',
                        icon: '<i class="fas fa-trash-alt text-red-500"></i>',
                        action: () => {
                            if (params.node && params.node.data) {
                                confirmarEliminar(params.node.data);
                            }
                        }
                    },
                    'separator',
                    'copy',
                    'export'
                ];
                return result;
            },
            sideBar: {
                toolPanels: [{
                        id: 'columns',
                        labelDefault: 'Columnas',
                        labelKey: 'columns',
                        iconKey: 'columns',
                        toolPanel: 'agColumnsToolPanel',
                        toolPanelParams: {
                            suppressRowGroups: true,
                            suppressValues: true,
                            suppressPivots: true,
                            suppressPivotMode: true
                        }
                    },
                    {
                        id: 'filters',
                        labelDefault: 'Filtros',
                        labelKey: 'filters',
                        iconKey: 'filter',
                        toolPanel: 'agFiltersToolPanel'
                    }
                ],
                defaultToolPanel: ''
            },
            localeText: {
                loadingOoo: 'Cargando...',
                noRowsToShow: 'No hay registros',
                page: 'Página',
                nextPage: 'Siguiente',
                lastPage: 'Última',
                firstPage: 'Primera'
            },
            onRowDoubleClicked: (event) => {
                if (event.data && event.data.id_empresa) {
                    openEmpresaModal(event.data.id_empresa);
                }
            }
        };

        const gridDiv = document.querySelector('#gridEmpresas');
        const gridApi = agGrid.createGrid(gridDiv, gridOptions);

        async function loadData() {
            gridApi.setGridOption('loading', true);
            try {
                const res = await fetch(`${API_URL}?action=list&_t=${Date.now()}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Error desconocido');
                gridApi.setGridOption('rowData', data.rows || []);
            } catch (e) {
                console.error(e);
                gridApi.showNoRowsOverlay();
            } finally {
                gridApi.setGridOption('loading', false);
            }
        }

        document.getElementById('searchInput').addEventListener('input', (e) => {
            gridApi.setGridOption('quickFilterText', e.target.value);
        });

        document.getElementById('refreshBtn').addEventListener('click', () => {
            loadData();
        });

        document.getElementById('newEmpresaBtn').addEventListener('click', () => {
            openEmpresaModal();
        });

        function openEmpresaModal(idEmpresa = null) {
            const modal = document.getElementById('empresaModal');
            const frame = document.getElementById('empresaModalFrame');
            const title = document.getElementById('empresaModalTitle');

            if (idEmpresa) {
                frame.src = `editar_empresa.php?id=${idEmpresa}`;
                title.innerHTML = '<i class="fas fa-edit"></i> Editar empresa';
            } else {
                frame.src = 'new_empresa.php';
                title.innerHTML = '<i class="fas fa-plus"></i> Nueva empresa';
            }
            modal.style.display = 'flex';
            sendThemeToFrame();
        }

        function openSifenConfig(idEmpresa) {
            const modal = document.getElementById('empresaModal');
            const frame = document.getElementById('empresaModalFrame');
            const title = document.getElementById('empresaModalTitle');

            frame.src = `/admin_empresa/editar_habilitacion_sifen.php?id_empresa=${idEmpresa}`;
            title.innerHTML = '<i class="fas fa-file-invoice text-green-500"></i> Configuración SIFEN';
            modal.style.display = 'flex';
            sendThemeToFrame();
        }

        document.getElementById('closeEmpresaModal').addEventListener('click', () => {
            closeEmpresaModal();
        });

        function closeEmpresaModal() {
            const modal = document.getElementById('empresaModal');
            const frame = document.getElementById('empresaModalFrame');
            frame.src = '';
            modal.style.display = 'none';
            loadData();
        }

        // Función para confirmar eliminación desde menú contextual
        async function confirmarEliminar(empresa) {
            // Primera confirmación
            const result1 = await showConfirm({
                title: '¿Eliminar empresa?',
                message: `
                    <p class="font-medium text-slate-900 dark:text-white">${empresa.empresa}</p>
                    <p class="text-sm text-slate-500 mt-1">RUC: ${empresa.ruc}-${empresa.dv || ''}</p>
                    <p class="text-sm text-slate-500">Base de datos: ${empresa.dbase || 'N/A'}</p>
                    <p class="text-red-600 text-sm mt-3">⚠️ Esta acción no se puede deshacer</p>
                `,
                icon: 'fas fa-exclamation-triangle',
                iconClass: 'bg-red-100 dark:bg-red-900/50 text-red-600',
                confirmText: 'Eliminar',
                confirmIcon: 'fas fa-trash-alt',
                confirmClass: 'bg-red-600 hover:bg-red-700'
            });

            if (!result1.confirmed) return;

            // Segunda confirmación con RUC
            const result2 = await showConfirm({
                title: 'Confirmar eliminación',
                message: `Escribe el RUC <strong>${empresa.ruc}</strong> para confirmar:`,
                icon: 'fas fa-keyboard',
                iconClass: 'bg-amber-100 dark:bg-amber-900/50 text-amber-600',
                confirmText: 'Eliminar definitivamente',
                confirmIcon: 'fas fa-trash-alt',
                confirmClass: 'bg-red-600 hover:bg-red-700',
                showInput: true,
                inputPlaceholder: 'Escribe el RUC',
                expectedInput: empresa.ruc
            });

            if (!result2.confirmed) return;

            try {
                const res = await fetch('editar_empresa.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id_empresa: empresa.id_empresa,
                        confirmacion: result2.value
                    })
                });
                const data = await res.json();

                if (data.success) {
                    showNotif('Eliminada', data.backup_file ? `Backup: ${data.backup_file}` : 'Empresa eliminada correctamente', 'success');
                    loadData();
                } else {
                    showNotif('Error', data.error || 'No se pudo eliminar', 'error');
                }
            } catch (e) {
                showNotif('Error', 'Error de conexión', 'error');
            }
        }

        // Escuchar mensaje del iframe para cerrar modal
        window.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'closeModal') {
                closeEmpresaModal();
            }
        });

        function getCurrentTheme() {
            const stored = localStorage.getItem('theme');
            if (stored) return stored;
            return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        }

        function sendThemeToFrame() {
            const frame = document.getElementById('empresaModalFrame');
            if (!frame || !frame.contentWindow) return;
            frame.contentWindow.postMessage({
                type: 'theme',
                value: getCurrentTheme()
            }, '*');
        }

        window.addEventListener('storage', (e) => {
            if (e.key === 'theme') {
                sendThemeToFrame();
            }
        });

        // Exportaciones
        function exportExcel() {
            gridApi.exportDataAsExcel({
                fileName: 'empresas_' + new Date().toISOString().slice(0, 10) + '.xlsx',
                sheetName: 'Empresas'
            });
        }

        function exportPDF() {
            // AG Grid Enterprise tiene exportación a PDF
            showNotif('Exportar PDF', 'Use Ctrl+P para imprimir a PDF', 'info');
        }

        // Aplicar tema al grid
        function applyGridTheme() {
            const gridDiv = document.querySelector('#gridEmpresas');
            const isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                gridDiv.classList.remove('ag-theme-quartz');
                gridDiv.classList.add('ag-theme-quartz-dark');
            } else {
                gridDiv.classList.remove('ag-theme-quartz-dark');
                gridDiv.classList.add('ag-theme-quartz');
            }
        }

        // Inicializar Feather Icons si está disponible
        if (typeof feather !== 'undefined') {
            feather.replace();
        }

        applyGridTheme();
        loadData();
    </script>
</body>

</html>