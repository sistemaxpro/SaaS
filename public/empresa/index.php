<?php

/**
 * Formulario Único Empresa (Alta/Edición)
 * Alpine.js + Tailwind - Arquitectura v1
 * 
 * Uso:
 *   index.php          → Nueva empresa
 *   index.php?id=123   → Editar empresa 123
 */
require_once __DIR__ . '/../../config/bootstrap.php';

$idEmpresa = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_SESSION['id_empresa'] ?? 0);
if ($idEmpresa <= 0 && !empty($_SESSION['id_login'])) {
    try {
        $pdoResolveEmpresa = Database::getMasterConnection();
        $stmtResolveEmpresa = $pdoResolveEmpresa->prepare("SELECT id_empresa FROM " . MASTER_DB . ".sec_users WHERE id_login = ? LIMIT 1");
        $stmtResolveEmpresa->execute([(int)$_SESSION['id_login']]);
        $idEmpresa = (int)($stmtResolveEmpresa->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $idEmpresa = $idEmpresa > 0 ? $idEmpresa : 0;
    }
}
$isEdit = $idEmpresa > 0;
$tiposNegocioOptions = [
    ['nombre' => 'Comercial', 'disponible' => 1],
    ['nombre' => 'Estación de Servicio', 'disponible' => 1],
    ['nombre' => 'Taller Mecánico', 'disponible' => 1],
    ['nombre' => 'Gastronomía', 'disponible' => 1],
    ['nombre' => 'Farmacia', 'disponible' => 1],
    ['nombre' => 'Ferretería', 'disponible' => 1],
    ['nombre' => 'Supermercado', 'disponible' => 1],
    ['nombre' => 'Distribuidora', 'disponible' => 1],
    ['nombre' => 'Servicios', 'disponible' => 1],
    ['nombre' => 'E-commerce', 'disponible' => 1],
];
$monedasOptions = [
    ['id_moneda' => 1, 'nombre' => 'Guarani', 'codigo_iso' => 'PYG'],
    ['id_moneda' => 2, 'nombre' => 'Dolar Americano', 'codigo_iso' => 'USD'],
    ['id_moneda' => 3, 'nombre' => 'Real Brasileno', 'codigo_iso' => 'BRL'],
];

try {
    $pdoTipos = Database::getMasterConnection();
    $hasDisponible = false;
    try {
        $stCol = $pdoTipos->query("SHOW COLUMNS FROM tipo_negocio LIKE 'disponible'");
        $hasDisponible = (bool)($stCol && $stCol->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $hasDisponible = false;
    }
    $stmtTipos = $pdoTipos->query("
        SELECT nombre, " . ($hasDisponible ? "COALESCE(disponible, 1)" : "1") . " AS disponible
        FROM tipo_negocio
        WHERE activo = 1
        ORDER BY orden ASC, nombre ASC
    ");
    $rowsTipos = $stmtTipos ? $stmtTipos->fetchAll(PDO::FETCH_ASSOC) : [];
    $itemsTipos = [];
    foreach ($rowsTipos as $rowTipo) {
        $nombre = trim((string)($rowTipo['nombre'] ?? ''));
        if ($nombre === '') continue;
        $itemsTipos[] = [
            'nombre' => $nombre,
            'disponible' => (int)($rowTipo['disponible'] ?? 1) === 1 ? 1 : 0,
        ];
    }
    if (!empty($itemsTipos)) {
        $tiposNegocioOptions = $itemsTipos;
    }
} catch (Throwable $e) {
    // Fallback local.
}

try {
    $pdoMonedas = Database::getMasterConnection();
    $stmtMonedas = $pdoMonedas->query("
        SELECT id_moneda, nombre, codigo_iso
        FROM moneda_sifen
        WHERE id_moneda IS NOT NULL
        ORDER BY id_moneda ASC
    ");
    $rowsMonedas = $stmtMonedas ? $stmtMonedas->fetchAll(PDO::FETCH_ASSOC) : [];
    $itemsMonedas = [];
    foreach ($rowsMonedas as $rowMoneda) {
        $idMoneda = (int)($rowMoneda['id_moneda'] ?? 0);
        $nombre = trim((string)($rowMoneda['nombre'] ?? ''));
        $codigoIso = strtoupper(trim((string)($rowMoneda['codigo_iso'] ?? '')));
        if ($idMoneda <= 0 || $nombre === '') continue;
        $itemsMonedas[] = [
            'id_moneda' => $idMoneda,
            'nombre' => $nombre,
            'codigo_iso' => $codigoIso,
        ];
    }
    if (!empty($itemsMonedas)) {
        $monedasOptions = $itemsMonedas;
    }
} catch (Throwable $e) {
    // Fallback local.
}

$empresaContext = [
    'id_empresa' => $idEmpresa,
    'empresa' => (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ''),
    'empresa_nombre' => (string)($_SESSION['empresa_nombre'] ?? $_SESSION['empresa'] ?? ''),
    'ruc' => (string)($_SESSION['ruc'] ?? ''),
    'dv' => (string)($_SESSION['dv'] ?? ''),
    'server' => (string)($_SESSION['server'] ?? ''),
    'user' => (string)($_SESSION['user'] ?? ''),
    'puerto' => (int)($_SESSION['puerto'] ?? (defined('SISTEMAX_IS_DEV') && SISTEMAX_IS_DEV ? 3307 : 3306)),
    'database' => (string)($_SESSION['database'] ?? ''),
    'dbase' => (string)($_SESSION['dbu'] ?? ''),
    'establecimiento' => (string)($_SESSION['establecimiento'] ?? ''),
    'punto_expedicion' => (string)($_SESSION['punto_expedicion'] ?? ''),
    'id_sucursal' => (int)($_SESSION['id_sucursal'] ?? 0),
    'sucursal' => (string)($_SESSION['sucursal'] ?? ''),
    'id_caja_def' => (int)($_SESSION['id_caja_def'] ?? 0),
    'caja_def' => (int)($_SESSION['caja_def'] ?? ($_SESSION['id_caja_def'] ?? 0)),
    'caja' => (string)($_SESSION['caja'] ?? ''),
    'moneda_principal' => (int)($_SESSION['moneda_principal'] ?? 1),
];

try {
    if ($idEmpresa > 0) {
        $empresaDb = Database::getEmpresaInfo($idEmpresa);
        if (is_array($empresaDb)) {
            foreach (['empresa', 'empresa_nombre', 'ruc', 'dv', 'user', 'database', 'dbase', 'establecimiento', 'punto_expedicion', 'moneda_principal'] as $key) {
                if (isset($empresaDb[$key]) && $empresaDb[$key] !== null && trim((string)$empresaDb[$key]) !== '') {
                    $empresaContext[$key] = $empresaDb[$key];
                }
            }
            $serverDb = trim((string)($empresaDb['server'] ?? ''));
            if ($serverDb !== '') {
                $empresaContext['server'] = in_array(strtolower($serverDb), ['168.231.95.50', '127.0.0.1', 'localhost'], true) ? 'localhost' : $serverDb;
            } elseif (empty($empresaContext['server'])) {
                $empresaContext['server'] = 'localhost';
            }
            if (!empty($empresaDb['puerto'])) {
                $empresaContext['puerto'] = (int)$empresaDb['puerto'];
            }
            if (!empty($empresaDb['empresa'])) {
                $empresaContext['empresa'] = (string)$empresaDb['empresa'];
            }
            if (!empty($empresaDb['empresa_nombre'])) {
                $empresaContext['empresa_nombre'] = (string)$empresaDb['empresa_nombre'];
            } elseif (!empty($empresaContext['empresa'])) {
                $empresaContext['empresa_nombre'] = $empresaContext['empresa'];
            }
            if (!empty($empresaDb['dbase'])) {
                $empresaContext['dbase'] = (string)$empresaDb['dbase'];
            }
        }
    }
} catch (Throwable $e) {
    // Mantener valores de sesión.
}

try {
    $idLoginCtx = (int)($_SESSION['id_login'] ?? 0);
    if ($idLoginCtx > 0) {
        $pdoMasterCtx = Database::getMasterConnection();
        $stmtUserCtx = $pdoMasterCtx->prepare("
            SELECT id_login, login, name, email, phone, id_empresa, id_sucursal, id_caja, caja_def
            FROM " . MASTER_DB . ".sec_users
            WHERE id_login = ?
            LIMIT 1
        ");
        $stmtUserCtx->execute([$idLoginCtx]);
        $userCtx = $stmtUserCtx->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($userCtx)) {
            if (empty($empresaContext['id_empresa']) && !empty($userCtx['id_empresa'])) {
                $empresaContext['id_empresa'] = (int)$userCtx['id_empresa'];
            }
            if (empty($empresaContext['id_sucursal']) && !empty($userCtx['id_sucursal'])) {
                $empresaContext['id_sucursal'] = (int)$userCtx['id_sucursal'];
            }
            if (empty($empresaContext['id_caja_def'])) {
                $empresaContext['id_caja_def'] = (int)($userCtx['id_caja'] ?? $userCtx['caja_def'] ?? 0);
                $empresaContext['caja_def'] = $empresaContext['id_caja_def'];
            }
        }
    }
} catch (Throwable $e) {
    // Mantener valores de sesión.
}

try {
    if ($idEmpresa > 0 && ((int)($empresaContext['id_sucursal'] ?? 0) > 0 || (int)($empresaContext['id_caja_def'] ?? 0) > 0)) {
        $pdoEmpresaCtx = Database::getSessionEmpresaConnection();
        if (empty($empresaContext['sucursal']) && (int)$empresaContext['id_sucursal'] > 0) {
            try {
                $stmtSucCtx = $pdoEmpresaCtx->prepare("SELECT * FROM sucursales WHERE id_sucursal = ? LIMIT 1");
                $stmtSucCtx->execute([(int)$empresaContext['id_sucursal']]);
                $rowSuc = $stmtSucCtx->fetch(PDO::FETCH_ASSOC) ?: [];
                $empresaContext['sucursal'] = trim((string)($rowSuc['nombre'] ?? $rowSuc['sucursal'] ?? $rowSuc['NOMBRE'] ?? ''));
                if ($empresaContext['sucursal'] === '') {
                    $empresaContext['sucursal'] = 'Sucursal #' . (int)$empresaContext['id_sucursal'];
                }
            } catch (Throwable $e) {
                try {
                    $stmtSucCtx = $pdoEmpresaCtx->prepare("SELECT * FROM sucursal WHERE id_sucursal = ? LIMIT 1");
                    $stmtSucCtx->execute([(int)$empresaContext['id_sucursal']]);
                    $rowSuc = $stmtSucCtx->fetch(PDO::FETCH_ASSOC) ?: [];
                    $empresaContext['sucursal'] = trim((string)($rowSuc['nombre'] ?? $rowSuc['sucursal'] ?? $rowSuc['NOMBRE'] ?? ''));
                    if ($empresaContext['sucursal'] === '') {
                        $empresaContext['sucursal'] = 'Sucursal #' . (int)$empresaContext['id_sucursal'];
                    }
                } catch (Throwable $e2) {
                    $empresaContext['sucursal'] = $empresaContext['sucursal'] ?: ('Sucursal #' . (int)$empresaContext['id_sucursal']);
                }
            }
        }

        if ((empty($empresaContext['caja']) || $empresaContext['caja'] === 'N/D') && (int)$empresaContext['id_caja_def'] > 0) {
            try {
                $stmtCajaCtx = $pdoEmpresaCtx->prepare("SELECT * FROM cajas WHERE id_caja = ? LIMIT 1");
                $stmtCajaCtx->execute([(int)$empresaContext['id_caja_def']]);
                $rowCaja = $stmtCajaCtx->fetch(PDO::FETCH_ASSOC) ?: [];
                $empresaContext['caja'] = trim((string)($rowCaja['caja'] ?? $rowCaja['nombre'] ?? ''));
                if ($empresaContext['caja'] === '') {
                    $empresaContext['caja'] = 'Caja #' . (int)$empresaContext['id_caja_def'];
                }
            } catch (Throwable $e) {
                $empresaContext['caja'] = $empresaContext['caja'] ?: ('Caja #' . (int)$empresaContext['id_caja_def']);
            }
        }
    }
} catch (Throwable $e) {
    // Mantener valores de sesión.
}
?>
<!DOCTYPE html>
<html lang="es" x-data="empresaForm()" class="h-full">

<head>
    <meta charset="utf-8">
    <title><?php echo $isEdit ? 'Editar Empresa' : 'Nueva Empresa'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#1e293b' }
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        (function() {
            function applyTheme(mode) {
                document.documentElement.classList.toggle('dark', mode === 'dark');
            }
            const stored = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            applyTheme(stored || (systemDark ? 'dark' : 'light'));

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (!localStorage.getItem('theme')) applyTheme(e.matches ? 'dark' : 'light');
            });

            window.addEventListener('message', (event) => {
                if (event.data && event.data.type === 'theme') applyTheme(event.data.value);
            });
        })();
    </script>
    <style>
        [x-cloak] { display: none !important; }
        html { font-size: 12px; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 12px;
            font-weight: 300;
            line-height: 1.35;
        }
        body, body * {
            font-weight: 300 !important;
        }
        body :where(i.fa, i.fas, i.far, i.fal, i.fab, i.fad, [class^="fa-"], [class*=" fa-"]) {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands" !important;
        }
        body :where(.fa-solid, .fas, .fa-classic) {
            font-weight: 900 !important;
        }
        body :where(.fa-regular, .far, .fa-brands, .fab) {
            font-weight: 400 !important;
        }
        body :where(.fa-light, .fal, .fa-thin) {
            font-weight: 300 !important;
        }
        body :where(.text-xs, .text-sm, .text-base) {
            font-size: 12px !important;
        }
        body :where(.text-lg, .text-xl, .text-2xl) {
            line-height: 1.2;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.4); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.6); }
        .dark ::-webkit-scrollbar-thumb { background: rgba(71, 85, 105, 0.5); }

        .fade-in { animation: fadeIn 0.2s ease-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .btn-chip {
            padding: 8px 16px; border-radius: 10px; font-weight: 400; cursor: pointer;
            border: 1px solid #3b82f6; display: inline-flex; align-items: center; gap: 8px;
            background: transparent; color: #2d7be5; box-shadow: none;
            transition: all 0.2s; font-size: 13px;
        }
        .btn-chip:hover { background: rgba(59, 130, 246, 0.05); transform: translateY(-1px); }
        .btn-chip--primary { border-color: #3b82f6; color: #3b82f6; }
        .btn-chip--primary:hover { background: rgba(59, 130, 246, 0.08); }
        .btn-chip--success { border-color: #22c55e; color: #22c55e; }
        .btn-chip--success:hover { background: rgba(34, 197, 94, 0.08); }
        .btn-chip--danger { border-color: #ef4444; color: #ef4444; }
        .btn-chip--danger:hover { background: rgba(239, 68, 68, 0.08); }
        .btn-chip--warning { border-color: #f59e0b; color: #f59e0b; }
        .btn-chip--warning:hover { background: rgba(245, 158, 11, 0.08); }
        .logo-hold-ring { animation: logoHoldPulse .8s ease-in-out infinite; }
        @keyframes logoHoldPulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(59,130,246,.25); }
            50% { box-shadow: 0 0 0 10px rgba(59,130,246,0); }
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800 h-full">
    <input x-ref="logoFileInput" type="file" accept="image/*" class="hidden" @change="handleLogoFileSelected($event)">
    <input x-ref="logoCameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="handleLogoFileSelected($event)">
    <!-- Modal Notificación -->
    <div x-data="notifModal()" x-show="show" x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @keydown.escape.window="close()">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden fade-in"
            @click.outside="close()">
            <div class="p-8 text-center">
                <div class="mx-auto mb-5 w-16 h-16 rounded-full flex items-center justify-center"
                    :class="{
                        'bg-emerald-100 dark:bg-emerald-900/40': type === 'success',
                        'bg-red-100 dark:bg-red-900/40': type === 'error',
                        'bg-amber-100 dark:bg-amber-900/40': type === 'warning',
                        'bg-blue-100 dark:bg-blue-900/40': type === 'info'
                    }">
                    <i x-show="type === 'success'" class="fas fa-check text-2xl text-emerald-600 dark:text-emerald-400"></i>
                    <i x-show="type === 'error'" class="fas fa-times text-2xl text-red-600 dark:text-red-400"></i>
                    <i x-show="type === 'warning'" class="fas fa-exclamation text-2xl text-amber-600 dark:text-amber-400"></i>
                    <i x-show="type === 'info'" class="fas fa-info text-2xl text-blue-600 dark:text-blue-400"></i>
                </div>
                <h3 class="text-xl font-semibold text-slate-800 dark:text-white mb-2" x-text="title"></h3>
                <p class="text-slate-500 dark:text-slate-400" x-text="message"></p>
            </div>
            <div class="px-8 pb-8">
                <button @click="close()" class="w-full py-3 rounded-xl font-medium text-white transition-all duration-200 transform hover:scale-[1.02] active:scale-[0.98]"
                    :class="{
                        'bg-emerald-500 hover:bg-emerald-600': type === 'success',
                        'bg-red-500 hover:bg-red-600': type === 'error',
                        'bg-amber-500 hover:bg-amber-600': type === 'warning',
                        'bg-blue-500 hover:bg-blue-600': type === 'info'
                    }">Aceptar</button>
            </div>
        </div>
    </div>

    <!-- Modal Progreso Sincronización -->
    <div x-data="progressModal()" x-show="show" x-cloak id="progressModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @show-progress.window="open($event.detail)"
        @update-progress.window="update($event.detail)"
        @finish-progress.window="finish($event.detail)">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-3xl mx-4 overflow-hidden fade-in">
            <div class="p-6">
                <!-- Header -->
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center"
                        :class="finished ? 'bg-emerald-100 dark:bg-emerald-900/40' : 'bg-blue-100 dark:bg-blue-900/40'">
                        <i x-show="!finished" class="fas fa-database text-xl text-blue-600 dark:text-blue-400 animate-pulse"></i>
                        <i x-show="finished && !hasErrors" class="fas fa-check text-xl text-emerald-600 dark:text-emerald-400"></i>
                        <i x-show="finished && hasErrors" class="fas fa-exclamation-triangle text-xl text-amber-600 dark:text-amber-400"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800 dark:text-white" x-text="title"></h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400 break-all" x-text="subtitle"></p>
                    </div>
                </div>

                <!-- Barra de progreso -->
                <div class="mb-4" x-show="!finished">
                    <div class="flex justify-between text-xs text-slate-500 dark:text-slate-400 mb-1">
                        <span x-text="currentStep"></span>
                        <span x-text="progress + '%'"></span>
                    </div>
                    <div class="h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-blue-500 to-blue-600 rounded-full transition-all duration-300"
                            :style="'width: ' + progress + '%'"></div>
                    </div>
                </div>

                <!-- Log de pasos -->
                <div class="max-h-48 overflow-y-auto mb-4 bg-slate-50 dark:bg-slate-900/50 rounded-lg p-3 text-sm font-mono">
                    <template x-for="(log, index) in logs" :key="index">
                        <div class="flex items-start gap-2 py-1"
                            :class="log.type === 'error' ? 'text-red-500' : log.type === 'success' ? 'text-emerald-500' : log.type === 'warning' ? 'text-amber-500' : 'text-slate-600 dark:text-slate-400'">
                            <i :class="{
                                'fas fa-check-circle': log.type === 'success',
                                'fas fa-times-circle': log.type === 'error',
                                'fas fa-spinner fa-spin': log.type === 'loading',
                                'fas fa-exclamation-triangle': log.type === 'warning',
                                'fas fa-info-circle': log.type === 'info'
                            }" class="mt-0.5 text-xs"></i>
                            <span x-text="log.message" class="text-xs"></span>
                        </div>
                    </template>
                </div>

                <!-- Resumen final -->
                <div x-show="finished" class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 mb-4">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="text-center p-2 bg-white dark:bg-slate-800 rounded-lg">
                            <div class="text-2xl font-bold text-blue-600" x-text="tablesCreated"></div>
                            <div class="text-xs text-slate-500">Tablas creadas</div>
                        </div>
                        <div class="text-center p-2 bg-white dark:bg-slate-800 rounded-lg">
                            <div class="text-2xl font-bold text-emerald-600" x-text="columnsAdded"></div>
                            <div class="text-xs text-slate-500">Columnas agregadas</div>
                        </div>
                    </div>
                    <div x-show="errorsCount > 0" class="mt-3 text-center text-amber-600 dark:text-amber-400 text-sm">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <span x-text="errorsCount + ' errores durante el proceso'"></span>
                    </div>
                </div>

                <!-- Botón Aceptar -->
                <button x-show="finished" @click="close()"
                    class="w-full py-3 rounded-xl font-medium text-white transition-all duration-200 transform hover:scale-[1.02] active:scale-[0.98]"
                    :class="hasErrors ? 'bg-amber-500 hover:bg-amber-600' : 'bg-emerald-500 hover:bg-emerald-600'">
                    <i class="fas fa-check mr-2"></i>Aceptar
                </button>
            </div>
        </div>
    </div>

    <div class="w-full h-full p-4 overflow-auto">
        <div class="w-full h-full">
            <!-- Card principal -->
            <div class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl shadow-xl shadow-slate-200/50 dark:shadow-slate-900/50 rounded-2xl border border-slate-200/50 dark:border-slate-700/50 overflow-hidden h-full flex flex-col">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-slate-200/50 dark:border-slate-700/50 bg-gradient-to-r from-slate-50 to-transparent dark:from-slate-800/50 flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <button class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                            @click="cerrarModal()" title="Salir">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                            </svg>
                        </button>
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/30">
                            <i class="fas fa-building text-white"></i>
                        </div>
                        <div>
                            <h1 class="text-lg font-bold text-slate-800 dark:text-white" x-text="isEdit ? 'Editar Empresa' : 'Nueva Empresa'"></h1>
                            <p class="text-xs text-slate-500 dark:text-slate-400" x-show="isEdit" x-text="'ID: ' + form.id_empresa"></p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button class="btn-chip" @click="resetForm()">
                            <i class="fas fa-undo"></i>Restaurar
                        </button>
                        <button class="btn-chip btn-chip--success" @click="guardar()" :disabled="loading">
                            <i class="fas fa-save" x-show="!loading"></i>
                            <i class="fas fa-spinner fa-spin" x-show="loading"></i>
                            <span x-text="loading ? 'Guardando...' : 'Guardar'"></span>
                        </button>
                        <button x-show="isEdit" class="btn-chip btn-chip--danger" @click="confirmarEliminar()">
                            <i class="fas fa-trash-alt"></i>Eliminar
                        </button>
                    </div>
                </div>

                <div class="p-6 space-y-6 overflow-y-auto flex-1">
                    <!-- Sección: Datos Principales -->
                    <section class="fade-in">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-7 h-7 rounded-lg bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                                <i class="fas fa-id-card text-blue-600 dark:text-blue-400 text-xs"></i>
                            </div>
                            <h2 class="text-base font-semibold text-slate-800 dark:text-white">Datos Principales</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                            <div class="md:col-span-3 relative">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1.5">RUC <span class="text-red-500">*</span></label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.ruc"
                                    @input.debounce.600ms="buscarRucSifen()"
                                    @blur="buscarRucSifen()"
                                    placeholder="80012345"
                                    x-init="$nextTick(() => { if (!isEdit) $el.focus(); })">
                                <div x-show="loadingRuc" class="absolute inset-0 flex items-center justify-center bg-white/90 dark:bg-slate-900/90 rounded-xl mt-8">
                                    <span class="text-sm text-blue-600 dark:text-blue-400 font-medium flex items-center gap-2">
                                        <i class="fas fa-circle-notch fa-spin"></i> Consultando SET...
                                    </span>
                                </div>
                            </div>
                            <div class="md:col-span-1">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">DV <span class="text-red-500">*</span></label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white text-center font-bold text-lg focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.dv" maxlength="1">
                            </div>
                            <div class="md:col-span-5">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Razón Social <span class="text-red-500">*</span></label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.empresa"
                                    placeholder="Nombre de la empresa">
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Tipo de negocio</label>
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none cursor-pointer"
                                    x-model="form.rubro">
                                    <template x-for="tipo in tiposNegocioDisponibles" :key="tipo.nombre">
                                        <option :value="tipo.nombre" x-text="tipo.nombre"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Nombre Fantasía</label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.nombre_fantasia"
                                    placeholder="Nombre comercial">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Estado</label>
                                <div class="flex gap-4 mt-3">
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" x-model="form.activo" value="1" class="w-5 h-5 text-emerald-500 border-2 border-slate-300 focus:ring-emerald-500">
                                        <span class="flex items-center gap-2 text-slate-700 dark:text-slate-300 group-hover:text-emerald-600 transition">
                                            <i class="fas fa-check-circle text-emerald-500"></i> Activo
                                        </span>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" x-model="form.activo" value="0" class="w-5 h-5 text-red-500 border-2 border-slate-300 focus:ring-red-500">
                                        <span class="flex items-center gap-2 text-slate-700 dark:text-slate-300 group-hover:text-red-600 transition">
                                            <i class="fas fa-times-circle text-red-500"></i> Inactivo
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Moneda principal del sistema</label>
                            <select x-model.number="form.moneda_principal"
                                :disabled="monedaPrincipalBloqueada()"
                                :class="monedaPrincipalBloqueada() ? 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 cursor-not-allowed' : 'bg-white dark:bg-slate-900 text-slate-800 dark:text-white'"
                                class="w-full md:w-1/2 px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-600 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none">
                                <template x-for="moneda in monedasDisponibles" :key="'moneda-' + moneda.id_moneda">
                                    <option :value="moneda.id_moneda" x-text="`${moneda.nombre} (${moneda.codigo_iso})`"></option>
                                </template>
                            </select>
                            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1" x-show="!monedaPrincipalBloqueada()">
                                Define la moneda base de todas las operaciones del sistema. El tipo de negocio y la moneda se hidratan desde la configuracion habilitada. Por defecto inicia en Guaranies.
                            </p>
                            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1" x-show="monedaPrincipalBloqueada()">
                                La moneda principal ya fue configurada como <span class="font-medium" x-text="monedaPrincipalActual() ? `${monedaPrincipalActual().nombre} (${monedaPrincipalActual().codigo_iso})` : 'Guaranies (PYG)'"></span> y no se puede cambiar.
                            </p>
                        </div>
                    </section>

                    <div class="border-t border-slate-200 dark:border-slate-700"></div>

                    <!-- Sección: Contacto -->
                    <section class="fade-in">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                                <i class="fas fa-phone text-emerald-600 dark:text-emerald-400 text-sm"></i>
                            </div>
                            <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Contacto</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Teléfono Principal</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-phone"></i></span>
                                    <input type="text"
                                        class="w-full pl-11 pr-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.telefono"
                                        placeholder="0981 123 456">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Teléfono Secundario</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-phone"></i></span>
                                    <input type="text"
                                        class="w-full pl-11 pr-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.telefono2"
                                        placeholder="021 123 456">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Email Principal</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-envelope"></i></span>
                                    <input type="email"
                                        class="w-full pl-11 pr-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.email"
                                        placeholder="contacto@empresa.com">
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="border-t border-slate-200 dark:border-slate-700"></div>

                    <!-- Sección: Ubicación -->
                    <section class="fade-in">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center">
                                <i class="fas fa-map-marker-alt text-purple-600 dark:text-purple-400 text-sm"></i>
                            </div>
                            <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Ubicación</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                            <div class="md:col-span-6">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Dirección</label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.direccion"
                                    placeholder="Av. Principal c/ Calle Secundaria">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Nro. Casa</label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.numero_casa"
                                    placeholder="123">
                            </div>
                            <div class="md:col-span-4">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Ciudad</label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.ciudad"
                                    placeholder="Asunción">
                            </div>
                        </div>
                    </section>

                    <div class="border-t border-slate-200 dark:border-slate-700"></div>

                    <!-- Sección: Tipo de Factura -->
                    <section class="fade-in">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                                <i class="fas fa-file-invoice text-amber-600 dark:text-amber-400 text-sm"></i>
                            </div>
                            <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Tipo de Factura</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Factura habilitada</label>
                                <div class="flex gap-4 mt-3">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" x-model="form.factura_electronica" value="1" class="w-5 h-5 text-blue-500">
                                        <span class="text-slate-700 dark:text-slate-300">Electrónica</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" x-model="form.factura_electronica" value="0" class="w-5 h-5 text-blue-500">
                                        <span class="text-slate-700 dark:text-slate-300">Autoimpresa</span>
                                    </label>
                                </div>
                            </div>
                            <div x-show="Number(form.factura_electronica) === 1" x-transition>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Timbrado</label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.timbrado"
                                    placeholder="12345678">
                            </div>
                        </div>
                        <div x-show="Number(form.factura_electronica) === 0" x-transition class="mt-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Timbrado (Autoimpresa)</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.timbrado"
                                        placeholder="12345678">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Vencimiento (Autoimpresa)</label>
                                    <input type="date"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.vigencia_fin">
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="border-t border-slate-200 dark:border-slate-700"></div>

                    <!-- Sección: Sistema (colapsable) -->
                    <section class="fade-in" x-data="{ open: false }">
                        <button @click="open = !open" class="w-full flex items-center justify-between group">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center">
                                    <i class="fas fa-cog text-slate-600 dark:text-slate-400 text-sm"></i>
                                </div>
                                <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Sistema & Configuración</h2>
                            </div>
                            <i class="fas fa-chevron-down text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                        </button>
                        <div x-show="open" x-collapse class="mt-6">
                            <div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-900/40 p-4 mb-4">
                                <div class="flex items-center justify-between gap-3 mb-4">
                                    <div>
                                        <h3 class="text-sm font-semibold text-slate-800 dark:text-white">Información de conexión</h3>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">Datos base de la empresa y acceso a su entorno.</p>
                                    </div>
                                    <span class="text-[11px] font-medium px-2.5 py-1 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">Solo lectura</span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950/40 p-3">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">DB Host</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-white break-all" x-text="form.server || 'N/D'"></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950/40 p-3">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">Puerto</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-white break-all" x-text="form.puerto ? String(form.puerto) : (window.__SISTEMAX_ENV__ === 'dev' ? '3307' : '3306')"></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950/40 p-3">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">DBase</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-white break-all" x-text="form.dbase || 'N/D'"></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950/40 p-3">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">Empresa</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-white break-all" x-text="form.empresa || 'N/D'"></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950/40 p-3">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">Sucursal</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-white break-all" x-text="form.sucursal || form.establecimiento || 'N/D'"></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950/40 p-3">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">Usuario</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-white break-all" x-text="form.user || 'N/D'"></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950/40 p-3">
                                        <p class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">Caja</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-white break-all" x-text="form.caja_principal || 'N/D'"></p>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Base de Datos</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.dbase"
                                        placeholder="nombre_base_datos">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Logo</label>
                                    <div class="flex items-center gap-4">
                                        <div class="flex-shrink-0">
                                            <template x-if="logoPreview || form.logos">
                                                <button type="button"
                                                    @mousedown="startLogoLongPress()"
                                                    @mouseup="cancelLogoLongPress()"
                                                    @mouseleave="cancelLogoLongPress()"
                                                    @touchstart="startLogoLongPress()"
                                                    @touchend="cancelLogoLongPress()"
                                                    @touchcancel="cancelLogoLongPress()"
                                                    @contextmenu.prevent="openLogoCaptureModal()"
                                                    class="block">
                                                    <img :src="logoPreview || ('/public/_lib/file/empresa/' + form.logos)"
                                                        class="w-[120px] h-[120px] object-contain rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-800"
                                                        :class="logoLongPressing ? 'ring-2 ring-blue-500/70' : ''"
                                                        alt="Logo preview">
                                                </button>
                                            </template>
                                            <template x-if="!logoPreview && !form.logos">
                                                <button type="button"
                                                    @mousedown="startLogoLongPress()"
                                                    @mouseup="cancelLogoLongPress()"
                                                    @mouseleave="cancelLogoLongPress()"
                                                    @touchstart="startLogoLongPress()"
                                                    @touchend="cancelLogoLongPress()"
                                                    @touchcancel="cancelLogoLongPress()"
                                                    @contextmenu.prevent="openLogoCaptureModal()"
                                                    class="block">
                                                <div class="w-[120px] h-[120px] rounded-lg border-2 border-dashed border-slate-300 dark:border-slate-600 flex items-center justify-center bg-slate-50 dark:bg-slate-800"
                                                     :class="logoLongPressing ? 'ring-2 ring-blue-500/70' : ''">
                                                    <i class="fas fa-image text-3xl text-slate-300 dark:text-slate-600"></i>
                                                </div>
                                                </button>
                                            </template>
                                        </div>
                                        <div class="flex-1 space-y-2">
                                            <input type="text"
                                                class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 text-sm"
                                                x-model="form.logos"
                                                placeholder="Nombre del archivo"
                                                readonly>
                                            <label class="flex items-center justify-center gap-2 px-4 py-2 rounded-lg border border-blue-500 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors">
                                                <i class="fas fa-upload"></i>
                                                <span class="text-sm font-medium">Subir Logo</span>
                                                <input type="file" class="hidden" accept="image/*" @change="subirLogo($event)">
                                            </label>
                                            <button type="button" @click="openLogoCaptureModal()" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors text-sm font-medium">
                                                Capturar / buscar logo
                                            </button>
                                            <p class="text-xs text-slate-400">PNG, JPG o GIF. Máx 2MB</p>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">WooCommerce</label>
                                    <select class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none cursor-pointer"
                                        x-model="form.web">
                                        <option value="0">Deshabilitado</option>
                                        <option value="1">Habilitado</option>
                                    </select>
                                </div>
                            </div>
                            <div x-show="form.web == 1" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">URL Tienda</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.web_url"
                                        placeholder="https://mitienda.com">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Consumer Key</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-mono text-sm focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.web_ck">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Consumer Secret</label>
                                    <input type="password"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-mono text-sm focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.web_cs">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-3">Desactivar Video de Fondo</label>
                                <div class="flex items-center gap-4">
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" :value="0" x-model.number="form.disable_background_video" class="w-5 h-5 text-blue-500 border-2 border-slate-300 focus:ring-blue-500">
                                        <span class="text-sm text-slate-700 dark:text-slate-300 group-hover:text-blue-600 transition">Permitir video</span>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" :value="1" x-model.number="form.disable_background_video" class="w-5 h-5 text-amber-500 border-2 border-slate-300 focus:ring-amber-500">
                                        <span class="text-sm text-slate-700 dark:text-slate-300 group-hover:text-amber-600 transition">Desactivar video</span>
                                    </label>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Desactiva el video de fondo en la página de login y otros módulos que lo utilicen.</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showLogoCaptureModal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4" @keydown.escape.window="closeLogoCaptureModal()">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeLogoCaptureModal()"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto fade-in">
            <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl z-10">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Capturar Logo</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400" x-text="form.empresa || form.nombre_fantasia || ('Empresa #' + (form.id_empresa || 'nueva'))"></p>
                </div>
                <button @click="closeLogoCaptureModal()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="px-6 py-5 space-y-5">
                <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-5">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-40 h-40 rounded-3xl overflow-hidden border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-900 flex items-center justify-center">
                            <template x-if="logoCapturePreview">
                                <img :src="logoCapturePreview" alt="preview logo" class="w-full h-full object-contain bg-white dark:bg-slate-950">
                            </template>
                            <template x-if="!logoCapturePreview">
                                <div class="text-center px-4">
                                    <i class="fas fa-building text-5xl text-slate-300 dark:text-slate-600"></i>
                                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Sin previsualización</p>
                                </div>
                            </template>
                        </div>
                        <button x-show="logoCapturePreview" @click="clearLogoCaptureDraft()" class="text-xs font-semibold text-red-600 dark:text-red-400 hover:underline">Limpiar borrador</button>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <button @click="openLogoGoogleSearch()" class="px-4 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold flex items-center justify-center gap-2">
                                <i class="fab fa-google"></i> Google Imagen
                            </button>
                            <button @click="fetchPixabayLogoSuggestion()" class="px-4 py-3 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-sm font-semibold flex items-center justify-center gap-2 disabled:opacity-50"
                                :disabled="logoPixabayLoading">
                                <i class="fas" :class="logoPixabayLoading ? 'fa-spinner fa-spin' : 'fa-image'"></i> Pixabay
                            </button>
                            <button @click="$refs.logoCameraInput.click()" class="px-4 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold flex items-center justify-center gap-2">
                                <i class="fas fa-camera"></i> Cámara
                            </button>
                            <button @click="$refs.logoFileInput.click()" class="px-4 py-3 rounded-xl bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold flex items-center justify-center gap-2 sm:col-span-3">
                                <i class="fas fa-folder-open"></i> Seleccionar archivo
                            </button>
                        </div>

                        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4 bg-slate-50 dark:bg-slate-900/60">
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">Pegar URL de imagen</label>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input x-model="logoCaptureUrl" type="text" placeholder="https://..." class="flex-1 px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm">
                                <button @click="previewLogoFromUrl()" class="px-4 py-2.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:hover:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 text-sm font-semibold">
                                    Previsualizar
                                </button>
                            </div>
                            <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">El sistema conserva un solo logo por empresa. La última captura reemplaza la anterior.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 px-6 py-4 flex items-center justify-end gap-3 rounded-b-2xl">
                <button @click="closeLogoCaptureModal()" class="px-4 py-2.5 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-sm font-medium hover:bg-slate-300 dark:hover:bg-slate-600 transition-colors">Cancelar</button>
                <button @click="saveLogoCapture()" :disabled="logoCaptureSaving || (!logoCaptureFile && !logoCaptureUrl.trim())"
                    class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition-colors disabled:opacity-50 flex items-center gap-2">
                    <i class="fas" :class="logoCaptureSaving ? 'fa-spinner fa-spin' : 'fa-building'"></i>
                    <span x-text="logoCaptureSaving ? 'Guardando...' : 'Guardar logo'"></span>
                </button>
            </div>
        </div>
    </div>

    <script>
        function notifModal() {
            return {
                show: false,
                title: '',
                message: '',
                type: 'info',
                open(title, message, type = 'info') {
                    this.title = title;
                    this.message = message;
                    this.type = type;
                    this.show = true;
                },
                close() { this.show = false; }
            };
        }

        function progressModal() {
            return {
                show: false, title: '', subtitle: '', currentStep: '', progress: 0,
                logs: [], finished: false, hasErrors: false,
                tablesCreated: 0, columnsAdded: 0, errorsCount: 0,
                open(detail) {
                    this.show = true;
                    this.title = detail.title || 'Procesando...';
                    this.subtitle = detail.subtitle || '';
                    this.currentStep = detail.step || 'Iniciando...';
                    this.progress = 0; this.logs = []; this.finished = false;
                    this.hasErrors = false; this.tablesCreated = 0;
                    this.columnsAdded = 0; this.errorsCount = 0;
                    if (detail.log) this.logs.push({ message: detail.log, type: 'loading' });
                },
                update(detail) {
                    if (detail.step) this.currentStep = detail.step;
                    if (detail.progress !== undefined) this.progress = detail.progress;
                    if (detail.log) {
                        if (this.logs.length > 0 && this.logs[this.logs.length - 1].type === 'loading') {
                            this.logs[this.logs.length - 1] = { message: detail.log, type: detail.logType || 'success' };
                        } else {
                            this.logs.push({ message: detail.log, type: detail.logType || 'info' });
                        }
                    }
                    if (detail.addLog) {
                        this.logs.push({ message: detail.addLog, type: detail.logType || 'loading' });
                    }
                },
                finish(detail) {
                    this.finished = true; this.progress = 100; this.currentStep = 'Completado';
                    this.tablesCreated = detail.tablesCreated || 0;
                    this.columnsAdded = detail.columnsAdded || 0;
                    this.errorsCount = detail.errorsCount || 0;
                    this.hasErrors = this.errorsCount > 0;
                    if (detail.log) this.logs.push({ message: detail.log, type: detail.logType || 'success' });
                },
                close() { this.show = false; }
            };
        }

        window.showProgress = (detail) => window.dispatchEvent(new CustomEvent('show-progress', { detail }));
        window.updateProgress = (detail) => window.dispatchEvent(new CustomEvent('update-progress', { detail }));
        window.finishProgress = (detail) => window.dispatchEvent(new CustomEvent('finish-progress', { detail }));

        window.showNotif = function(title, message, type = 'info') {
            window.dispatchEvent(new CustomEvent('show-notif', { detail: { title, message, type } }));
        };

        document.addEventListener('alpine:init', () => {
            window.addEventListener('show-notif', (e) => {
                const el = document.querySelector('[x-data="notifModal()"]');
                if (el && el.__x) {
                    el.__x.$data.open(e.detail.title, e.detail.message, e.detail.type);
                }
            });
        });

        function empresaForm() {
            const idEmpresa = <?php echo json_encode($idEmpresa); ?>;
            const isEditMode = <?php echo json_encode($isEdit); ?>;
            const empresaContext = <?php echo json_encode($empresaContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const tiposNegocioDisponibles = <?php echo json_encode(array_values(array_map(
                static fn($item) => ['nombre' => trim((string)($item['nombre'] ?? '')), 'disponible' => (int)($item['disponible'] ?? 1)],
                array_values(array_filter($tiposNegocioOptions, static fn($item) => trim((string)($item['nombre'] ?? '')) !== '' && (int)($item['disponible'] ?? 1) === 1))
            ))); ?>;
            const monedasDisponibles = <?php echo json_encode(array_values(array_map(
                static fn($item) => [
                    'id_moneda' => (int)($item['id_moneda'] ?? 0),
                    'nombre' => trim((string)($item['nombre'] ?? '')),
                    'codigo_iso' => strtoupper(trim((string)($item['codigo_iso'] ?? ''))),
                ],
                array_values(array_filter($monedasOptions, static fn($item) => (int)($item['id_moneda'] ?? 0) > 0 && trim((string)($item['nombre'] ?? '')) !== ''))
            ))); ?>;

            return {
                loading: false,
                loadingRuc: false,
                isEdit: isEditMode,
                tiposNegocioDisponibles,
                monedasDisponibles,
                logoPreview: null,
                showLogoCaptureModal: false,
                logoCapturePreview: '',
                logoCaptureUrl: '',
                logoCaptureFile: null,
                logoCaptureSaving: false,
                logoPixabayLoading: false,
                logoLongPressTimer: null,
                logoLongPressing: false,
                form: {
                    id_empresa: idEmpresa,
                    empresa: empresaContext.empresa || '',
                    nombre_fantasia: '',
                    ruc: empresaContext.ruc || '',
                    dv: empresaContext.dv || '',
                    telefono: '',
                    telefono2: '',
                    email: '',
                    email2: '',
                    direccion: 'Mariscal López',
                    numero_casa: '123',
                    ciudad: 'Asunción',
                    pais: 'Paraguay',
                    activo: 1,
                    software: 1,
                    server: empresaContext.server || '',
                    dbase: empresaContext.dbase || '',
                    database: empresaContext.database || '',
                    user: empresaContext.user || '',
                    sucursal: empresaContext.sucursal || '',
                    establecimiento: empresaContext.establecimiento || '',
                    caja_principal: empresaContext.caja || 'N/D',
                    logos: '',
                    disable_background_video: 0,
                    rubro: tiposNegocioDisponibles[0]?.nombre || 'Comercial',
                    moneda_principal: Number(empresaContext.moneda_principal || monedasDisponibles[0]?.id_moneda || 1),
                    web: 0,
                    web_url: '',
                    web_ck: '',
                    web_cs: '',
                    factura_electronica: 0,
                    ambiente_sifen: '',
                    timbrado: '',
                    vigencia_ini: '',
                    vigencia_fin: ''
                },
                init() {
                    this.normalizarTipoNegocio();
                    this.hidratarContextoInicial();
                    if (this.isEdit && this.form.id_empresa > 0) {
                        this.cargarEmpresa();
                    }
                },
                hidratarContextoInicial() {
                    if (!this.form.empresa && empresaContext.empresa) this.form.empresa = empresaContext.empresa;
                    if (!this.form.ruc && empresaContext.ruc) this.form.ruc = empresaContext.ruc;
                    if (!this.form.dv && empresaContext.dv) this.form.dv = empresaContext.dv;
                    if (!this.form.server && empresaContext.server) this.form.server = empresaContext.server;
                    if (!Number(this.form.puerto || 0) && Number(empresaContext.puerto || 0) > 0) this.form.puerto = Number(empresaContext.puerto);
                    if (!this.form.dbase && empresaContext.dbase) this.form.dbase = empresaContext.dbase;
                    if (!this.form.database && empresaContext.database) this.form.database = empresaContext.database;
                    if (!this.form.user && empresaContext.user) this.form.user = empresaContext.user;
                    if (!this.form.sucursal && empresaContext.sucursal) this.form.sucursal = empresaContext.sucursal;
                    if (!this.form.establecimiento && empresaContext.establecimiento) this.form.establecimiento = empresaContext.establecimiento;
                    if ((!this.form.caja_principal || this.form.caja_principal === 'N/D') && empresaContext.caja) this.form.caja_principal = empresaContext.caja;
                    if (!Number(this.form.moneda_principal || 0) && Number(empresaContext.moneda_principal || 0) > 0) {
                        this.form.moneda_principal = Number(empresaContext.moneda_principal);
                    }
                },
                softwareByTipoNegocio(tipo) {
                    const raw = String(tipo || '').trim().toLowerCase();
                    return (raw.includes('transport') || raw.includes('flota')) ? 2 : 1;
                },
                normalizarTipoNegocio() {
                    const opciones = Array.isArray(this.tiposNegocioDisponibles) ? this.tiposNegocioDisponibles : [];
                    const actual = String(this.form.rubro || '').trim();
                    if (!actual && opciones.length > 0) {
                        this.form.rubro = opciones[0].nombre;
                    } else if (actual) {
                        const existe = opciones.some((item) => String(item?.nombre || '').trim().toLowerCase() === actual.toLowerCase());
                        if (!existe) {
                            this.tiposNegocioDisponibles = [{ nombre: actual, disponible: 1 }, ...opciones];
                        }
                    }
                    this.form.software = this.softwareByTipoNegocio(this.form.rubro);
                },
                monedaPrincipalBloqueada() {
                    return this.isEdit && Number(this.form.id_empresa || 0) > 0 && Number(this.form.moneda_principal || 0) > 0;
                },
                monedaPrincipalActual() {
                    const monedaId = Number(this.form.moneda_principal || 1);
                    return this.monedasDisponibles.find((item) => Number(item?.id_moneda || 0) === monedaId) || this.monedasDisponibles[0] || null;
                },
                logoDisplayUrl() {
                    const file = String(this.form.logos || '').trim();
                    if (this.logoPreview) return this.logoPreview;
                    if (!file) return '';
                    if (/^https?:\/\//i.test(file) || file.startsWith('/public/')) return file;
                    return '/public/_lib/file/empresa/' + encodeURIComponent(file);
                },
                async cargarEmpresa() {
                    this.loading = true;
                    try {
                        const res = await fetch(`editar_empresa.php?action=get&id=${this.form.id_empresa}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            const keepIfBlank = ['empresa', 'ruc', 'dv', 'server', 'puerto', 'dbase', 'database', 'user', 'sucursal', 'establecimiento', 'caja_principal'];
                            Object.keys(this.form).forEach(key => {
                                if (data.data[key] === undefined || data.data[key] === null) {
                                    return;
                                }
                                if (keepIfBlank.includes(key) && String(data.data[key]).trim() === '') {
                                    return;
                                }
                                this.form[key] = data.data[key];
                            });
                            if ((data.data.factura_electronica === undefined || data.data.factura_electronica === null) && data.data.fe !== undefined) {
                                this.form.factura_electronica = Number(data.data.fe) === 1 ? 1 : 0;
                            }
                            this.normalizarTipoNegocio();
                            this.hidratarContextoInicial();
                            this.logoPreview = this.logoDisplayUrl();
                        } else {
                            window.showNotif('Error', data.error || 'No se pudo cargar la empresa', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        window.showNotif('Error', 'Error de conexión', 'error');
                    } finally {
                        this.loading = false;
                    }
                },
                async buscarRucSifen() {
                    const ruc = (this.form.ruc || '').trim();
                    if (this.loadingRuc || ruc.length < 5 || this.isEdit) return;
                    this.loadingRuc = true;
                    try {
                        const res = await fetch(`editar_empresa.php?action=sifen_lookup&ruc=${encodeURIComponent(ruc)}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            if (data.data.ruc_base) this.form.ruc = data.data.ruc_base;
                            if (data.data.dv) this.form.dv = data.data.dv;
                            if (data.data.razon_social) this.form.empresa = data.data.razon_social;
                        } else if (data.error) {
                            window.showNotif('SIFEN', data.error, 'info');
                        }
                    } catch (e) {
                        console.error('SIFEN lookup error', e);
                    } finally {
                        this.loadingRuc = false;
                    }
                },
                resetForm() {
                    if (this.isEdit) {
                        this.cargarEmpresa();
                    } else {
                        Object.assign(this.form, {
                            id_empresa: 0, empresa: '', nombre_fantasia: '',
                            ruc: '', dv: '', telefono: '', telefono2: '',
                            email: '', email2: '', direccion: 'Mariscal López',
                            numero_casa: '123', ciudad: 'Asunción', pais: 'Paraguay',
                            activo: 1, software: 1, server: '', dbase: '', database: '',
                            user: '', puerto: Number(empresaContext.puerto || (window.__SISTEMAX_ENV__ === 'dev' ? 3307 : 3306)), sucursal: '', establecimiento: '', caja_principal: 'N/D',
                            logos: '', disable_background_video: 0, rubro: this.tiposNegocioDisponibles[0]?.nombre || 'Comercial',
                            moneda_principal: Number(this.monedasDisponibles[0]?.id_moneda || 1),
                            web: 0, web_url: '', web_ck: '', web_cs: '',
                            factura_electronica: 0, ambiente_sifen: '',
                            timbrado: '', vigencia_ini: '', vigencia_fin: ''
                        });
                        this.normalizarTipoNegocio();
                        this.logoPreview = '';
                    }
                },
                startLogoLongPress() {
                    this.cancelLogoLongPress();
                    this.logoLongPressing = true;
                    this.logoLongPressTimer = setTimeout(() => this.openLogoCaptureModal(), 550);
                },
                cancelLogoLongPress() {
                    if (this.logoLongPressTimer) {
                        clearTimeout(this.logoLongPressTimer);
                        this.logoLongPressTimer = null;
                    }
                    this.logoLongPressing = false;
                },
                openLogoCaptureModal() {
                    this.cancelLogoLongPress();
                    this.logoCapturePreview = this.logoDisplayUrl();
                    this.logoCaptureUrl = '';
                    this.logoCaptureFile = null;
                    this.logoCaptureSaving = false;
                    this.logoPixabayLoading = false;
                    this.showLogoCaptureModal = true;
                    if (!this.logoCapturePreview) {
                        this.fetchPixabayLogoSuggestion();
                    }
                },
                closeLogoCaptureModal() {
                    this.showLogoCaptureModal = false;
                    this.clearLogoCaptureDraft();
                },
                clearLogoCaptureDraft() {
                    this.logoCapturePreview = this.logoDisplayUrl();
                    this.logoCaptureUrl = '';
                    this.logoCaptureFile = null;
                    this.logoPixabayLoading = false;
                    if (this.$refs.logoFileInput) this.$refs.logoFileInput.value = '';
                    if (this.$refs.logoCameraInput) this.$refs.logoCameraInput.value = '';
                },
                openLogoGoogleSearch() {
                    const query = encodeURIComponent(`${this.form.empresa || this.form.nombre_fantasia || 'empresa'} logo`);
                    window.open(`https://www.google.com/search?tbm=isch&q=${query}`, '_blank', 'noopener,noreferrer');
                },
                async fetchPixabayLogoSuggestion() {
                    if (this.logoPixabayLoading) return;
                    const query = String(this.form.nombre_fantasia || this.form.empresa || this.form.ruc || '').trim();
                    if (!query) return;
                    this.logoPixabayLoading = true;
                    try {
                        const res = await fetch(`/public/pos/api/buscar_imagenes.php?q=${encodeURIComponent(query + ' logo empresa')}&page=1&_t=${Date.now()}`, { cache: 'no-store' });
                        const data = await res.json();
                        const img = Array.isArray(data?.images) ? (data.images[0] || null) : null;
                        const imageUrl = String(img?.large || img?.preview || img?.thumbnail || '').trim();
                        if (!imageUrl) {
                            window.showNotif('Pixabay', 'No se encontró un logo sugerido', 'info');
                            return;
                        }
                        this.logoCaptureUrl = imageUrl;
                        this.logoCaptureFile = null;
                        this.logoCapturePreview = imageUrl;
                    } catch (e) {
                        window.showNotif('Error', 'No se pudo consultar Pixabay', 'error');
                    } finally {
                        this.logoPixabayLoading = false;
                    }
                },
                handleLogoFileSelected(event) {
                    const file = event?.target?.files?.[0] || null;
                    if (!file) return;
                    this.logoCaptureFile = file;
                    this.logoCaptureUrl = '';
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        this.logoCapturePreview = String(e?.target?.result || '');
                    };
                    reader.readAsDataURL(file);
                },
                previewLogoFromUrl() {
                    const url = String(this.logoCaptureUrl || '').trim();
                    if (!/^https?:\/\//i.test(url)) {
                        window.showNotif('Error', 'URL de imagen inválida', 'error');
                        return;
                    }
                    this.logoCaptureFile = null;
                    this.logoCapturePreview = url;
                },
                async saveLogoCapture() {
                    this.logoCaptureSaving = true;
                    try {
                        let res;
                        if (this.logoCaptureFile) {
                            const fd = new FormData();
                            fd.append('logo', this.logoCaptureFile);
                            fd.append('id_empresa', String(this.form.id_empresa || 0));
                            res = await fetch('editar_empresa.php?action=upload_logo', { method: 'POST', body: fd });
                        } else {
                            const imageUrl = String(this.logoCaptureUrl || '').trim();
                            if (!/^https?:\/\//i.test(imageUrl)) {
                                throw new Error('URL de imagen inválida');
                            }
                            res = await fetch('editar_empresa.php?action=upload_logo_url', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    id_empresa: Number(this.form.id_empresa || 0),
                                    image_url: imageUrl
                                })
                            });
                        }
                        const data = await res.json();
                        if (!data?.success) {
                            throw new Error(data?.error || 'No se pudo guardar el logo');
                        }
                        this.form.logos = data.filename || this.form.logos;
                        this.logoPreview = data.path || this.logoDisplayUrl();
                        window.showNotif('Éxito', 'Logo actualizado correctamente', 'success');
                        this.closeLogoCaptureModal();
                    } catch (e) {
                        window.showNotif('Error', e?.message || 'No se pudo guardar el logo', 'error');
                    } finally {
                        this.logoCaptureSaving = false;
                    }
                },
                async confirmarEliminar() {
                    if (!this.isEdit || !this.form.id_empresa) return;

                    const modalHtml = `
                        <div id="deleteConfirmModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.8)">
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-lg w-full p-6 border-2 border-red-500">
                                <div class="text-center mb-6">
                                    <div class="w-20 h-20 mx-auto rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mb-4">
                                        <i class="fas fa-exclamation-triangle text-4xl text-red-600"></i>
                                    </div>
                                    <h2 class="text-2xl font-bold text-red-600 mb-2">⚠️ OPERACIÓN CRÍTICA</h2>
                                    <p class="text-slate-600 dark:text-slate-300 font-medium">Estás a punto de eliminar permanentemente:</p>
                                </div>
                                <div class="bg-slate-100 dark:bg-slate-700 rounded-lg p-4 mb-4">
                                    <p class="font-bold text-slate-800 dark:text-white text-lg">${this.form.empresa}</p>
                                    <p class="text-slate-600 dark:text-slate-400">RUC: ${this.form.ruc}</p>
                                    <p class="text-slate-600 dark:text-slate-400">Base de datos: ${this.form.dbase || 'empresa_' + this.form.id_empresa}</p>
                                </div>
                                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-4">
                                    <p class="text-red-700 dark:text-red-300 text-sm font-medium mb-2">
                                        <i class="fas fa-info-circle mr-1"></i> Esta acción:
                                    </p>
                                    <ul class="text-red-600 dark:text-red-400 text-sm space-y-1 ml-4">
                                        <li>• Eliminará la base de datos completa</li>
                                        <li>• Borrará todas las facturas, clientes y datos</li>
                                        <li>• Se creará un backup antes de eliminar</li>
                                        <li>• <strong>NO SE PUEDE DESHACER</strong></li>
                                    </ul>
                                </div>
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                        Para confirmar, escribe el RUC: <strong class="text-red-600">${this.form.ruc}</strong>
                                    </label>
                                    <input type="text" id="deleteConfirmInput"
                                        class="w-full px-4 py-3 rounded-lg border-2 border-red-300 dark:border-red-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:border-red-500 focus:ring-4 focus:ring-red-500/20 outline-none font-mono text-lg"
                                        placeholder="Escribe el RUC aquí..." autocomplete="off">
                                </div>
                                <div class="flex gap-3">
                                    <button id="cancelDeleteBtn" class="flex-1 px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-medium hover:bg-slate-100 dark:hover:bg-slate-700 transition-all">
                                        <i class="fas fa-times mr-2"></i>Cancelar
                                    </button>
                                    <button id="confirmDeleteBtn" class="flex-1 px-4 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                        <i class="fas fa-trash-alt mr-2"></i>Eliminar Empresa
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;

                    document.body.insertAdjacentHTML('beforeend', modalHtml);
                    const modal = document.getElementById('deleteConfirmModal');
                    const input = document.getElementById('deleteConfirmInput');
                    const confirmBtn = document.getElementById('confirmDeleteBtn');
                    const cancelBtn = document.getElementById('cancelDeleteBtn');
                    const rucToMatch = this.form.ruc;

                    input.focus();
                    input.addEventListener('input', () => {
                        confirmBtn.disabled = input.value !== rucToMatch;
                    });
                    cancelBtn.addEventListener('click', () => modal.remove());

                    confirmBtn.addEventListener('click', async () => {
                        if (input.value !== rucToMatch) return;
                        confirmBtn.disabled = true;
                        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Eliminando...';
                        modal.remove();

                        const dbName = this.form.dbase || 'empresa_' + this.form.id_empresa;
                        window.showProgress({
                            title: 'Eliminando Empresa',
                            subtitle: `🗑️ ${this.form.empresa}`,
                            step: 'Iniciando proceso de eliminación...',
                            log: `Base de datos: ${dbName}`
                        });

                        await this.sleep(300);
                        window.updateProgress({ progress: 10, addLog: 'Validando datos de empresa...', logType: 'loading' });
                        await this.sleep(200);

                        try {
                            window.updateProgress({ progress: 20, log: 'Conectando al servidor...', logType: 'success' });
                            await this.sleep(200);
                            window.updateProgress({ progress: 30, addLog: 'Creando backup de base de datos...', logType: 'loading' });

                            const res = await fetch('editar_empresa.php?action=delete', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id_empresa: this.form.id_empresa, confirmacion: input.value })
                            });
                            const data = await res.json();

                            if (data.success) {
                                window.updateProgress({ progress: 60, log: 'Backup completado', logType: 'success' });
                                await this.sleep(200);
                                if (data.backup_file) {
                                    window.updateProgress({ progress: 70, addLog: `💾 Archivo: ${data.backup_file}`, logType: 'success' });
                                    await this.sleep(200);
                                }
                                window.updateProgress({ progress: 90, addLog: 'Eliminando base de datos...', logType: 'loading' });
                                await this.sleep(300);
                                window.finishProgress({ tablesCreated: 0, columnsAdded: 0, errorsCount: 0, log: '✅ Empresa eliminada correctamente', logType: 'success' });
                            } else {
                                window.finishProgress({ tablesCreated: 0, columnsAdded: 0, errorsCount: 1, log: data.error || 'Error al eliminar', logType: 'error' });
                            }
                        } catch (e) {
                            window.finishProgress({ tablesCreated: 0, columnsAdded: 0, errorsCount: 1, log: 'Error de conexión: ' + e.message, logType: 'error' });
                        }
                    });
                },
                async guardar() {
                    if (!this.form.ruc) {
                        window.showNotif('Falta RUC', 'El RUC es obligatorio', 'warning');
                        return;
                    }
                    if (!this.form.empresa) {
                        window.showNotif('Falta empresa', 'El nombre de empresa es obligatorio', 'warning');
                        return;
                    }

                    this.loading = true;
                    this.form.software = this.softwareByTipoNegocio(this.form.rubro);

                    const dbName = 'empresa_' + (this.form.id_empresa || 'nueva');
                    const isNew = !this.isEdit;
                    const negocioName = String(this.form.rubro || '').trim() || 'Comercial';
                    const oldDbName = this.form.dbase || '';
                    const needsMigration = isNew ? false : (oldDbName && oldDbName !== dbName);

                    let subtitleText = `📥 Origen: detectando... → 📤 Destino: ${dbName}`;
                    if (needsMigration) subtitleText = `⚠️ Migración: ${oldDbName} → ${dbName}`;

                    window.showProgress({
                        title: isNew ? 'Creando Empresa' : 'Actualizando Empresa',
                        subtitle: subtitleText,
                        step: 'Guardando datos de empresa...',
                        log: `Tipo de negocio: ${negocioName}${needsMigration ? ' | Migración pendiente' : ''}`
                    });

                    await this.sleep(300);
                    window.updateProgress({ progress: 10, log: 'Datos de empresa guardados', logType: 'success' });

                    try {
                        window.updateProgress({ progress: 15, addLog: 'Conectando al servidor...', logType: 'loading' });
                        await this.sleep(200);

                        const res = await fetch('editar_empresa.php?action=save', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(this.form)
                        });

                        window.updateProgress({ progress: 30, log: 'Conexión establecida', logType: 'success' });
                        await this.sleep(200);
                        window.updateProgress({ progress: 40, addLog: 'Procesando respuesta...', logType: 'loading' });

                        const data = await res.json();

                        if (data.success) {
                            window.updateProgress({ progress: 50, log: data.message || 'Empresa guardada', logType: 'success' });
                            await this.sleep(200);
                            if (data.db_source) {
                                window.updateProgress({ progress: 53, addLog: `Base origen usada: ${data.db_source}`, logType: 'info' });
                                await this.sleep(120);
                            }

                            // Migración
                            if (data.migration && data.migration.migrated) {
                                window.updateProgress({ progress: 52, addLog: `⚠️ DB anterior detectada: ${data.migration.old_db}`, logType: 'warning' });
                                await this.sleep(300);
                                window.updateProgress({ progress: 56, addLog: `✓ ${data.migration.tables_migrated} tablas migradas`, logType: 'success' });
                                await this.sleep(200);
                                if (data.migration.backup_file) {
                                    window.updateProgress({ progress: 58, addLog: `💾 Backup creado: ${data.migration.backup_file}`, logType: 'success' });
                                    await this.sleep(200);
                                }
                            }

                            if (data.db_created) {
                                window.updateProgress({ progress: 60, addLog: `Base de datos ${data.db_name} creada`, logType: 'success' });
                                await this.sleep(200);
                            } else if (data.db_name) {
                                window.updateProgress({ progress: 55, addLog: `Base de datos: ${data.db_name}`, logType: 'info' });
                            }
                            this.form.dbase = data.db_name || this.form.dbase;
                            await this.sleep(150);

                            // Tablas creadas
                            const tablasCreadas = data.sync_tables_created_list || [];
                            if (tablasCreadas.length > 0) {
                                window.updateProgress({ progress: 65, addLog: `Creando ${tablasCreadas.length} tablas...`, logType: 'loading' });
                                await this.sleep(300);
                                const maxShow = Math.min(tablasCreadas.length, 10);
                                for (let i = 0; i < maxShow; i++) {
                                    window.updateProgress({
                                        progress: 65 + Math.round((i / maxShow) * 15),
                                        addLog: `✓ Tabla: ${tablasCreadas[i]}`,
                                        logType: 'success'
                                    });
                                    await this.sleep(50);
                                }
                                if (tablasCreadas.length > 10) {
                                    window.updateProgress({ addLog: `... y ${tablasCreadas.length - 10} tablas más`, logType: 'info' });
                                }
                            }

                            // Columnas agregadas
                            const columnasAgregadas = data.sync_columns_added_list || [];
                            const tablasSincronizadas = data.sync_tables_updated_list || [];
                            if (tablasSincronizadas.length > 0) {
                                window.updateProgress({ progress: 85, addLog: `Sincronizando ${tablasSincronizadas.length} tablas existentes...`, logType: 'loading' });
                                await this.sleep(200);
                                const maxCols = Math.min(columnasAgregadas.length, 10);
                                for (let i = 0; i < maxCols; i++) {
                                    window.updateProgress({
                                        progress: 85 + Math.round((i / maxCols) * 10),
                                        addLog: `+ Columna: ${columnasAgregadas[i]}`,
                                        logType: 'success'
                                    });
                                    await this.sleep(30);
                                }
                                if (columnasAgregadas.length > 10) {
                                    window.updateProgress({ addLog: `... y ${columnasAgregadas.length - 10} columnas más`, logType: 'info' });
                                }
                            }

                            // Errores de sync
                            const syncErrors = data.sync_errors || data.setup_errors || [];
                            if (syncErrors.length > 0) {
                                syncErrors.slice(0, 5).forEach(err => {
                                    window.updateProgress({ addLog: err, logType: 'error' });
                                });
                            }

                            if (data.user_created) {
                                window.updateProgress({ progress: 95, addLog: 'Usuario soporte creado', logType: 'success' });
                            }

                            await this.sleep(300);
                            window.finishProgress({
                                tablesCreated: tablasCreadas.length || data.sync_tables_created || 0,
                                columnsAdded: columnasAgregadas.length || 0,
                                errorsCount: syncErrors.length,
                                log: 'Proceso completado exitosamente',
                                logType: 'success'
                            });

                            if (!this.isEdit && data.id) {
                                this.form.id_empresa = data.id;
                                this.isEdit = true;
                            }
                        } else {
                            window.finishProgress({ tablesCreated: 0, columnsAdded: 0, errorsCount: 1, log: data.error || 'Error al guardar', logType: 'error' });
                        }
                    } catch (e) {
                        window.finishProgress({ tablesCreated: 0, columnsAdded: 0, errorsCount: 1, log: 'Error de conexión: ' + e.message, logType: 'error' });
                    } finally {
                        this.loading = false;
                    }
                },
                sleep(ms) {
                    return new Promise(resolve => setTimeout(resolve, ms));
                },
                cerrarModal() {
                    try {
                        if (window.parent && window.parent !== window && typeof window.parent.cerrarApp === 'function') {
                            window.parent.cerrarApp();
                            return;
                        }
                    } catch (e) {
                        console.log('Error cerrando app en iframe:', e);
                    }
                    window.location.href = '/public/menu/menu.php';
                },
                async subirLogo(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    const allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(file.type)) {
                        window.showNotif('Error', 'Formato no válido. Use PNG, JPG o GIF', 'error');
                        return;
                    }
                    if (file.size > 2 * 1024 * 1024) {
                        window.showNotif('Error', 'El archivo excede 2MB', 'error');
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = (e) => { this.logoPreview = e.target.result; };
                    reader.readAsDataURL(file);

                    const formData = new FormData();
                    formData.append('logo', file);
                    formData.append('id_empresa', this.form.id_empresa || 0);

                    try {
                        const res = await fetch('editar_empresa.php?action=upload_logo', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.form.logos = data.filename;
                            this.logoPreview = data.path || this.logoPreview;
                            window.showNotif('Éxito', 'Logo subido correctamente', 'success');
                        } else {
                            window.showNotif('Error', data.error || 'No se pudo subir el logo', 'error');
                            this.logoPreview = null;
                        }
                    } catch (e) {
                        console.error(e);
                        window.showNotif('Error', 'Error al subir el logo', 'error');
                        this.logoPreview = null;
                    }
                }
            };
        }
    </script>
</body>

</html>
