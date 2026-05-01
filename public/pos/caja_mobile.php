<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config/db_config.php';

$id_login = (int)($_SESSION['id_login'] ?? 0);
$id_empresa = (int)($_SESSION['id_empresa'] ?? 0);
$id_empresa_req = (int)($_GET['id_empresa'] ?? 0);
$id_caja = (int)($_SESSION['id_caja_def'] ?? 0);
$id_caja_req = (int)($_GET['id_caja'] ?? 0);
$usuario = (string)($_SESSION['username'] ?? $_SESSION['login'] ?? 'Usuario');

try {
    $pdo_init = getMasterConnection();
    $stmt_user = $pdo_init->prepare("SELECT login, caja_def, id_empresa FROM sec_users WHERE id_login = :id");
    $stmt_user->execute([':id' => $id_login]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        $usuario = (string)($user_data['login'] ?? $usuario);
        if ($id_caja_req > 0) {
            $id_caja = $id_caja_req;
        } elseif ($id_caja <= 0) {
            $id_caja = (int)($user_data['caja_def'] ?? 0);
        }

        if ($id_empresa_req > 0) {
            $id_empresa = $id_empresa_req;
        } elseif ($id_empresa <= 0) {
            $id_empresa = (int)($user_data['id_empresa'] ?? 0);
        }
    }
} catch (Throwable $e) {
}

if ($id_caja <= 0) {
    $id_caja = 1;
}
if ($id_empresa <= 0) {
    $id_empresa = 169;
}

$_SESSION['id_caja_def'] = $id_caja;
$_SESSION['id_empresa'] = $id_empresa;
$mobileV = @filemtime(__DIR__ . '/mobile.php') ?: time();
?>
<!DOCTYPE html>
<html lang="es" class="h-full" x-data="cajaMobile()" x-init="init()" :class="isDarkMode ? 'dark' : ''">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Mi Caja Movil</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../assets/vendor/alpine.min.js?v=<?php echo @filemtime(__DIR__ . '/../assets/vendor/alpine.min.js') ?: time(); ?>" defer></script>
    <style>
        [x-cloak] { display: none !important; }
        html, body { height: 100%; overscroll-behavior-y: contain; }
        body {
            margin: 0;
            -webkit-font-smoothing: antialiased;
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .mobile-shell {
            height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .summary-scroll {
            overflow-x: auto;
            scrollbar-width: none;
        }
        .summary-scroll::-webkit-scrollbar { display: none; }
        .card-shadow {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
        }
        .dark .card-shadow {
            box-shadow: 0 2px 8px rgba(0,0,0,0.3), 0 1px 2px rgba(0,0,0,0.2);
        }
        .tab-bar {
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .safe-bottom {
            padding-bottom: max(0.5rem, env(safe-area-inset-bottom));
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-slate-900 dark:text-slate-100">
<script>
window.__SISTEMAX_BUG_CONFIG__ = {
    id_empresa: <?php echo (int)$id_empresa; ?>,
    empresa: <?php echo json_encode($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa ' . (int)$id_empresa), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    usuario: <?php echo json_encode((string)$usuario, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
};
</script>
<script defer src="/public/assets/js/sistemax-bug-reporter.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/sistemax-bug-reporter.js') ?: time(); ?>"></script>
<div class="mobile-shell">
    <header class="px-4 py-3 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-20">
        <div class="flex items-center justify-between gap-2">
            <button @click="window.location.href='/public/pos/mobile.php?id_caja=<?php echo (int)$id_caja; ?>&id_empresa=<?php echo (int)$id_empresa; ?>&v=<?php echo (int)$mobileV; ?>'" class="h-9 px-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm inline-flex items-center gap-2">
                <span>&larr;</span>
                <span>Volver</span>
            </button>
            <div class="text-xs text-gray-500 dark:text-slate-400 truncate">Caja #<?php echo str_pad((string)$id_caja, 2, '0', STR_PAD_LEFT); ?> · <?php echo htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <h2 class="text-lg font-bold mt-2">
            <i class="fas fa-cash-register text-blue-500 mr-2"></i>
            Mi Caja
        </h2>
    </header>

    <main class="flex-1 overflow-y-auto hide-scrollbar p-3 space-y-3 pb-24">
        <section class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 card-shadow overflow-hidden">
            <button @click="showTotals = !showTotals" class="w-full px-3 py-3 flex items-center justify-between">
                <div class="text-left">
                    <p class="text-xs text-gray-500 dark:text-slate-400 uppercase">Totales de Caja</p>
                    <p class="text-lg font-black text-blue-600 dark:text-blue-400" x-text="money(summary.saldo)"></p>
                </div>
                <i class="fas fa-chevron-down text-gray-400 transition-transform" :class="showTotals ? 'rotate-180' : ''"></i>
            </button>
            <div x-show="showTotals" x-transition class="p-2 pt-0 grid grid-cols-2 gap-2">
                <div class="rounded-lg p-2 bg-gray-50 dark:bg-slate-900/50 border border-gray-200 dark:border-slate-700">
                    <div class="text-[10px] uppercase text-gray-500 dark:text-slate-400">Efectivo</div>
                    <div class="text-base font-black text-emerald-600 dark:text-emerald-400" x-text="money(summary.efectivo)"></div>
                </div>
                <div class="rounded-lg p-2 bg-gray-50 dark:bg-slate-900/50 border border-gray-200 dark:border-slate-700">
                    <div class="text-[10px] uppercase text-gray-500 dark:text-slate-400">Tarjeta</div>
                    <div class="text-base font-black text-sky-600 dark:text-sky-400" x-text="money(summary.tarjeta)"></div>
                </div>
                <div class="rounded-lg p-2 bg-gray-50 dark:bg-slate-900/50 border border-gray-200 dark:border-slate-700">
                    <div class="text-[10px] uppercase text-gray-500 dark:text-slate-400">Transfer</div>
                    <div class="text-base font-black text-violet-600 dark:text-violet-400" x-text="money(summary.transferencia)"></div>
                </div>
                <div class="rounded-lg p-2 bg-gray-50 dark:bg-slate-900/50 border border-gray-200 dark:border-slate-700">
                    <div class="text-[10px] uppercase text-gray-500 dark:text-slate-400">QR / PIX</div>
                    <div class="text-base font-black text-amber-600 dark:text-amber-400" x-text="money(summary.qr_pix)"></div>
                </div>
                <div class="rounded-lg p-2 bg-gray-50 dark:bg-slate-900/50 border border-gray-200 dark:border-slate-700 col-span-2">
                    <div class="text-[10px] uppercase text-gray-500 dark:text-slate-400">Facturas Pendientes</div>
                    <div class="text-base font-black text-amber-500 dark:text-amber-300" x-text="summary.facturas_pendientes_count || 0"></div>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 card-shadow overflow-hidden">
            <button @click="showFilters = !showFilters" class="w-full px-3 py-3 flex items-center justify-between">
                <div class="text-left">
                    <p class="text-xs text-gray-500 dark:text-slate-400 uppercase">Filtros</p>
                    <p class="text-sm font-semibold text-gray-700 dark:text-slate-200" x-text="'Periodo: ' + periodLabel()"></p>
                </div>
                <i class="fas fa-chevron-down text-gray-400 transition-transform" :class="showFilters ? 'rotate-180' : ''"></i>
            </button>
            <div x-show="showFilters" x-transition class="space-y-2 bg-gray-50 dark:bg-slate-900/40 rounded-b-xl p-2 border-t border-gray-200 dark:border-slate-700">
                <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                <input
                    x-model="filterSearch"
                    @input.debounce.250ms="loadData()"
                    type="text"
                    placeholder="Buscar por nro, cliente, concepto..."
                    class="w-full bg-white dark:bg-slate-700 rounded-lg pl-8 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-600 text-gray-800 dark:text-slate-100 placeholder-gray-400 dark:placeholder-slate-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
            </div>

                <select x-model="filterPeriod" @change="setPeriod(filterPeriod)" class="w-full bg-white dark:bg-slate-700 rounded-lg px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 font-semibold text-gray-700 dark:text-slate-200">
                    <template x-for="period in periods" :key="period.value">
                        <option :value="period.value" x-text="period.label"></option>
                    </template>
                </select>

                <div x-show="filterPeriod === 'custom'" class="grid grid-cols-2 gap-2">
                <input type="date" x-model="customDateFrom" @change="applyCustomRange()" class="w-full bg-white dark:bg-slate-700 rounded-lg px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-200">
                <input type="date" x-model="customDateTo" @change="applyCustomRange()" class="w-full bg-white dark:bg-slate-700 rounded-lg px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-200">
                </div>

                <div class="grid grid-cols-3 gap-2">
                <select x-model="filterStatus" @change="loadData()"
                        class="col-span-2 w-full bg-white dark:bg-slate-700 rounded-lg px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 font-semibold text-gray-700 dark:text-slate-200">
                    <option value="activos">Activos</option>
                    <option value="anulados">Anulados</option>
                    <option value="todos">Todos</option>
                </select>
                <button @click="clearFilters()"
                        class="w-full py-2 rounded-lg bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-gray-600 dark:text-gray-300 text-xs font-bold hover:bg-gray-100 dark:hover:bg-slate-600">
                    Limpiar
                </button>
                </div>

                <button
                    @click="exportCsv()"
                    class="w-full py-2 rounded-lg bg-emerald-600 text-white text-xs font-bold flex items-center justify-center gap-2">
                    <i class="fas fa-file-excel"></i> Exportar Excel
                </button>
            </div>
        </section>

        <section class="grid grid-cols-3 gap-2">
            <button @click="openModal('open')" class="h-10 rounded-lg bg-blue-600 text-white font-semibold text-xs">Apertura</button>
            <button @click="openModal('operacion')" class="h-10 rounded-lg bg-emerald-600 text-white font-semibold text-xs">Operaciones</button>
            <button @click="closeCajaQuick()" class="h-10 rounded-lg bg-rose-600 text-white font-semibold text-xs">Cierre</button>
        </section>

        <section class="space-y-2">
            <div class="flex items-center justify-between px-1">
                <h3 class="text-sm font-bold text-gray-700 dark:text-slate-200">Operaciones de Caja</h3>
                <span class="text-xs text-gray-500 dark:text-slate-400" x-text="operaciones.length + ' registros'"></span>
            </div>
            <div class="grid grid-cols-1 gap-2">
                <template x-for="row in operaciones" :key="row.id">
                    <article class="bg-white dark:bg-slate-800 rounded-xl p-3 card-shadow border border-gray-200 dark:border-slate-700">
                        <button @click="toggleOperation(row.id)" class="w-full flex items-start justify-between gap-2 mb-1 text-left">
                            <div class="min-w-0">
                                <p class="font-bold text-sm text-gray-800 dark:text-slate-100" x-text="'#' + row.id"></p>
                                <p class="text-[10px] text-gray-500 dark:text-slate-400" x-text="formatDate(row.fecha)"></p>
                                <p class="text-sm font-semibold text-gray-700 dark:text-slate-200 break-words mt-1" x-text="row.concepto || '-'"></p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span :class="Number(row.estado) === 1 ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400'" class="text-[10px] px-2 py-0.5 rounded-full font-semibold" x-text="Number(row.estado) === 1 ? 'Activo' : 'Anulado'"></span>
                                <i class="fas fa-chevron-down text-gray-400 transition-transform" :class="openOperationId === row.id ? 'rotate-180' : ''"></i>
                            </div>
                        </button>
                        <div x-show="openOperationId === row.id" x-transition class="mt-2 pt-2 border-t border-gray-100 dark:border-slate-700 grid grid-cols-3 gap-2">
                            <div>
                                <p class="text-[10px] text-gray-500 dark:text-slate-400 uppercase">Entrada</p>
                                <p class="font-bold text-sm text-emerald-600 dark:text-emerald-400" x-text="row.credito > 0 ? money(row.credito) : '-'"></p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500 dark:text-slate-400 uppercase">Salida</p>
                                <p class="font-bold text-sm text-rose-600 dark:text-rose-400" x-text="row.debito > 0 ? money(row.debito) : '-'"></p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500 dark:text-slate-400 uppercase">Saldo</p>
                                <p class="font-bold text-sm text-blue-600 dark:text-blue-400" x-text="money(row.saldo_acumulado || 0)"></p>
                            </div>
                        </div>
                    </article>
                </template>
            </div>

            <div x-show="!loading && operaciones.length === 0" class="text-center py-12">
                <div class="text-5xl mb-3 opacity-50">🧾</div>
                <p class="text-gray-500 dark:text-slate-400 text-sm">Sin operaciones para los filtros actuales.</p>
            </div>

            <div x-show="loading" class="flex items-center justify-center py-10">
                <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full"></div>
            </div>
        </section>
    </main>

    <nav class="tab-bar bg-white/90 dark:bg-slate-800/90 border-t border-gray-200 dark:border-slate-700 safe-bottom">
        <div class="flex justify-around py-2">
            <button
                @click="window.location.href='/public/pos/mobile.php?tab=products&id_caja=<?php echo (int)$id_caja; ?>&id_empresa=<?php echo (int)$id_empresa; ?>&v=<?php echo (int)$mobileV; ?>'"
                class="flex flex-col items-center py-1 px-6 transition-colors text-gray-400 hover:text-blue-600">
                <i class="fas fa-box-open text-xl"></i>
                <span class="text-xs mt-1 font-medium">Productos</span>
            </button>

            <button
                @click="window.location.href='/public/pos/mobile.php?tab=cart&id_caja=<?php echo (int)$id_caja; ?>&id_empresa=<?php echo (int)$id_empresa; ?>&v=<?php echo (int)$mobileV; ?>'"
                class="flex flex-col items-center py-1 px-6 transition-colors text-gray-400 hover:text-blue-600">
                <i class="fas fa-shopping-cart text-xl"></i>
                <span class="text-xs mt-1 font-medium">Carrito</span>
            </button>

            <button
                @click="window.location.href='/public/pos/mobile.php?tab=sales&id_caja=<?php echo (int)$id_caja; ?>&id_empresa=<?php echo (int)$id_empresa; ?>&v=<?php echo (int)$mobileV; ?>'"
                class="flex flex-col items-center py-1 px-4 transition-colors text-gray-400 hover:text-blue-600">
                <i class="fas fa-receipt text-xl"></i>
                <span class="text-xs mt-1 font-medium">Ventas</span>
            </button>

            <button
                class="flex flex-col items-center py-1 px-4 transition-colors text-blue-600">
                <i class="fas fa-cash-register text-xl"></i>
                <span class="text-xs mt-1 font-medium">Mi Caja</span>
            </button>
        </div>
    </nav>
</div>

<div x-cloak x-show="showModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @click.self="showModal = false">
    <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-slate-700">
            <h3 class="font-semibold text-gray-800 dark:text-slate-100" x-text="modalTitle"></h3>
        </div>
        <div class="p-4 space-y-3">
            <div x-show="modalMode === 'operacion'">
                <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Tipo de Operacion</label>
                <select x-model="modalOperacionTipo" class="w-full h-11 px-3 rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-gray-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="entrada">Entrada</option>
                    <option value="salida">Salida</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Monto</label>
                <input x-model="modalMonto" type="text" inputmode="numeric" class="w-full h-11 px-3 rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-gray-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div x-show="modalMode !== 'open'">
                <label class="block text-xs text-gray-500 dark:text-slate-400 mb-1">Concepto</label>
                <input x-model="modalConcepto" type="text" class="w-full h-11 px-3 rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-gray-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>
        <div class="px-4 py-3 border-t border-gray-200 dark:border-slate-700 flex gap-2">
            <button @click="showModal = false" class="h-11 flex-1 rounded-lg bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-800 dark:text-slate-100 font-semibold">Cancelar</button>
            <button @click="saveModal()" :disabled="saving" class="h-11 flex-1 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 font-semibold">
                <span x-show="!saving">Guardar</span>
                <span x-show="saving">Guardando...</span>
            </button>
        </div>
    </div>
</div>

<div class="fixed bottom-4 right-3 z-50 space-y-2">
    <template x-for="t in toasts" :key="t.id">
        <div x-show="t.show" x-transition class="px-3 py-2 rounded-lg text-white shadow-lg text-sm"
            :class="t.type === 'error' ? 'bg-rose-600' : (t.type === 'warning' ? 'bg-amber-600' : 'bg-emerald-600')"
            x-text="t.message"></div>
    </template>
</div>

<script>
function cajaMobile() {
    const today = new Date().toISOString().split('T')[0];
    return {
        idCaja: <?php echo (int)$id_caja; ?>,
        idEmpresa: <?php echo (int)$id_empresa; ?>,
        isDarkMode: true,
        loading: false,
        saving: false,
        showTotals: false,
        showFilters: false,
        openOperationId: null,
        filterSearch: '',
        filterStatus: 'activos',
        filterPeriod: 'hoy',
        fechaDesde: today,
        fechaHasta: today,
        customDateFrom: today,
        customDateTo: today,
        periods: [
            { value: 'todo', label: 'Todo' },
            { value: 'hoy', label: 'Hoy' },
            { value: 'semana', label: 'Semana' },
            { value: 'mes', label: 'Mes' },
            { value: 'anio', label: 'Año' },
            { value: 'custom', label: 'Personalizado' },
        ],
        summary: {
            efectivo: 0,
            tarjeta: 0,
            transferencia: 0,
            qr_pix: 0,
            saldo: 0,
            facturas_pendientes_count: 0,
        },
        operaciones: [],
        showModal: false,
        modalMode: 'entrada',
        modalOperacionTipo: 'entrada',
        modalMonto: '',
        modalConcepto: '',
        toasts: [],
        toastSeq: 0,

        get modalTitle() {
            if (this.modalMode === 'open') return 'Apertura de Caja';
            if (this.modalMode === 'operacion') return 'Operacion de Caja';
            if (this.modalMode === 'salida') return 'Nueva Salida';
            return 'Nueva Entrada';
        },

        init() {
            this.syncThemeFromStorage();
            window.addEventListener('storage', (e) => {
                if (!e || e.key === 'theme' || e.key === null) this.syncThemeFromStorage();
            });
            this.setPeriod('hoy', false);
            this.loadData();
        },

        toggleOperation(id) {
            this.openOperationId = this.openOperationId === id ? null : id;
        },

        periodLabel() {
            const p = (this.periods || []).find((x) => x.value === this.filterPeriod);
            return p ? p.label : 'Hoy';
        },

        syncThemeFromStorage() {
            const theme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            this.isDarkMode = theme === 'dark' || (!theme && prefersDark);
        },

        toast(message, type = 'success') {
            const id = ++this.toastSeq;
            this.toasts.push({ id, message, type, show: true });
            setTimeout(() => {
                const t = this.toasts.find(x => x.id === id);
                if (t) t.show = false;
                setTimeout(() => {
                    this.toasts = this.toasts.filter(x => x.id !== id);
                }, 200);
            }, 2600);
        },

        async apiFetch(url, options = {}) {
            const headers = {
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {})
            };
            return fetch(url, { credentials: 'same-origin', ...options, headers });
        },

        dateRangeFor(period) {
            const d = new Date();
            const todayIso = d.toISOString().split('T')[0];
            const toIso = (x) => x.toISOString().split('T')[0];
            let from = new Date(d);
            let to = new Date(d);
            if (period === 'todo') from = new Date(d.getFullYear() - 3, 0, 1);
            if (period === 'semana') {
                const day = d.getDay();
                const diff = day === 0 ? 6 : day - 1;
                from.setDate(d.getDate() - diff);
            }
            if (period === 'mes') from = new Date(d.getFullYear(), d.getMonth(), 1);
            if (period === 'anio') from = new Date(d.getFullYear(), 0, 1);
            if (period === 'custom') {
                from = new Date(this.customDateFrom || todayIso);
                to = new Date(this.customDateTo || todayIso);
            }
            return { from: toIso(from), to: toIso(to) };
        },

        setPeriod(period, reload = true) {
            this.filterPeriod = period;
            const range = this.dateRangeFor(period);
            this.fechaDesde = range.from;
            this.fechaHasta = range.to;
            if (period !== 'custom') {
                this.customDateFrom = range.from;
                this.customDateTo = range.to;
            }
            if (reload) this.loadData();
        },

        applyCustomRange() {
            if (!this.customDateFrom || !this.customDateTo) return;
            if (this.customDateFrom > this.customDateTo) {
                const tmp = this.customDateFrom;
                this.customDateFrom = this.customDateTo;
                this.customDateTo = tmp;
            }
            this.setPeriod('custom');
        },

        clearFilters() {
            this.filterSearch = '';
            this.filterStatus = 'activos';
            this.setPeriod('hoy');
        },

        async loadData() {
            this.loading = true;
            await Promise.all([this.loadSummary(), this.loadOperaciones()]);
            this.loading = false;
        },

        async loadSummary() {
            try {
                const url = `api/caja.php?action=summary&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}&fecha_desde=${this.fechaDesde}&fecha_hasta=${this.fechaHasta}&estado=${encodeURIComponent(this.filterStatus)}&q=${encodeURIComponent(this.filterSearch)}&solo_mias=0&_ts=${Date.now()}`;
                const res = await this.apiFetch(url, { cache: 'no-store' });
                const data = await res.json();
                if (res.status === 401 || data?.error === 'Unauthorized') {
                    this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
                    return;
                }
                if (data?.success) {
                    this.summary = { ...this.summary, ...(data.summary || {}) };
                }
            } catch (e) {
                this.toast('No se pudo cargar resumen', 'error');
            }
        },

        async loadOperaciones() {
            try {
                const url = `api/caja.php?action=list&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}&fecha_desde=${this.fechaDesde}&fecha_hasta=${this.fechaHasta}&estado=${encodeURIComponent(this.filterStatus)}&q=${encodeURIComponent(this.filterSearch)}&solo_mias=0&_ts=${Date.now()}`;
                const res = await this.apiFetch(url, { cache: 'no-store' });
                const data = await res.json();
                if (res.status === 401 || data?.error === 'Unauthorized') {
                    this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
                    return;
                }
                this.operaciones = data?.success && Array.isArray(data.operaciones) ? data.operaciones : [];
            } catch (e) {
                this.operaciones = [];
                this.toast('No se pudo cargar operaciones', 'error');
            }
        },

        openModal(mode) {
            this.modalMode = mode;
            this.modalOperacionTipo = 'entrada';
            this.modalMonto = '';
            this.modalConcepto = mode === 'open' ? 'APERTURA DE CAJA' : '';
            this.showModal = true;
        },

        async saveModal() {
            const monto = parseFloat(String(this.modalMonto || '').replace(/\./g, '').replace(',', '.'));
            if (!monto || monto <= 0) {
                this.toast('Monto invalido', 'warning');
                return;
            }
            if (this.modalMode !== 'open' && !String(this.modalConcepto || '').trim()) {
                this.toast('Ingrese concepto', 'warning');
                return;
            }

            this.saving = true;
            try {
                let action = 'insert';
                let payload = {};
                if (this.modalMode === 'open') {
                    action = 'open';
                    payload = { monto, id_caja: this.idCaja, id_empresa: this.idEmpresa };
                } else {
                    const tipoOperacion = this.modalMode === 'operacion' ? this.modalOperacionTipo : this.modalMode;
                    payload = {
                        tipo: tipoOperacion,
                        monto,
                        concepto: this.modalConcepto.trim(),
                        id_caja: this.idCaja,
                        id_empresa: this.idEmpresa,
                        payment_method: 'efectivo'
                    };
                }
                const res = await this.apiFetch(`api/caja.php?action=${action}&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.status === 401 || data?.error === 'Unauthorized') {
                    this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
                    return;
                }
                if (data?.success) {
                    this.toast(action === 'open' ? 'Caja abierta correctamente' : 'Operacion guardada');
                    this.showModal = false;
                    await this.loadData();
                } else {
                    this.toast(data?.message || data?.error || 'No se pudo guardar', 'error');
                }
            } catch (e) {
                this.toast('Error de conexion', 'error');
            } finally {
                this.saving = false;
            }
        },

        async closeCajaQuick() {
            const saldo = Number(this.summary?.saldo || 0);
            if (saldo <= 0) {
                this.toast('No hay saldo para cierre', 'warning');
                return;
            }
            if (!confirm(`Confirma cierre rapido por ${this.money(saldo)}?`)) {
                return;
            }
            this.saving = true;
            try {
                const payload = {
                    efectivo_contado: saldo,
                    monto_entregado_supervisor: saldo,
                    supervisor_nombre: 'Supervisor',
                    observacion: 'Cierre rapido desde movil',
                    valores: []
                };
                const res = await this.apiFetch(`api/caja.php?action=close&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}&soft_error=1`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.status === 401 || data?.error === 'Unauthorized') {
                    this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
                    return;
                }
                if (data?.success) {
                    this.toast('Caja cerrada correctamente');
                    await this.loadData();
                    return;
                }
                this.toast(data?.message || data?.error || 'No se pudo cerrar caja', 'error');
            } catch (e) {
                this.toast('Error de conexion', 'error');
            } finally {
                this.saving = false;
            }
        },

        exportCsv() {
            const rows = Array.isArray(this.operaciones) ? this.operaciones : [];
            if (rows.length === 0) {
                this.toast('No hay datos para exportar', 'warning');
                return;
            }
            const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
            const lines = [['Fecha', 'Concepto', 'Entrada', 'Salida', 'Saldo', 'Estado'].map(esc).join(',')];
            for (const r of rows) {
                lines.push([
                    this.formatDate(r.fecha),
                    r.concepto || '',
                    r.credito || 0,
                    r.debito || 0,
                    r.saldo_acumulado || 0,
                    Number(r.estado) === 1 ? 'Activo' : 'Anulado'
                ].map(esc).join(','));
            }
            const blob = new Blob(["\ufeff" + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `caja_mobile_${this.idCaja}_${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        formatDate(v) {
            if (!v) return '-';
            try { return new Date(v).toLocaleString('es-PY'); } catch (_) { return String(v); }
        },

        money(v) {
            return new Intl.NumberFormat('es-PY').format(Number(v || 0));
        }
    };
}
</script>
</body>
</html>
