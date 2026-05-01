<?php

/**
 * Cobros Varios - Formulario Alpine.js
 * Registro de cobros a clientes y cuentas
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int) $_SESSION['id_empresa'] : 169;
$id_login = isset($_SESSION['id_login']) ? (int) $_SESSION['id_login'] : 0;

// Cargar nombre de empresa
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

$nombreEmpresa = 'Empresa';
try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->exec("SET NAMES utf8");
    $stmt = $pdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $empresaDB = $stmt->fetchColumn();
    if ($empresaDB) $nombreEmpresa = $empresaDB;
} catch (Exception $e) { /* Silenciar */
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cobros Varios</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
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

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style type="text/tailwindcss">
        :root { 
            color-scheme: light; 
            --bg: rgba(243, 246, 251, 0.45); 
            --text: #1f2933; 
            --border-color: rgba(0,0,0,0.4); 
        }
        html.dark { 
            color-scheme: dark; 
            --bg: rgba(15, 23, 42, 0.60); 
            --text: #f1f5f9; 
            --border-color: rgba(255,255,255,0.4);
        }
        
        html { height: 100%; }
        html, body { width: 100%; }
        
        body { 
            font-family: "Segoe UI", "Roboto", sans-serif; 
            margin: 12px; 
            padding: 0; 
            background: var(--bg); 
            color: var(--text); 
            min-height: calc(100vh - 24px); 
            box-sizing: border-box; 
            border: 1px solid var(--border-color);
            border-radius: 20px;
            backdrop-filter: blur(8px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transform: translateZ(0); 
            position: relative;
        }

        .input-field {
            width: 100%;
            border-radius: 0.5rem;
            padding-left: 1rem;
            padding-right: 1rem;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
            outline: 2px solid transparent;
            outline-offset: 2px;
            border-width: 1px;
            transition-property: all;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 150ms;
        }
        :where(html:not(.dark)) .input-field {
            background-color: rgb(255 255 255);
            border-color: rgb(203 213 225);
        }
        :where(html.dark) .input-field {
            background-color: #1e293b;
            border-color: #475569;
        }
        .input-field:focus {
            --tw-ring-offset-shadow: var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);
            --tw-ring-shadow: var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color);
            box-shadow: var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow, 0 0 #0000);
            --tw-ring-opacity: 1;
            --tw-ring-color: rgb(59 130 246 / var(--tw-ring-opacity));
            border-color: transparent;
        }

        .btn-primary {
            background-color: rgb(37 99 235);
            color: rgb(255 255 255);
            font-weight: 700;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition-property: all;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 150ms;
            box-shadow: 0 10px 15px -3px rgb(59 130 246 / 0.2), 0 4px 6px -4px rgb(59 130 246 / 0.2);
        }
        .btn-primary:hover {
            background-color: rgb(29 78 216);
        }

        .btn-secondary {
            font-weight: 700;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
            border-radius: 0.5rem;
            transition-property: all;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 150ms;
        }
        :where(html:not(.dark)) .btn-secondary {
            background-color: rgb(226 232 240);
            color: rgb(51 65 85);
        }
        :where(html:not(.dark)) .btn-secondary:hover {
            background-color: rgb(203 213 225);
        }
        :where(html.dark) .btn-secondary {
            background-color: rgb(51 65 85);
            color: rgb(255 255 255);
        }
        :where(html.dark) .btn-secondary:hover {
            background-color: rgb(71 85 105);
        }
    </style>

    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && systemDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>

<body x-data="cobrosApp()" x-init="init()" class="text-gray-900 dark:text-white">

    <!-- Toast Notifications -->
    <div class="fixed top-4 right-4 z-[9999] space-y-2" x-show="toasts.length > 0">
        <template x-for="(toast, index) in toasts" :key="index">
            <div
                x-show="toast.visible"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 transform translate-x-8"
                x-transition:enter-end="opacity-100 transform translate-x-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                :class="{
                    'bg-green-600': toast.type === 'success',
                    'bg-red-600': toast.type === 'error',
                    'bg-blue-600': toast.type === 'info',
                    'bg-yellow-500': toast.type === 'warning'
                }"
                class="text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 min-w-[280px]">
                <i :class="{
                    'fa-check-circle': toast.type === 'success',
                    'fa-times-circle': toast.type === 'error',
                    'fa-info-circle': toast.type === 'info',
                    'fa-exclamation-triangle': toast.type === 'warning'
                }" class="fa-solid text-lg"></i>
                <span x-text="toast.message" class="flex-1 text-sm font-medium"></span>
                <button @click="removeToast(index)" class="opacity-70 hover:opacity-100">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
        </template>
    </div>

    <!-- Header -->
    <header class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-[19px]">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-black tracking-tight text-slate-900 dark:text-white flex items-center gap-2">
                <span class="bg-green-600 text-white px-2 py-0.5 rounded shadow-lg shadow-green-500/20">$</span>
                <span>Cobros Varios</span>
            </h1>
            <div class="w-px h-6 bg-slate-200 dark:bg-slate-700 hidden lg:block"></div>
            <span class="hidden lg:block text-slate-400 font-medium text-sm"><?php echo htmlspecialchars($nombreEmpresa); ?></span>
        </div>
        <button @click="window.history.back()" class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-white transition-colors">
            <i class="fa-solid fa-arrow-left mr-2"></i> Volver
        </button>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-6">
        <div class="max-w-2xl mx-auto space-y-6">

            <!-- Mode Selector -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-2 flex gap-2 shadow-sm border border-slate-200 dark:border-slate-700">
                <button
                    @click="tipo = 'cliente'; resetSelection()"
                    :class="tipo === 'cliente' ? 'bg-blue-600 text-white shadow-lg' : 'bg-transparent text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700'"
                    class="flex-1 py-3 rounded-lg font-bold transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-user"></i>
                    Cobro a Cliente
                </button>
                <button
                    @click="tipo = 'cuenta'; resetSelection()"
                    :class="tipo === 'cuenta' ? 'bg-green-600 text-white shadow-lg' : 'bg-transparent text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700'"
                    class="flex-1 py-3 rounded-lg font-bold transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-building-columns"></i>
                    Cobro a Cuenta
                </button>
            </div>

            <!-- Search Section -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">

                <!-- Client Search -->
                <div x-show="tipo === 'cliente'" class="space-y-4">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Buscar Cliente</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input
                            type="text"
                            x-model="clienteSearch"
                            @input.debounce.300ms="searchClientes()"
                            @focus="showClienteDropdown = true"
                            placeholder="Nombre o RUC del cliente..."
                            class="input-field pl-11">
                        <!-- Dropdown -->
                        <div
                            x-show="showClienteDropdown && clienteResults.length > 0"
                            @click.outside="showClienteDropdown = false"
                            class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-xl z-50 max-h-64 overflow-y-auto">
                            <template x-for="cliente in clienteResults" :key="cliente.id">
                                <button
                                    @click="selectCliente(cliente)"
                                    class="w-full px-4 py-3 text-left hover:bg-slate-100 dark:hover:bg-slate-700 border-b border-slate-100 dark:border-slate-700 last:border-0 transition-colors">
                                    <div class="font-bold text-sm text-slate-900 dark:text-white" x-text="cliente.nombre"></div>
                                    <div class="text-xs text-slate-500 flex justify-between">
                                        <span x-text="'RUC: ' + cliente.ruc"></span>
                                        <span :class="parseFloat(cliente.saldo) >= 0 ? 'text-green-600' : 'text-red-600'" x-text="'Saldo: ' + formatMoney(cliente.saldo)"></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Selected Client Card -->
                    <div x-show="selectedCliente" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex items-center justify-between">
                        <div>
                            <div class="font-bold text-blue-900 dark:text-blue-100" x-text="selectedCliente?.nombre"></div>
                            <div class="text-xs text-blue-600 dark:text-blue-400" x-text="'RUC: ' + selectedCliente?.ruc"></div>
                        </div>
                        <button @click="selectedCliente = null; clienteSearch = ''" class="text-blue-500 hover:text-blue-700">
                            <i class="fa-solid fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- Account Search -->
                <div x-show="tipo === 'cuenta'" class="space-y-4">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Buscar Cuenta</label>
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input
                            type="text"
                            x-model="cuentaSearch"
                            @input.debounce.300ms="searchCuentas()"
                            @focus="showCuentaDropdown = true"
                            placeholder="Nombre o código de cuenta..."
                            class="input-field pl-11">
                        <!-- Dropdown -->
                        <div
                            x-show="showCuentaDropdown && cuentaResults.length > 0"
                            @click.outside="showCuentaDropdown = false"
                            class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-xl z-50 max-h-64 overflow-y-auto">
                            <template x-for="cuenta in cuentaResults" :key="cuenta.id">
                                <button
                                    @click="selectCuenta(cuenta)"
                                    class="w-full px-4 py-3 text-left hover:bg-slate-100 dark:hover:bg-slate-700 border-b border-slate-100 dark:border-slate-700 last:border-0 transition-colors">
                                    <div class="font-bold text-sm text-slate-900 dark:text-white" x-text="cuenta.nombre"></div>
                                    <div class="text-xs text-slate-500" x-text="cuenta.codigo"></div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Selected Account Card -->
                    <div x-show="selectedCuenta" class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 flex items-center justify-between">
                        <div>
                            <div class="font-bold text-green-900 dark:text-green-100" x-text="selectedCuenta?.nombre"></div>
                            <div class="text-xs text-green-600 dark:text-green-400" x-text="selectedCuenta?.codigo"></div>
                        </div>
                        <button @click="selectedCuenta = null; cuentaSearch = ''" class="text-green-500 hover:text-green-700">
                            <i class="fa-solid fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700 space-y-5">
                <h3 class="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-money-bill-wave text-green-500"></i>
                    Detalles del Cobro
                </h3>

                <!-- Amount -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Monto</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">Gs.</span>
                        <input
                            type="text"
                            inputmode="numeric"
                            :value="formatNum(monto)"
                            @input="monto = parseNum($event.target.value)"
                            class="input-field pl-12 text-2xl font-black text-right"
                            placeholder="0">
                    </div>
                </div>

                <!-- Concept -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Concepto</label>
                    <textarea
                        x-model="concepto"
                        rows="2"
                        class="input-field resize-none"
                        placeholder="Descripción del cobro..."></textarea>
                </div>

                <!-- Payment Method -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">Medio de Pago</label>
                    <div class="grid grid-cols-4 gap-2">
                        <template x-for="method in paymentMethods" :key="method.id">
                            <button
                                @click="medioPago = method.id"
                                :class="medioPago === method.id ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-600 hover:border-blue-400'"
                                class="py-3 rounded-lg font-bold text-sm border-2 transition-all flex flex-col items-center gap-1">
                                <i :class="method.icon" class="text-lg"></i>
                                <span x-text="method.name" class="text-xs"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Reference (conditional) -->
                <div x-show="medioPago !== 'efectivo'">
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">
                        <span x-text="medioPago === 'tarjeta' ? 'Nro. Voucher' : (medioPago === 'transferencia' ? 'Referencia Transferencia' : 'Código Transacción')"></span>
                    </label>
                    <input
                        type="text"
                        x-model="referenciaPago"
                        class="input-field"
                        placeholder="Ingrese referencia...">
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-4">
                <button
                    @click="submitCobro()"
                    :disabled="loading || !canSubmit"
                    :class="canSubmit ? 'btn-primary' : 'bg-slate-300 dark:bg-slate-700 text-slate-500 cursor-not-allowed'"
                    class="flex-1 py-4 rounded-xl font-bold text-lg transition-all">
                    <template x-if="!loading">
                        <span class="flex items-center justify-center gap-2">
                            <i class="fa-solid fa-check"></i>
                            Registrar Cobro
                        </span>
                    </template>
                    <template x-if="loading">
                        <span class="flex items-center justify-center gap-2">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            Procesando...
                        </span>
                    </template>
                </button>
                <button @click="resetForm()" class="btn-secondary">
                    <i class="fa-solid fa-eraser"></i>
                    Limpiar
                </button>
            </div>

        </div>
    </main>

    <!-- Confirmation Modal -->
    <div
        x-show="showConfirmModal"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[10000] flex items-center justify-center bg-black/50 backdrop-blur-sm"
        style="display: none;">
        <div
            @click.outside="showConfirmModal = false"
            class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl p-6 w-full max-w-md mx-4 border border-slate-200 dark:border-slate-700">
            <div class="text-center mb-6">
                <div class="mx-auto w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mb-4">
                    <i class="fa-solid fa-check text-green-600 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Confirmar Cobro</h3>
            </div>

            <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 space-y-2 mb-6">
                <div class="flex justify-between text-sm">
                    <span class="text-slate-500">Tipo:</span>
                    <span class="font-bold text-slate-900 dark:text-white" x-text="tipo === 'cliente' ? 'Cobro a Cliente' : 'Cobro a Cuenta'"></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-slate-500" x-text="tipo === 'cliente' ? 'Cliente:' : 'Cuenta:'"></span>
                    <span class="font-bold text-slate-900 dark:text-white" x-text="tipo === 'cliente' ? selectedCliente?.nombre : selectedCuenta?.nombre"></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-slate-500">Monto:</span>
                    <span class="font-black text-green-600 text-lg" x-text="formatMoney(monto)"></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-slate-500">Medio:</span>
                    <span class="font-bold text-slate-900 dark:text-white uppercase" x-text="medioPago"></span>
                </div>
            </div>

            <div class="flex gap-3">
                <button @click="showConfirmModal = false" class="flex-1 btn-secondary py-3">Cancelar</button>
                <button @click="confirmCobro()" class="flex-1 btn-primary py-3">
                    <i class="fa-solid fa-check"></i>
                    Confirmar
                </button>
            </div>
        </div>
    </div>

    <script>
        const ID_EMPRESA = <?php echo $id_empresa; ?>;
        const API_URL = 'cobros_varios_api.php';

        function cobrosApp() {
            return {
                // State
                tipo: 'cliente',

                // Client
                clienteSearch: '',
                clienteResults: [],
                selectedCliente: null,
                showClienteDropdown: false,

                // Account
                cuentaSearch: '',
                cuentaResults: [],
                selectedCuenta: null,
                showCuentaDropdown: false,

                // Payment
                monto: 0,
                concepto: '',
                medioPago: 'efectivo',
                referenciaPago: '',

                // UI
                loading: false,
                toasts: [],
                showConfirmModal: false,

                paymentMethods: [{
                        id: 'efectivo',
                        name: 'Efectivo',
                        icon: 'fa-solid fa-money-bill'
                    },
                    {
                        id: 'tarjeta',
                        name: 'Tarjeta',
                        icon: 'fa-solid fa-credit-card'
                    },
                    {
                        id: 'transferencia',
                        name: 'Transfer',
                        icon: 'fa-solid fa-building-columns'
                    },
                    {
                        id: 'qr',
                        name: 'QR/PIX',
                        icon: 'fa-solid fa-qrcode'
                    }
                ],

                // Computed
                get canSubmit() {
                    const hasSelection = this.tipo === 'cliente' ? this.selectedCliente : this.selectedCuenta;
                    return hasSelection && this.monto > 0;
                },

                // Init
                init() {
                    console.log('Cobros Varios App initialized');
                },

                // Search Clients
                async searchClientes() {
                    if (this.clienteSearch.length < 2) {
                        this.clienteResults = [];
                        return;
                    }
                    try {
                        const res = await fetch(`${API_URL}?action=search_clients&q=${encodeURIComponent(this.clienteSearch)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success) {
                            this.clienteResults = data.clients || [];
                            this.showClienteDropdown = true;
                        }
                    } catch (e) {
                        console.error(e);
                    }
                },

                selectCliente(cliente) {
                    this.selectedCliente = cliente;
                    this.clienteSearch = cliente.nombre;
                    this.showClienteDropdown = false;
                    this.clienteResults = [];
                },

                // Search Accounts
                async searchCuentas() {
                    if (this.cuentaSearch.length < 2) {
                        this.cuentaResults = [];
                        return;
                    }
                    try {
                        const res = await fetch(`${API_URL}?action=search_cuentas&q=${encodeURIComponent(this.cuentaSearch)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success) {
                            this.cuentaResults = data.cuentas || [];
                            this.showCuentaDropdown = true;
                        }
                    } catch (e) {
                        console.error(e);
                    }
                },

                selectCuenta(cuenta) {
                    this.selectedCuenta = cuenta;
                    this.cuentaSearch = cuenta.nombre;
                    this.showCuentaDropdown = false;
                    this.cuentaResults = [];
                },

                // Reset
                resetSelection() {
                    this.selectedCliente = null;
                    this.selectedCuenta = null;
                    this.clienteSearch = '';
                    this.cuentaSearch = '';
                    this.clienteResults = [];
                    this.cuentaResults = [];
                },

                resetForm() {
                    this.resetSelection();
                    this.monto = 0;
                    this.concepto = '';
                    this.medioPago = 'efectivo';
                    this.referenciaPago = '';
                },

                // Submit
                submitCobro() {
                    if (!this.canSubmit) return;
                    this.showConfirmModal = true;
                },

                async confirmCobro() {
                    this.showConfirmModal = false;
                    this.loading = true;

                    const action = this.tipo === 'cliente' ? 'insert_cobro_cliente' : 'insert_cobro_cuenta';
                    const payload = {
                        monto: this.monto,
                        concepto: this.concepto,
                        medio_pago: this.medioPago,
                        referencia_pago: this.referenciaPago
                    };

                    if (this.tipo === 'cliente') {
                        payload.id_cliente = this.selectedCliente.id;
                    } else {
                        payload.id_cuenta = this.selectedCuenta.id;
                    }

                    try {
                        const res = await fetch(`${API_URL}?action=${action}&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });
                        const data = await res.json();

                        if (data.success) {
                            this.toast(data.message || 'Cobro registrado correctamente', 'success');
                            this.resetForm();
                        } else {
                            this.toast(data.message || 'Error al registrar cobro', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Error de conexión', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                // Toasts
                toast(message, type = 'info') {
                    const toast = {
                        message,
                        type,
                        visible: true
                    };
                    this.toasts.push(toast);
                    setTimeout(() => {
                        toast.visible = false;
                        setTimeout(() => {
                            this.toasts = this.toasts.filter(t => t !== toast);
                        }, 300);
                    }, 3000);
                },

                removeToast(index) {
                    this.toasts[index].visible = false;
                    setTimeout(() => {
                        this.toasts.splice(index, 1);
                    }, 300);
                },

                // Formatters
                formatNum(num) {
                    return Number(num || 0).toLocaleString('es-PY');
                },
                parseNum(str) {
                    return parseInt(String(str).replace(/\D/g, '')) || 0;
                },
                formatMoney(num) {
                    return Number(num || 0).toLocaleString('es-PY') + ' Gs';
                }
            }
        }
    </script>
</body>

</html>