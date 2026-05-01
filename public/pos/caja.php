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
$rol_usuario = (string)($_SESSION['role'] ?? '');

try {
    $pdo_init = getMasterConnection();
    $stmt_user = $pdo_init->prepare("SELECT login, role, caja_def, id_empresa FROM sec_users WHERE id_login = :id");
    $stmt_user->execute([':id' => $id_login]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        $usuario = (string)($user_data['login'] ?? $usuario);
        $rol_usuario = (string)($user_data['role'] ?? $rol_usuario);
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

// Obtener impresora configurada de la caja
$impresora_caja = '';
try {
    $dbu = $_SESSION['dbu'] ?? '';
    if ($dbu && $id_caja > 0) {
        $stmtImp = $pdo_init->prepare("SELECT impresora FROM {$dbu}.cajas WHERE id_caja = :id LIMIT 1");
        $stmtImp->execute([':id' => $id_caja]);
        $impresora_caja = trim((string)($stmtImp->fetchColumn() ?: ''));
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Caja</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@32.3.3/styles/ag-grid.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@32.3.3/styles/ag-theme-alpine.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@32.3.3/styles/ag-theme-alpine-dark.css">
    <script src="/public/assets/js/ag-grid-locale.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/ag-grid-enterprise@32.3.3/dist/ag-grid-enterprise.min.js" defer></script>
    <script src="../_lib/ag-grid/license.js?v=<?php echo @filemtime(__DIR__ . '/../_lib/ag-grid/license.js') ?: time(); ?>" defer></script>
    <script src="js/smx-printer.js?v=<?php echo @filemtime(__DIR__ . '/js/smx-printer.js') ?: time(); ?>" defer></script>
    <script src="js/caja.js?v=<?php echo @filemtime(__DIR__ . '/js/caja.js') ?: time(); ?>" defer></script>
    <script src="../assets/vendor/alpine.min.js?v=<?php echo @filemtime(__DIR__ . '/../assets/vendor/alpine.min.js') ?: time(); ?>" defer></script>
    <style>
        [x-cloak] { display: none !important; }
        .theme-dark { color-scheme: dark; }
        .theme-light { color-scheme: light; }
        .caja-app {
            --bg: #f1f5f9;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --panel-strong: #f1f5f9;
            --text: #0f172a;
            --text-soft: #475569;
            --border: #cbd5e1;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --thead: #e2e8f0;
            --row-odd: #ffffff;
            --row-even: #f8fafc;
            --row-hover: #e2e8f0;
        }
        .theme-dark.caja-app {
            --bg: #0b1220;
            --panel: #0f172a;
            --panel-soft: #1e293b;
            --panel-strong: #334155;
            --text: #e2e8f0;
            --text-soft: #94a3b8;
            --border: #334155;
            --input-bg: #2c3d56;
            --input-border: #425775;
            --thead: #0f172a;
            --row-odd: #0f172a;
            --row-even: #111b31;
            --row-hover: #1e293b;
        }
        .caja-app,
        .caja-root,
        .caja-header,
        .caja-main,
        .caja-box,
        .caja-table-wrap {
            background: var(--panel);
            color: var(--text);
        }
        .caja-app {
            background: var(--bg);
        }
        .caja-root,
        .caja-header,
        .caja-box {
            border-color: var(--border) !important;
        }
        .caja-soft {
            background: var(--panel-soft) !important;
            border-color: var(--border) !important;
            color: var(--text);
        }
        .caja-input {
            background: var(--input-bg) !important;
            border-color: var(--input-border) !important;
            color: var(--text) !important;
        }
        .caja-input::placeholder {
            color: var(--text-soft);
        }
        .caja-muted {
            color: var(--text-soft) !important;
        }
        .period-pill {
            border-color: var(--input-border) !important;
            background: var(--input-bg) !important;
        }
        .period-pill button {
            color: var(--text-soft);
        }
        .period-pill button.active-period {
            background: #3b82f6;
            color: #fff;
        }
        .menu-pop {
            background: var(--panel);
            border: 1px solid var(--border);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }
        .sx-list-shell {
            border-radius: 14px;
            overflow: hidden;
        }
        .sx-head-cell {
            font-size: 12px;
            letter-spacing: .06em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .sx-row {
            min-height: 62px;
        }
        .sx-chip {
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            display: inline-flex;
            align-items: center;
            line-height: 1;
        }
        .caja-grid {
            height: 100%;
            width: 100%;
            min-height: 320px;
        }
        .caja-grid.ag-theme-alpine,
        .caja-grid.ag-theme-alpine-dark {
            --ag-font-family: inherit;
            --ag-font-size: 13px;
            --ag-row-height: 48px;
            --ag-header-height: 42px;
            --ag-border-color: var(--border);
            --ag-background-color: var(--panel);
            --ag-foreground-color: var(--text);
            --ag-header-background-color: var(--thead);
            --ag-odd-row-background-color: var(--row-odd);
            --ag-row-hover-color: var(--row-hover);
            --ag-selected-row-background-color: color-mix(in srgb, #3b82f6 28%, transparent);
        }
        .caja-grid .ag-header-cell-label {
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 700;
            font-size: 11px;
        }
        .caja-grid .cell-salida {
            color: #dc2626;
            font-weight: 700;
        }
        .theme-dark .caja-grid .cell-salida {
            color: #fb7185;
        }
        .caja-grid .cell-entrada {
            color: #34d399;
            font-weight: 700;
        }
        .glass-pill {
            backdrop-filter: blur(14px) saturate(150%);
            -webkit-backdrop-filter: blur(14px) saturate(150%);
            border: 1px solid rgba(255, 255, 255, 0.22);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.3), 0 8px 22px rgba(0,0,0,.22);
            transition: transform .15s ease, filter .15s ease, box-shadow .15s ease;
        }
        .glass-pill:hover {
            transform: translateY(-1px);
            filter: brightness(1.08);
        }
        .theme-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #e2e8f0;
            background: linear-gradient(135deg, rgba(56,189,248,.22), rgba(99,102,241,.25));
        }
        .quick-action-btn {
            height: 38px;
            padding: 0 12px;
            border-radius: 12px;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .quick-open { background: linear-gradient(135deg, rgba(37,99,235,.45), rgba(29,78,216,.38)); }
        .quick-op { background: linear-gradient(135deg, rgba(16,185,129,.45), rgba(5,150,105,.35)); }
        .quick-close { background: linear-gradient(135deg, rgba(217,119,6,.45), rgba(180,83,9,.35)); }
        .toast-dock {
            position: fixed;
            right: 14px;
            bottom: 14px;
            z-index: 90;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            width: min(92vw, 460px);
            pointer-events: none;
        }
        .mac-toast {
            width: 100%;
            pointer-events: auto;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,.24);
            backdrop-filter: blur(14px) saturate(150%);
            -webkit-backdrop-filter: blur(14px) saturate(150%);
            box-shadow: 0 16px 38px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.24);
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.35;
            animation: toast-rise .26s ease-out;
        }
        .mac-toast.success {
            background: linear-gradient(135deg, rgba(16,185,129,.52), rgba(5,150,105,.42));
            color: #ecfdf5;
        }
        .mac-toast.warning {
            background:
                linear-gradient(160deg, rgba(255, 251, 214, .86), rgba(255, 242, 179, .78)),
                radial-gradient(circle at 18% 16%, rgba(255,255,255,.55), transparent 42%);
            color: #5b4500;
            border-color: rgba(234, 179, 8, .34);
            box-shadow: 0 16px 36px rgba(161, 98, 7, .18), inset 0 1px 0 rgba(255,255,255,.72);
        }
        .mac-toast.error {
            background: linear-gradient(135deg, rgba(239,68,68,.56), rgba(220,38,38,.45));
            color: #fff1f2;
        }
        @keyframes toast-rise {
            from { opacity: 0; transform: translateY(14px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .mac-toast-title {
            font-weight: 800;
            font-size: 14px;
            margin-bottom: 6px;
        }
        .mac-toast-row {
            display: flex;
            align-items: flex-end;
        }
        .mac-toast-msg {
            flex: 1;
            min-width: 0;
        }
    </style>
</head>
<body class="h-full">
    <div id="cajaAppRoot" data-id-caja="<?php echo (int)$id_caja; ?>" data-id-empresa="<?php echo (int)$id_empresa; ?>" data-printer="<?php echo htmlspecialchars($impresora_caja, ENT_QUOTES, 'UTF-8'); ?>" data-user-role="<?php echo htmlspecialchars($rol_usuario, ENT_QUOTES, 'UTF-8'); ?>" class="h-full caja-app" x-data="cajaNueva()" x-init="init()" :class="theme === 'dark' ? 'theme-dark' : 'theme-light'">
    <div class="h-full p-2">
        <div class="h-full rounded-xl border flex flex-col overflow-hidden caja-root">
            <header class="px-3 py-2 border-b caja-header">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <button
                            @click="window.location.href='index.php'"
                            class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm">
                            <span>&larr;</span>
                            <span>Volver al POS</span>
                        </button>
                        <div class="h-6 w-px caja-soft"></div>
                        <div class="text-sm truncate caja-muted">
                            Caja #<?php echo str_pad((string)$id_caja, 2, '0', STR_PAD_LEFT); ?> • <?php echo htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap justify-end">
                        <button @click="openModal('open')" class="quick-action-btn quick-open glass-pill"><span>🔓</span><span>Apertura</span></button>
                        <button @click="openModal('operacion')" class="quick-action-btn quick-op glass-pill"><span>🧾</span><span>Operaciones</span></button>
                        <button @click="closeCajaQuick()" class="quick-action-btn quick-close glass-pill"><span>🔒</span><span>Cierre</span></button>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-3 space-y-3 overflow-hidden flex flex-col caja-main">
                <section class="grid grid-cols-2 md:grid-cols-6 gap-2">
                    <div class="rounded-lg border p-2 caja-soft cursor-pointer transition hover:ring-2 hover:ring-emerald-400"
                        @click="togglePaymentFilter('EFECTIVO')"
                        :class="paymentMethodFilter === 'EFECTIVO' ? 'ring-2 ring-emerald-400 bg-emerald-500/10' : ''">
                        <div class="text-[11px] uppercase caja-muted">Efectivo</div>
                        <div class="text-lg font-bold text-emerald-400 text-right" x-text="money(summary.efectivo)"></div>
                    </div>
                    <div class="rounded-lg border p-2 caja-soft cursor-pointer transition hover:ring-2 hover:ring-sky-400"
                        @click="togglePaymentFilter('TARJETA')"
                        :class="paymentMethodFilter === 'TARJETA' ? 'ring-2 ring-sky-400 bg-sky-500/10' : ''">
                        <div class="text-[11px] uppercase caja-muted">Tarjeta</div>
                        <div class="text-lg font-bold text-sky-400 text-right" x-text="money(summary.tarjeta)"></div>
                    </div>
                    <div class="rounded-lg border p-2 caja-soft cursor-pointer transition hover:ring-2 hover:ring-violet-400"
                        @click="togglePaymentFilter('TRANSFERENCIA')"
                        :class="paymentMethodFilter === 'TRANSFERENCIA' ? 'ring-2 ring-violet-400 bg-violet-500/10' : ''">
                        <div class="text-[11px] uppercase caja-muted">Transfer</div>
                        <div class="text-lg font-bold text-violet-400 text-right" x-text="money(summary.transferencia)"></div>
                    </div>
                    <div class="rounded-lg border p-2 caja-soft cursor-pointer transition hover:ring-2 hover:ring-amber-400"
                        @click="togglePaymentFilter('QR')"
                        :class="paymentMethodFilter === 'QR' ? 'ring-2 ring-amber-400 bg-amber-500/10' : ''">
                        <div class="text-[11px] uppercase caja-muted">QR / PIX</div>
                        <div class="text-lg font-bold text-amber-400 text-right" x-text="money(summary.qr_pix)"></div>
                    </div>
                    <div class="rounded-lg border p-2 caja-soft cursor-pointer hover:ring-2 hover:ring-amber-400 transition" @click="showPendientesModal = true; loadPendientes()">
                        <div class="text-[11px] uppercase caja-muted flex items-center justify-between"><span>Fact. Pendientes</span><span class="font-bold text-amber-300" x-text="summary.facturas_pendientes_count || 0"></span></div>
                        <div class="text-lg font-bold text-amber-300 text-right" x-text="money(summary.facturas_pendientes_total)"></div>
                    </div>
                    <div class="rounded-lg border border-blue-500 bg-gradient-to-br from-blue-600 to-blue-700 p-2">
                        <div class="text-[11px] uppercase text-blue-200">Saldo Total</div>
                        <div class="text-lg font-bold text-white text-right" x-text="money(summary.saldo)"></div>
                    </div>
                </section>

                <section class="rounded-xl p-2 space-y-2 border caja-box caja-soft">
                    <div class="flex items-center gap-2 overflow-x-auto">
                        <div class="relative min-w-[280px] max-w-[280px]">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔎</span>
                            <input
                                x-model="filterSearch"
                                @input.debounce.250ms="loadData()"
                                type="text"
                                placeholder="Buscar por nro, cliente..."
                                class="h-10 w-full rounded-lg pl-10 pr-3 border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                        </div>

                        <div class="flex items-center gap-1 rounded-lg border p-1 min-w-max period-pill">
                            <template x-for="period in periods" :key="period.value">
                                <button
                                    @click="setPeriod(period.value)"
                                    :class="filterPeriod === period.value ? 'active-period' : ''"
                                    class="h-8 px-4 rounded-md text-[15px] font-semibold whitespace-nowrap"
                                    x-text="period.label">
                                </button>
                            </template>
                        </div>

                        <select
                            x-model="filterStatus"
                            @change="loadData()"
                            class="h-10 min-w-[160px] rounded-lg border px-4 font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                            <option value="activos">Activos</option>
                            <option value="anulados">Anulados</option>
                            <option value="todos">Todos</option>
                        </select>

                        <button
                            @click="clearFilters()"
                            class="h-10 w-10 text-2xl text-slate-300 hover:text-white"
                            title="Limpiar filtros">&times;</button>

                        <button
                            @click="exportCsv()"
                            class="h-10 px-4 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[15px] whitespace-nowrap">
                            Exportar Excel
                        </button>

                        <button
                            @click="openQrCobro()"
                            class="h-10 px-4 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-bold text-[15px] whitespace-nowrap">
                            Cobrar Factura
                        </button>

                        <button
                            @click="openDocumentosModal()"
                            class="h-10 px-4 rounded-lg bg-fuchsia-600 hover:bg-fuchsia-700 text-white font-bold text-[15px] whitespace-nowrap">
                            Cobrar Documento
                        </button>
                    </div>

                <div x-show="filterPeriod === 'custom'" class="flex items-center gap-2">
                    <input
                        type="date"
                        x-model="customDateFrom"
                        @change="applyCustomRange()"
                        class="h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                    <span class="text-slate-400">a</span>
                    <input
                        type="date"
                        x-model="customDateTo"
                        @change="applyCustomRange()"
                        class="h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                </div>
            </section>

                <section class="flex-1 min-h-0 rounded-lg border overflow-hidden sx-list-shell caja-table-wrap flex flex-col">
                    <div class="px-3 py-2 border-b flex items-center justify-between gap-3 caja-soft">
                        <div class="flex items-center gap-2 min-w-0">
                            <template x-if="paymentMethodFilterLabel">
                                <button @click="clearPaymentFilter()"
                                    class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-bold text-white">
                                    <span x-text="'Filtro: ' + paymentMethodFilterLabel"></span>
                                    <span class="text-slate-300">&times;</span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <div class="flex-1 min-h-[320px] relative">
                        <div x-ref="grid" class="caja-grid" :class="theme === 'dark' ? 'ag-theme-alpine-dark' : 'ag-theme-alpine'"></div>
                        <div x-show="gridError" class="absolute inset-0 flex items-center justify-center text-sm font-semibold text-rose-400 bg-black/20 px-4 text-center" x-text="gridError"></div>
                        <div x-show="loading" class="absolute inset-0 bg-black/20 flex items-center justify-center text-sm font-semibold">Cargando...</div>
                        <div x-show="!loading && totalOperaciones === 0" class="absolute inset-0 flex items-center justify-center text-sm caja-muted">
                            Sin operaciones para los filtros actuales.
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div x-cloak x-show="showModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showModal = false">
        <div class="w-full max-w-md rounded-xl border overflow-hidden caja-box">
            <div class="px-4 py-3 border-b caja-soft">
                <h3 class="font-semibold" x-text="modalTitle"></h3>
            </div>
            <div class="p-4 space-y-3" x-ref="modalForm" @keydown.enter.prevent="focusNextField($event)">
                <div x-show="modalMode === 'operacion'" class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs caja-muted mb-1">Operacion</label>
                            <select x-model="opForm.tipo" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                                <option value="entrada">Entrada</option>
                                <option value="salida">Salida</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs caja-muted mb-1">Referencia</label>
                            <select x-model="opForm.referencia_tipo" @change="onReferenciaTipoChanged()" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                                <option value="contacto">Contacto</option>
                                <option value="caja">Caja</option>
                                <option value="banco">Banco</option>
                                <option value="cuenta">Cuenta</option>
                            </select>
                        </div>
                    </div>
                    <div class="relative">
                        <label class="block text-xs caja-muted mb-1">Buscador inteligente</label>
                        <input x-model="opForm.referencia_texto" @focus="searchReferenciaOptions()" @input.debounce.220ms="searchReferenciaOptions()" @keydown="onReferenciaKeydown($event)" type="text" placeholder="Buscar por nombre, codigo o pista..." class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                        <div class="mt-1 text-[11px] caja-muted">Pistas: nombre, codigo, RUC/CI o descripcion.</div>
                        <div x-show="refLoading" class="absolute right-3 top-9 text-xs caja-muted">Buscando...</div>
                        <div x-show="refOptions.length > 0" class="absolute z-20 mt-1 w-full max-h-44 overflow-auto rounded-lg border caja-soft shadow-xl">
                            <template x-for="(item, idx) in refOptions" :key="item.id">
                                <button @click="selectReferencia(item)" type="button" :class="idx === refActiveIndex ? 'bg-blue-500/25' : ''" class="w-full text-left px-2 py-1.5 hover:bg-blue-500/20 leading-tight">
                                    <div class="text-[12px] font-semibold truncate" x-text="item.nombre"></div>
                                    <div class="text-[10px] caja-muted truncate" x-text="item.extra || ('ID: ' + item.id)"></div>
                                </button>
                            </template>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div x-show="opForm.referencia_tipo === 'banco'">
                            <label class="block text-xs caja-muted mb-1">Numero</label>
                            <input x-model="opForm.numero" type="text" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                        </div>
                        <div :class="opForm.referencia_tipo === 'banco' ? '' : 'col-span-2'">
                            <label class="block text-xs caja-muted mb-1">Importe</label>
                            <input x-model="opForm.importe" @input="formatOperacionImporteInput($event)" @blur="formatOperacionImporteInput($event)" type="text" inputmode="numeric" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input text-right tabular-nums">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div x-show="opForm.referencia_tipo === 'banco'">
                            <label class="block text-xs caja-muted mb-1">Fecha pago</label>
                            <input x-model="opForm.fecha_pago" type="date" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                        </div>
                        <div x-show="opForm.referencia_tipo !== 'banco'" class="col-span-2"></div>
                    </div>
                    <div x-show="opForm.referencia_tipo === 'banco'">
                        <label class="block text-xs caja-muted mb-1">Beneficiario</label>
                        <input x-model="opForm.beneficiario" type="text" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                    </div>
                    <div>
                        <label class="block text-xs caja-muted mb-1">Concepto</label>
                        <input x-model="opForm.concepto" type="text" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                    </div>
                </div>

                <div x-show="modalMode !== 'operacion'">
                    <div>
                        <label class="block text-xs caja-muted mb-1">Monto</label>
                        <input x-model="modalMonto" @input="formatModalMontoInput($event)" @blur="formatModalMontoInput($event)" type="text" inputmode="numeric" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input text-right tabular-nums">
                    </div>
                    <div x-show="modalMode !== 'open'">
                        <label class="block text-xs caja-muted mb-1">Concepto</label>
                        <input x-model="modalConcepto" type="text" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                    </div>
                </div>
            </div>
            <div class="px-4 py-3 border-t flex justify-end gap-2 caja-soft">
                <button @click="showModal = false" class="px-4 py-2 rounded-lg border caja-input">Cancelar</button>
                <button @click="saveModal()" :disabled="saving" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50">
                    <span x-show="!saving">Guardar</span>
                    <span x-show="saving">Guardando...</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Facturas Pendientes -->
    <div x-cloak x-show="showPendientesModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showPendientesModal = false">
        <div class="w-full max-w-6xl rounded-xl border overflow-hidden caja-box flex flex-col" style="max-height:88vh">
            <div class="px-4 py-3 border-b caja-soft flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Facturas Pendientes de Cobro</h3>
                    <div class="text-xs caja-muted">Buscador por pista: número, cliente o RUC.</div>
                </div>
                <button @click="showPendientesModal = false" class="text-2xl leading-none caja-muted hover:text-white">&times;</button>
            </div>
            <div class="p-4 border-b caja-soft space-y-4">
                <div class="flex items-center gap-2">
                    <input x-model="pendientesSearch" @input.debounce.250ms="loadPendientes()" type="text" placeholder="Buscar factura, cliente o RUC..." class="flex-1 h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-amber-500 caja-input">
                    <button @click="loadPendientes()" class="h-10 px-4 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold">Buscar</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="rounded-lg border border-white/10 p-3 caja-soft">
                        <div class="text-[11px] caja-muted uppercase">Facturas</div>
                        <div class="mt-1 text-xl font-bold text-white" x-text="pendientesList.length"></div>
                    </div>
                    <div class="rounded-lg border border-white/10 p-3 caja-soft">
                        <div class="text-[11px] caja-muted uppercase">Total Facturado</div>
                        <div class="mt-1 text-xl font-bold text-sky-300" x-text="money(pendientesList.reduce((s,f) => s + Number(f.total || 0), 0))"></div>
                    </div>
                    <div class="rounded-lg border border-white/10 p-3 caja-soft">
                        <div class="text-[11px] caja-muted uppercase">Total Pendiente</div>
                        <div class="mt-1 text-xl font-bold text-amber-300" x-text="money(pendientesList.reduce((s,f) => s + Number(f.saldo || 0), 0))"></div>
                    </div>
                </div>
            </div>
            <div class="flex-1 overflow-auto">
                <div x-show="pendientesLoading" class="p-8 text-center caja-muted">Cargando...</div>
                <div x-show="!pendientesLoading && pendientesList.length === 0" class="p-8 text-center caja-muted">No hay facturas pendientes.</div>
                <table x-show="!pendientesLoading && pendientesList.length > 0" class="w-full text-sm">
                    <thead class="caja-soft sticky top-0">
                        <tr class="border-b">
                            <th class="px-3 py-2 text-left">Nro. Factura</th>
                            <th class="px-3 py-2 text-left">Fecha</th>
                            <th class="px-3 py-2 text-left">Cliente</th>
                            <th class="px-3 py-2 text-left">RUC/CI</th>
                            <th class="px-3 py-2 text-right">Total</th>
                            <th class="px-3 py-2 text-right">Pendiente</th>
                            <th class="px-3 py-2 text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="f in pendientesList" :key="f.id_factura">
                            <tr class="border-b border-white/5 hover:bg-white/5">
                                <td class="px-3 py-3 align-top">
                                    <div class="font-mono font-semibold" x-text="f.nro_factura || ('#' + f.id_factura)"></div>
                                    <div class="text-[11px] caja-muted" x-text="'#' + f.id_factura"></div>
                                </td>
                                <td class="px-3 py-3 align-top whitespace-nowrap" x-text="f.fecha"></td>
                                <td class="px-3 py-3 align-top">
                                    <div class="font-semibold" x-text="f.cliente || '—'"></div>
                                </td>
                                <td class="px-3 py-3 align-top whitespace-nowrap" x-text="f.ruc || '—'"></td>
                                <td class="px-3 py-3 align-top text-right tabular-nums text-slate-200" x-text="money(f.total)"></td>
                                <td class="px-3 py-3 align-top text-right">
                                    <div class="inline-flex items-center rounded-full bg-amber-500/15 px-3 py-1 font-bold tabular-nums text-amber-300" x-text="money(f.saldo)"></div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <div class="inline-flex items-center gap-1 flex-wrap justify-center">
                                        <button @click="openCobroModal(f)" class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold inline-flex items-center gap-1">Cobrar</button>
                                        <button @click="printPendiente(f)" class="px-3 py-1.5 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold inline-flex items-center gap-1"><i class="fas fa-print"></i> Pendiente</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="caja-soft font-bold">
                        <tr class="border-t">
                            <td colspan="4" class="px-3 py-2">Total (<span x-text="pendientesList.length"></span> facturas)</td>
                            <td class="px-3 py-2 text-right tabular-nums" x-text="money(pendientesList.reduce((s,f) => s + Number(f.total || 0), 0))"></td>
                            <td class="px-3 py-2 text-right tabular-nums text-amber-300" x-text="money(pendientesList.reduce((s,f) => s + Number(f.saldo || 0), 0))"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="px-4 py-3 border-t caja-soft flex justify-end">
                <button @click="showPendientesModal = false" class="px-4 py-2 rounded-lg border caja-input">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal Documentos Pendientes -->
    <div x-cloak x-show="showDocumentosModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[55] flex items-center justify-center p-4" @click.self="showDocumentosModal = false">
        <div class="w-full max-w-6xl rounded-xl border overflow-hidden caja-box">
            <div class="px-4 py-3 border-b caja-soft flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Cobro de Documentos</h3>
                    <div class="text-xs caja-muted">Buscador por pista: concepto, cliente, RUC o cuota.</div>
                </div>
                <button @click="showDocumentosModal = false" class="text-2xl leading-none caja-muted hover:text-white">&times;</button>
            </div>
            <div class="p-4 space-y-4">
                <div class="flex items-center gap-2">
                    <input x-model="documentosSearch" @input.debounce.250ms="loadPendingDocuments()" type="text" placeholder="Buscar documento, cliente, RUC, factura..." class="flex-1 h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-fuchsia-500 caja-input">
                    <button @click="loadPendingDocuments()" class="h-10 px-4 rounded-lg bg-fuchsia-600 hover:bg-fuchsia-700 text-white text-sm font-bold">Buscar</button>
                </div>
                <div class="rounded-lg border overflow-hidden">
                    <div x-show="documentosLoading" class="p-8 text-center caja-muted">Cargando documentos...</div>
                    <div x-show="!documentosLoading && documentosList.length === 0" class="p-8 text-center caja-muted">No hay documentos pendientes para esa búsqueda.</div>
                    <table x-show="!documentosLoading && documentosList.length > 0" class="w-full text-sm">
                        <thead class="caja-soft sticky top-0">
                            <tr class="border-b">
                                <th class="px-3 py-2 text-left">Documento</th>
                                <th class="px-3 py-2 text-left">Cliente</th>
                                <th class="px-3 py-2 text-left">Factura</th>
                                <th class="px-3 py-2 text-left">Vence</th>
                                <th class="px-3 py-2 text-right">Pendiente</th>
                                <th class="px-3 py-2 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="doc in documentosList" :key="doc.id_documento">
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="px-3 py-2">
                                        <div class="font-semibold" x-text="doc.cantidad_cuota || ('#' + doc.id_documento)"></div>
                                        <div class="text-xs caja-muted truncate max-w-[260px]" x-text="doc.concepto || '-'"></div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div x-text="doc.cliente || '-'"></div>
                                        <div class="text-xs caja-muted" x-text="doc.ruc || ''"></div>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs" x-text="doc.id_factura ? ('#' + doc.id_factura) : '-'"></td>
                                    <td class="px-3 py-2" x-text="doc.fecha_vencimiento || '-'"></td>
                                    <td class="px-3 py-2 text-right tabular-nums font-bold text-amber-300" x-text="money(doc.pendiente || 0)"></td>
                                    <td class="px-3 py-2 text-center">
                                        <button @click="openCobroDocumentoModal(doc)" class="px-3 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold">Cobrar</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Cobro Documento -->
    <div x-cloak x-show="showCobroDocumentoModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[65] flex items-center justify-center p-4" @click.self="closeCobroDocumentoModal()">
        <div class="w-full max-w-4xl rounded-xl border overflow-hidden caja-box">
            <div class="px-4 py-3 border-b caja-soft flex items-center justify-between">
                <div>
                    <h3 class="font-semibold">Cobrar Documento</h3>
                    <div class="text-xs caja-muted" x-text="cobroDocumentoForm.cliente || ''"></div>
                </div>
                <button @click="closeCobroDocumentoModal()" class="text-2xl leading-none caja-muted hover:text-white">&times;</button>
            </div>
            <div class="p-4 space-y-4 max-h-[78vh] overflow-auto">
                <div x-show="cobroDocumentoDetalleLoading" class="p-8 text-center caja-muted">Cargando documento...</div>
                <div x-show="!cobroDocumentoDetalleLoading" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="space-y-3">
                        <div class="rounded-lg border p-3 caja-soft">
                            <div class="text-xs caja-muted uppercase">Documento</div>
                            <div class="mt-1 font-bold" x-text="cobroDocumentoForm.cuota || ('#' + (cobroDocumentoForm.id_documento || 0))"></div>
                            <div class="text-sm caja-muted mt-1" x-text="cobroDocumentoForm.concepto || '-'"></div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-sm">
                            <div class="rounded border border-white/10 p-3">
                                <div class="text-[11px] caja-muted">Total</div>
                                <div class="font-bold" x-text="money(cobroDocumentoForm.total || 0)"></div>
                            </div>
                            <div class="rounded border border-white/10 p-3">
                                <div class="text-[11px] caja-muted">Pagado</div>
                                <div class="font-bold text-emerald-300" x-text="money(cobroDocumentoForm.pagado || 0)"></div>
                            </div>
                            <div class="rounded border border-white/10 p-3">
                                <div class="text-[11px] caja-muted">Pendiente</div>
                                <div class="font-bold text-amber-300" x-text="money(cobroDocumentoForm.pendiente || 0)"></div>
                            </div>
                        </div>
                        <div class="rounded-lg border p-3 caja-soft">
                            <label class="block text-xs caja-muted mb-1">Monto a cobrar</label>
                            <input :value="money(documentCobroMontoObjetivo)" disabled class="w-full h-10 px-3 rounded-lg border caja-input font-bold text-right">
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-[10px] font-bold caja-muted uppercase tracking-[0.15em] mb-1.5">Tipo de Cobro</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" @click="cobroDocumentoForm.metodo = 'EFECTIVO'" :class="cobroDocumentoForm.metodo === 'EFECTIVO' ? 'border-emerald-400 bg-emerald-500/20 text-emerald-200' : 'border-slate-600 bg-slate-800 text-slate-300'" class="py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all">Efectivo</button>
                                <button type="button" @click="cobroDocumentoForm.metodo = 'TARJETA'" :class="cobroDocumentoForm.metodo === 'TARJETA' ? 'border-sky-400 bg-sky-500/20 text-sky-200' : 'border-slate-600 bg-slate-800 text-slate-300'" class="py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all">Tarjeta</button>
                                <button type="button" @click="cobroDocumentoForm.metodo = 'TRANSFERENCIA'" :class="cobroDocumentoForm.metodo === 'TRANSFERENCIA' ? 'border-violet-400 bg-violet-500/20 text-violet-200' : 'border-slate-600 bg-slate-800 text-slate-300'" class="py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all">Transfer.</button>
                                <button type="button" @click="cobroDocumentoForm.metodo = 'QR'" :class="cobroDocumentoForm.metodo === 'QR' ? 'border-amber-400 bg-amber-500/20 text-amber-200' : 'border-slate-600 bg-slate-800 text-slate-300'" class="py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all">QR</button>
                                <button type="button" @click="cobroDocumentoForm.metodo = 'PIX'" :class="cobroDocumentoForm.metodo === 'PIX' ? 'border-pink-400 bg-pink-500/20 text-pink-200' : 'border-slate-600 bg-slate-800 text-slate-300'" class="py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all">PIX</button>
                            </div>
                        </div>

                        <div x-show="cobroDocumentoForm.metodo === 'EFECTIVO'" class="rounded-lg border p-3 caja-soft space-y-2">
                            <label class="block text-xs caja-muted">Entrega</label>
                            <input :value="formatMontoGs(cobroDocumentoForm.efectivo_entrega || 0)" @input="formatCobroDocumentoEfectivoEntregaInput($event)" class="w-full h-10 px-3 rounded-lg border caja-input text-right">
                            <div class="text-xs caja-muted">Vuelto: <span class="font-bold text-white" x-text="money(documentCobroVuelto > 0 ? documentCobroVuelto : 0)"></span></div>
                        </div>

                        <div x-show="['TARJETA','TRANSFERENCIA','QR','PIX'].includes(cobroDocumentoForm.metodo)" class="rounded-lg border p-3 caja-soft">
                            <label class="block text-xs caja-muted mb-1">Referencia / Comprobante</label>
                            <input x-model="cobroDocumentoForm.referencia" type="text" class="w-full h-10 px-3 rounded-lg border caja-input">
                        </div>

                        <div x-show="documentCobroValidationMessage" class="text-xs text-rose-400" x-text="documentCobroValidationMessage"></div>
                    </div>
                </div>
            </div>
            <div class="px-4 py-3 border-t caja-soft flex justify-end gap-2">
                <button @click="closeCobroDocumentoModal()" class="px-4 py-2 rounded-lg border caja-input">Cancelar</button>
                <button @click="submitCobroDocumento()" :disabled="cobroDocumentoSaving || !canSubmitCobroDocumento" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold disabled:opacity-50">
                    <span x-show="!cobroDocumentoSaving">Cobrar</span>
                    <span x-show="cobroDocumentoSaving">Guardando...</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Cobro de Factura Pendiente -->
    <div x-cloak x-show="showCobroModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] flex items-center justify-center p-4" @click.self="closeCobroModal()">
        <div class="w-full max-w-5xl rounded-xl border overflow-hidden caja-box">
            <div class="px-4 py-3 border-b caja-soft">
                <h3 class="font-semibold">Cobrar Factura</h3>
                <div class="text-xs caja-muted mt-1" x-text="'Fact. ' + (cobroForm.nro_factura || '') + ' — ' + (cobroForm.cliente || 'Sin nombre')"></div>
            </div>
            <div class="p-4 space-y-4 max-h-[78vh] overflow-auto">
                <section class="rounded-lg border p-3 caja-soft space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-semibold">1. Captura de Factura</h4>
                        <span class="text-[11px] caja-muted">Escaneá QR para continuar</span>
                    </div>
                    <div>
                        <label class="block text-xs caja-muted mb-1">QR ticket pendiente</label>
                        <div class="flex gap-2">
                            <input x-ref="qrInput" x-model="qrInput" @input="autoParseQr()" @keydown.enter.prevent="parseQrInput()" type="text" placeholder='Escanear QR o pegar JSON/URL' class="flex-1 h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                            <button @click="qrCameraActive ? stopQrCamera() : startQrCamera()" class="px-3 h-10 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold" x-text="qrCameraActive ? 'Detener' : 'Cargar'"></button>
                        </div>
                        <div x-show="qrCameraActive" class="mt-2 rounded-lg overflow-hidden border border-white/10">
                            <video x-ref="qrVideo" class="w-full h-56 object-cover bg-black" playsinline muted></video>
                        </div>
                        <div class="text-[11px] caja-muted mt-1">Tip: también podés pegar el contenido del QR y presionar Enter.</div>
                        <div x-show="qrError" class="text-xs text-rose-400 mt-1" x-text="qrError"></div>
                    </div>
                </section>

                <section x-show="cobroForm.id_factura > 0" x-transition class="rounded-lg border p-3 caja-soft space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-semibold">2. Factura y Cobro</h4>
                        <span class="text-[11px] caja-muted" x-text="'Factura #' + (cobroForm.nro_factura || cobroForm.id_factura)"></span>
                    </div>
                    <div x-show="cobroDetalleLoading" class="text-xs caja-muted">Cargando detalle de factura...</div>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs caja-muted mb-1">Cliente</label>
                                <input x-model="cobroForm.cliente" type="text" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded border border-white/10 p-2">
                                    <div class="text-[11px] caja-muted">Total factura</div>
                                    <div class="font-bold" x-text="money(cobroForm.total)"></div>
                                </div>
                                <div class="rounded border border-white/10 p-2">
                                    <div class="text-[11px] caja-muted">Cobro</div>
                                    <div class="font-bold text-amber-300">Total</div>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs font-semibold caja-muted mb-2">Ítems de la factura</div>
                                <div class="rounded border border-white/10 overflow-hidden">
                                    <div class="max-h-[360px] overflow-auto">
                                        <table class="w-full text-xs">
                                            <thead class="caja-soft sticky top-0">
                                                <tr class="border-b border-white/10">
                                                    <th class="text-left px-2 py-2">Foto</th>
                                                    <th class="text-left px-2 py-2">Producto</th>
                                                    <th class="text-right px-2 py-2">Cant.</th>
                                                    <th class="text-right px-2 py-2">Precio</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-if="!cobroDetalleLoading && (!cobroDetalle.items || cobroDetalle.items.length === 0)">
                                                    <tr><td colspan="4" class="px-2 py-3 text-center caja-muted">Sin ítems disponibles.</td></tr>
                                                </template>
                                                <template x-for="it in (cobroDetalle.items || [])" :key="`${it.idproducto || 0}-${it.producto || ''}`">
                                                    <tr class="border-b border-white/5">
                                                        <td class="px-2 py-2">
                                                            <img :src="it.imagen_url" alt="img" class="w-10 h-10 rounded object-cover border border-white/10">
                                                        </td>
                                                        <td class="px-2 py-2">
                                                            <div class="font-semibold" x-text="it.producto || 'Producto'"></div>
                                                        </td>
                                                        <td class="px-2 py-2 text-right tabular-nums" x-text="it.cantidad || 0"></td>
                                                        <td class="px-2 py-2 text-right tabular-nums" x-text="money(it.precio || 0)"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-[10px] font-bold caja-muted uppercase tracking-[0.15em] mb-1.5">Tipo Documento</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button type="button"
                                        @click="cobroForm.tipo_documento = 'NOTA'"
                                        :class="cobroForm.tipo_documento === 'NOTA' ? 'border-blue-500 bg-blue-500/20 text-blue-200' : 'border-slate-600 bg-slate-800 text-slate-300'"
                                        class="w-full py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all text-center">
                                        📋 Nota de Control
                                    </button>
                                    <button type="button"
                                        @click="cobroForm.tipo_documento = 'FACTURA'"
                                        :class="cobroForm.tipo_documento === 'FACTURA' ? 'border-blue-500 bg-blue-500/20 text-blue-200' : 'border-slate-600 bg-slate-800 text-slate-300'"
                                        class="w-full py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all text-center">
                                        📝 Factura Autografiada
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold caja-muted uppercase tracking-[0.15em] mb-1.5">Forma de Pago</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button type="button" @click="cobroForm.metodo = 'EFECTIVO'"
                                        :class="cobroForm.metodo === 'EFECTIVO' ? 'ring-2 ring-emerald-300/80 border-emerald-400 bg-emerald-500/20' : 'border-slate-600 bg-slate-800'"
                                        class="py-3 px-2 rounded-xl text-xs font-black border transition-all flex flex-col items-center justify-center gap-1">
                                        <span class="text-xl">💵</span><span>Efectivo</span>
                                    </button>
                                    <button type="button" @click="cobroForm.metodo = 'TARJETA'"
                                        :class="cobroForm.metodo === 'TARJETA' ? 'ring-2 ring-sky-300/80 border-sky-400 bg-sky-500/20' : 'border-slate-600 bg-slate-800'"
                                        class="py-3 px-2 rounded-xl text-xs font-black border transition-all flex flex-col items-center justify-center gap-1">
                                        <span class="text-xl">💳</span><span>Tarjeta</span>
                                    </button>
                                    <button type="button" @click="cobroForm.metodo = 'TRANSFERENCIA'"
                                        :class="cobroForm.metodo === 'TRANSFERENCIA' ? 'ring-2 ring-violet-300/80 border-violet-400 bg-violet-500/20' : 'border-slate-600 bg-slate-800'"
                                        class="py-3 px-2 rounded-xl text-xs font-black border transition-all flex flex-col items-center justify-center gap-1">
                                        <span class="text-xl">🏦</span><span>Transfer.</span>
                                    </button>
                                    <button type="button" @click="cobroForm.metodo = 'QR'"
                                        :class="cobroForm.metodo === 'QR' ? 'ring-2 ring-fuchsia-300/80 border-fuchsia-400 bg-fuchsia-500/20' : 'border-slate-600 bg-slate-800'"
                                        class="py-3 px-2 rounded-xl text-xs font-black border transition-all flex flex-col items-center justify-center gap-1">
                                        <span class="text-xl">📱</span><span>Pix</span>
                                    </button>
                                </div>
                            </div>
                            <div x-show="cobroForm.metodo === 'EFECTIVO'" class="space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs caja-muted mb-1">Entrega</label>
                                        <input type="text" inputmode="numeric"
                                            :value="formatMontoGs(cobroForm.efectivo_entrega)"
                                            @input="formatCobroEfectivoEntregaInput($event)"
                                            @blur="formatCobroEfectivoEntregaInput($event)"
                                            @focus="$event.target.select()"
                                            class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input text-right tabular-nums">
                                    </div>
                                    <div>
                                        <label class="block text-xs caja-muted mb-1">Vuelto</label>
                                        <input type="text" readonly
                                            :value="money(cobroVuelto)"
                                            :class="cobroVuelto < 0 ? 'text-red-400' : ''"
                                            class="w-full h-10 px-3 rounded-lg border caja-input font-bold text-right tabular-nums">
                                    </div>
                                </div>
                            </div>

                            <div x-show="cobroForm.metodo === 'TARJETA'" class="space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs caja-muted mb-1">Tipo Tarjeta</label>
                                        <select x-model="cobroForm.card_financing_type" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                                            <option value="credito">Crédito</option>
                                            <option value="debito">Débito</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs caja-muted mb-1">Procesador</label>
                                        <select x-model="cobroForm.card_processor" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                                            <option value="bancard">Bancard</option>
                                            <option value="bepsa">Bepsa</option>
                                            <option value="dinelco">Dinelco</option>
                                            <option value="otro">Otro</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs caja-muted mb-1">Cuotas</label>
                                        <input type="number" min="1" x-model="cobroForm.card_installments" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                                    </div>
                                    <div>
                                        <label class="block text-xs caja-muted mb-1">Voucher/NSU</label>
                                        <input x-model="cobroForm.referencia" type="text" placeholder="Referencia terminal" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                                    </div>
                                </div>
                            </div>

                            <div x-show="cobroForm.metodo === 'TRANSFERENCIA'" class="space-y-2">
                                <label class="block text-xs caja-muted mb-1">Referencia Transferencia</label>
                                <input x-model="cobroForm.referencia" type="text" placeholder="Comprobante transferencia" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                            </div>

                            <div x-show="cobroForm.metodo === 'QR'" class="space-y-2">
                                <label class="block text-xs caja-muted mb-1">Referencia PIX (TXID)</label>
                                <input x-model="cobroForm.referencia" type="text" placeholder="Código de transacción PIX" class="w-full h-10 px-3 rounded-lg border focus:outline-none focus:ring-2 focus:ring-blue-500 caja-input">
                            </div>

                        </div>
                    </div>
                </div>
                </section>
            <div class="px-4 py-3 border-t flex justify-end gap-2 caja-soft">
                <div class="flex-1 text-xs text-amber-300 self-center" x-show="!canSubmitCobro" x-text="cobroValidationMessage"></div>
                <button @click="closeCobroModal()" class="px-4 py-2 rounded-lg border caja-input">Cancelar</button>
                <button @click="submitCobro()" :disabled="cobroSaving || !canSubmitCobro" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold disabled:opacity-50">
                    <span x-show="!cobroSaving">Cobrar</span>
                    <span x-show="cobroSaving">Procesando...</span>
                </button>
            </div>
        </div>
    </div>

    <div class="toast-dock">
        <template x-for="t in (typeof toasts === 'undefined' ? [] : toasts)" :key="t.id">
            <div x-show="t.show" x-transition class="mac-toast"
                :class="t.type === 'error' ? 'error' : (t.type === 'warning' ? 'warning' : 'success')">
                <div class="mac-toast-title" x-text="t.type === 'warning' ? 'Alerta' : (t.type === 'error' ? 'Error' : 'Confirmado')"></div>
                <div class="mac-toast-row">
                    <div class="mac-toast-msg" x-text="t.message"></div>
                </div>
            </div>
        </template>
    </div>
    </div>

</body>
</html>
