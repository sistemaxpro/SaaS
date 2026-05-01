<?php

/**
 * POS Desktop - Sistema de Punto de Venta
 * Tailwind CSS + Alpine.js + PHP
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_login = (int)($_SESSION['id_login'] ?? 0);
$id_empresa_session = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;

// Valores por defecto
$usuario = $_SESSION['usuario'] ?? 'Usuario';
$id_caja = (int)($_SESSION['id_caja_def'] ?? 0);
$id_empresa = $id_empresa_session;
$nombreEmpresa = 'SistemaX';
$isFacturaElectronica = 1;

try {
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? 'localhost';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    $pdo_init = new PDO("mysql:host={$dbHost};dbname={$masterDb}", $dbUser, $dbPass);

    // 1. Obtener datos extendidos del usuario desde sec_users (Fuente de Verdad)
    $stmt_user = $pdo_init->prepare("SELECT login, caja_def, id_empresa, cobro_df, ancho_papel, forma_pago_def FROM sec_users WHERE id_login = :id");
    $stmt_user->execute([':id' => $id_login]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $cobro_df = 1; // Default
    $ancho_papel = 400; // Default
    $forma_pago_def = 1; // Default

    if ($user_data) {
        $usuario = $user_data['login'];
        $id_caja = (int)$user_data['caja_def'];
        $id_empresa = (int)$user_data['id_empresa']; // Priorizar empresa del usuario
        $cobro_df = (int)$user_data['cobro_df'];
        $ancho_papel = (int)$user_data['ancho_papel'];
        $forma_pago_def = (int)$user_data['forma_pago_def'];
    }

    // 2. Obtener datos de la empresa
    $stmt_init = $pdo_init->prepare("SELECT empresa, fe FROM empresa WHERE id_empresa = :id");
    $stmt_init->execute([':id' => $id_empresa]);
    $emp_data = $stmt_init->fetch(PDO::FETCH_ASSOC);
    if ($emp_data) {
        $nombreEmpresa = $emp_data['empresa'];
        $isFacturaElectronica = (int)$emp_data['fe'];
    }

    // 3. Obtener base de datos de la empresa para tipos de precio
    $stmt_db = $pdo_init->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmt_db->execute([':id' => $id_empresa]);
    $dbEmpresa = $stmt_db->fetchColumn();

    // 4. Cargar tipos de precio
    $tiposPrecio = [];
    if ($dbEmpresa) {
        $stmt_precios = $pdo_init->query("SELECT id, tipo FROM $dbEmpresa.tipo_precio ORDER BY id");
        $tiposPrecio = $stmt_precios->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Mantener defaults en caso de error
}
?>
<!DOCTYPE html>
<html lang="es" x-data="posApp()" x-init="init()" :class="isDarkMode ? 'dark' : ''">
<script>
    // Detectar tema (Forzar Light por defecto según solicitud)
    (function() {
        const theme = localStorage.getItem('theme');
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        }
    })();
</script>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - SistemaX</title>

    <!-- Tailwind CSS -->
    <link rel="stylesheet" href="../assets/tailwind.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#3b82f6',
                        success: '#22c55e',
                        danger: '#ef4444',
                        warning: '#f59e0b'
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        [x-cloak] {
            display: none !important;
        }

        .producto-card {
            transition: all 0.15s ease;
        }

        .producto-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .producto-card:active {
            transform: scale(0.98);
        }

        /* Custom scrollbar - Dark */
        .dark .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .dark .custom-scroll::-webkit-scrollbar-track {
            background: #1e293b;
        }

        .dark .custom-scroll::-webkit-scrollbar-thumb {
            background: #475569;
            border-radius: 3px;
        }

        /* Custom scrollbar - Light */
        :not(.dark) .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }

        :not(.dark) .custom-scroll::-webkit-scrollbar-track {
            background: #e2e8f0;
        }

        :not(.dark) .custom-scroll::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 3px;
        }

        /* Animación entrada */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .cart-item {
            animation: slideIn 0.2s ease;
        }

        /* Full viewport cover */
        html {
            height: 100%;
        }

        html,
        body {
            /* Styles replaced by specific body styling below */
            width: 100%;
        }

        /* Modern Transparent UI */
        body {
            display: flex;
            flex-direction: column;
            overflow: hidden !important;

            /* Card Effect */
            margin: 12px !important;
            height: calc(100% - 24px) !important;
            width: calc(100% - 24px) !important;
            border-radius: 20px;

            /* Force Clipping */
            overflow: hidden !important;
            transform: translateZ(0);
            /* Fixes border-radius clipping being ignored in some renderers */
            position: relative;
            /* Ensures z-index container */

            backdrop-filter: blur(8px);

            /* Default Borders - Increased Contrast */
            border: 1px solid rgba(0, 0, 0, 0.4);
        }

        .dark body {
            background-color: rgba(15, 23, 42, 0.60) !important;
            /* Menos transparencia */
            border-color: rgba(255, 255, 255, 0.4);
            /* Más contraste */
        }

        /* Tema Claro - Transparent */
        html:not(.dark) body {
            background-color: transparent !important;
            /* 100% transparente */
            color: #0f172a;
        }

        .pos-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background-color: transparent !important;
            /* Allow body bg to show */
        }

        /* Contenedores principales y tarjetas en blanco puro */
        html:not(.dark) .producto-card,
        html:not(.dark) .bg-white,
        html:not(.dark) header {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
        }

        /* Mapeo de negros/grises oscuros a grises claros para mantener jerarquía */
        html:not(.dark) .bg-slate-800,
        html:not(.dark) .bg-gray-900,
        html:not(.dark) .bg-slate-900 {
            background-color: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #e2e8f0;
            /* Agrega borde sutil para definir forma */
        }

        /* Inputs y elementos secundarios en gris muy claro */
        html:not(.dark) input,
        html:not(.dark) select,
        html:not(.dark) .bg-slate-100 {
            background-color: #f8fafc !important;
            /* Slate 50 */
            border-color: #cbd5e1 !important;
            /* Slate 300 */
            color: #334155 !important;
            /* Slate 700 */
        }

        /* Hover states en light mode */
        html:not(.dark) .hover\:bg-slate-100:hover {
            background-color: #e2e8f0 !important;
            /* Slate 200 */
        }

        /* Azul Universal - Enforcement */
        /* Asegura que los elementos verdes se vuelvan azules en ambos modos si quedan clases residuales */
        .text-green-400,
        .text-green-500,
        .text-green-600 {
            color: #3b82f6 !important;
        }

        /* Blue 500 */
        .bg-green-500,
        .bg-green-600 {
            background-color: #2563eb !important;
        }

        /* Blue 600 */
        .bg-green-600:hover {
            background-color: #1d4ed8 !important;
        }

        /* Dark Mode específico para textos azules para legibilidad */
        .dark .text-blue-600 {
            color: #60a5fa !important;
        }

        /* Blue 400 en dark mode */


        /* Precios en la tabla */
        html:not(.dark) .text-slate-600 dark:text-slate-400 {
            color: #1e293b !important;
        }

        /* Resaltado de búsqueda */
        mark {
            background-color: rgba(234, 179, 8, 0.4);
            border-radius: 2px;
            padding: 0 2px;
            color: inherit;
        }

        .dark mark {
            color: #fff;
        }

        html:not(.dark) mark {
            color: #000;
            font-weight: bold;
        }
    </style>
</head>

<body class="bg-gray-100 dark:bg-slate-900 text-gray-900 dark:text-white transition-colors">
    <div class="pos-container">
        <!-- Header -->
        <header class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-4 py-2 flex items-center justify-between gap-4 rounded-t-[19px]">
            <!-- Izquierda: Título y Empresa -->
            <div class="flex items-center gap-3 flex-shrink-0">
                <h1 class="text-xl font-black tracking-tighter text-slate-900 dark:text-white flex items-center gap-2">
                    <span class="bg-blue-600 text-white px-2 py-0.5 rounded shadow-lg shadow-blue-500/20">SX</span>
                    <span class="hidden sm:inline text-blue-600">POS</span>
                </h1>
                <div class="w-px h-6 bg-slate-100 dark:bg-slate-700 hidden lg:block"></div>
                <span class="hidden lg:block text-slate-400 font-bold text-xs uppercase tracking-widest truncate max-w-[200px]"><?php echo htmlspecialchars($nombreEmpresa); ?></span>
            </div>

            <!-- Centro: Tickets -->
            <div class="flex items-center gap-1 flex-1 justify-center overflow-x-auto max-w-2xl">
                <template x-for="(ticket, index) in tickets" :key="ticket.id">
                    <div
                        @click="switchTicket(index)"
                        :class="activeTicket === index 
                        ? 'bg-blue-600 text-white' 
                        : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-600'"
                        :title="(ticket.selectedCliente?.nombre ? '👤 ' + ticket.selectedCliente.nombre + (ticket.selectedCliente?.ruc ? ' (' + ticket.selectedCliente.ruc + ')' : '') : 'Sin cliente') + '\n🛒 ' + ticket.cart.length + ' producto(s)'"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg cursor-pointer transition-all text-sm min-w-fit max-w-52 group relative">
                        <span class="font-medium whitespace-nowrap">🎫 #<span x-text="ticket.id"></span></span>
                        <!-- Nombre del cliente -->
                        <span
                            x-show="ticket.selectedCliente?.nombre"
                            class="text-xs truncate max-w-24 opacity-80 border-l border-white/20 pl-1.5"
                            x-text="ticket.selectedCliente?.nombre?.split(',')[0] || ticket.selectedCliente?.nombre?.split(' ')[0] || ''"></span>
                        <button
                            x-show="index > 0"
                            @click.stop="removeTicket(index)"
                            class="text-xs opacity-50 hover:opacity-100 hover:text-red-400 flex-shrink-0 ml-1"
                            title="Cerrar ticket">✕</button>
                    </div>
                </template>
                <button
                    @click="addTicket()"
                    class="w-7 h-7 bg-slate-100 dark:bg-slate-700 hover:bg-green-600 text-slate-400 hover:text-slate-900 dark:text-white rounded-lg flex items-center justify-center transition-all flex-shrink-0"
                    title="Nuevo ticket (Ctrl+T)">+</button>
            </div>

            <!-- Derecha: Hora, Usuario y Salir -->
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-slate-400 text-sm hidden sm:inline" x-text="currentTime"></span>

                <!-- Botón Editar Venta -->
                <!-- Botón Editar Venta - Naranja/Blanco -->
                <button
                    x-show="false"
                    @click="openEditSearchModal()"
                    :disabled="cart.length > 0"
                    :class="cart.length > 0 ? 'opacity-40 cursor-not-allowed bg-amber-600 text-white' : 'bg-amber-600 hover:bg-amber-700 text-white'"
                    class="font-bold p-2 rounded-lg transition-all flex items-center justify-center text-sm gap-1.5 shadow-sm"
                    title="Mis Ventas - Editar notas (30 días)">
                    <span class="text-xs">Mis Ventas</span>
                </button>

                <!-- Botón Mi Caja -->
                <!-- Botón Mi Caja - Verde/Blanco -->
                <button
                    x-show="false"
                    @click="window.location.href = 'caja.php'"
                    class="bg-green-600 text-white hover:bg-green-700 font-bold p-2 rounded-lg transition-all flex items-center justify-center text-sm gap-1.5 shadow-sm"
                    title="Control de Caja">
                    <span class="text-xs">Mi Caja</span>
                </button>

                <!-- Caja y Usuario -->
                <div class="flex items-center gap-3 bg-white dark:bg-slate-800/50 px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700/50">
                    <div class="flex flex-col items-end leading-none">
                        <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Caja</span>
                        <span class="text-sm font-black text-blue-400">#<?php echo str_pad($id_caja, 2, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="w-px h-6 bg-slate-100 dark:bg-slate-700"></div>
                    <div class="flex items-center gap-2">
                        <div class="hidden md:flex flex-col items-end leading-none">
                            <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Cajero</span>
                            <span class="text-xs font-medium text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($usuario); ?></span>
                        </div>
                        <button
                            @click="toggleSound()"
                            class="w-8 h-8 rounded-lg flex items-center justify-center border transition-all cursor-pointer"
                            :class="soundMuted ? 'bg-red-500/20 border-red-500/30' : 'bg-green-500/20 border-green-500/30'"
                            :title="soundMuted ? 'Sonido desactivado - Click para activar' : 'Sonido activado - Click para desactivar'">
                            <span x-show="!soundMuted" class="text-sm">🔊</span>
                            <span x-show="soundMuted" class="text-sm">🔇</span>
                        </button>
                    </div>
                </div>

                <!-- Botón Salir -->
                <button
                    @click="salir()"
                    class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors text-sm flex items-center gap-2 border border-slate-200 dark:border-slate-700 font-bold"
                    title="Salir y cerrar el POS">
                    <span class="font-bold">SALIR</span>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 flex overflow-hidden">

            <!-- Panel Izquierdo: Productos -->
            <section class="w-[70%] border-r border-slate-200 dark:border-slate-700 flex flex-col overflow-visible">

                <!-- Búsqueda con Autocompletado -->
                <div class="p-4 border-b border-slate-200 dark:border-slate-700 relative overflow-visible">
                    <div class="relative" @click.away="showSuggestions = false">
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input
                            type="text"
                            x-model="searchQuery"
                            @input.debounce.300ms="searchProducts()"
                            @keydown.enter.prevent="handleSearchEnter()"
                            @keydown.arrow-down.prevent="navigateGrid(1)"
                            @keydown.arrow-up.prevent="navigateGrid(-1)"
                            @keydown.arrow-right.prevent="if(viewMode === 'grid') navigateGrid('right')"
                            @keydown.arrow-left.prevent="if(viewMode === 'grid') navigateGrid('left')"
                            @keydown.escape="searchQuery = ''; productos = []"
                            @focus="selectedIndex = 0"
                            x-ref="searchInput"
                            placeholder="Buscar por nombre, código o filtrado por precio (<500...)"
                            class="w-full bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg pl-10 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-slate-500">
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                            <!-- Selector Tipo de Precio -->
                            <select
                                x-model="selectedPriceType"
                                @change="onPriceTypeChange()"
                                class="text-[10px] font-bold px-2 py-1 rounded border border-blue-500 bg-blue-50 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
                                title="Tipo de precio a utilizar">
                                <?php foreach ($tiposPrecio as $tp): ?>
                                    <option value="<?php echo (int)$tp['id']; ?>"><?php echo htmlspecialchars($tp['tipo']); ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button
                                x-show="searchQuery"
                                @click="searchQuery = ''; productos = []; showSuggestions = false"
                                class="text-slate-400 hover:text-slate-900 dark:text-white">✕</button>
                        </div>
                    </div>

                    <!-- Aviso Balanza -->
                    <div
                        x-show="balanzaData"
                        x-transition
                        class="mt-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/40 dark:bg-amber-900/30 dark:text-amber-100 px-4 py-3 flex items-start gap-3 shadow-sm">
                        <div class="text-2xl">⚖️</div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="font-bold text-sm">Peso detectado en balanza</p>
                                <span class="text-[10px] font-mono bg-white/60 dark:bg-slate-800/60 px-2 py-0.5 rounded-full" x-text="balanzaData?.balanza || 'Balanza'"></span>
                            </div>
                            <div class="text-[12px] grid grid-cols-2 gap-2 text-slate-700 dark:text-slate-200">
                                <div>
                                    <span class="font-semibold">Producto:</span>
                                    <span x-text="(balanzaData?.descripcion || 'N/D') + (balanzaData?.codigo ? ' (' + balanzaData.codigo + ')' : '')"></span>
                                </div>
                                <div><span class="font-semibold">ID:</span> <span x-text="balanzaData?.idproducto || ''"></span></div>
                                <div><span class="font-semibold">Modo:</span> <span x-text="balanzaData?.modo || ''"></span></div>
                                <div><span class="font-semibold">Valor leído:</span> <span x-text="balanzaData?.valor || ''"></span></div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <button @click="confirmarBalanza()" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-bold hover:bg-blue-700 transition-colors">Usar en venta</button>
                                <button @click="cancelarBalanza()" class="px-3 py-1.5 rounded-lg bg-white text-slate-700 border border-slate-300 text-xs font-bold hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600 dark:hover:bg-slate-700">Cancelar</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista de Productos -->
                <div class="flex-1 overflow-y-auto custom-scroll p-4">

                    <!-- Resultados de Búsqueda Activa -->
                    <div x-show="searchQuery.trim() !== '' && productos.length > 0" class="pb-10">
                        <div class="flex items-center justify-between mb-3 px-1">
                            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                <span class="text-blue-500">🔍</span> Resultados de búsqueda
                            </h2>
                            <div class="flex items-center gap-2">
                                <span class="text-[9px] text-slate-600 dark:text-slate-400 font-bold px-2 py-0.5 bg-white dark:bg-slate-800/50 rounded-full" x-text="productos.length + ' encontrados'"></span>
                                <!-- Toggle View -->
                                <div class="flex bg-white dark:bg-slate-800/50 rounded-lg p-0.5 border border-slate-200 dark:border-slate-700/50">
                                    <button
                                        @click="viewMode = 'grid'"
                                        @contextmenu.prevent="setViewModeDefault('grid')"
                                        :class="viewMode === 'grid' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-slate-900 dark:text-white'"
                                        class="p-1 rounded transition-all"
                                        title="Clic izquierdo: Grid / Clic derecho: Fijar defecto">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="viewMode = 'list'"
                                        @contextmenu.prevent="setViewModeDefault('list')"
                                        :class="viewMode === 'list' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-slate-900 dark:text-white'"
                                        class="p-1 rounded transition-all"
                                        title="Clic izquierdo: Lista / Clic derecho: Fijar defecto">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Grid View -->
                        <div x-show="viewMode === 'grid'" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                            <template x-for="(producto, index) in productos" :key="producto.id">
                                <div
                                    @click="POSAudio.play('success'); addToCart(producto)"
                                    @contextmenu.prevent="openProductDetail(producto)"
                                    @mouseenter="selectedIndex = index"
                                    :class="selectedIndex === index ? 'ring-2 ring-blue-500 bg-slate-100 dark:bg-slate-700/80 scale-[1.02] shadow-xl z-10' : 'bg-white dark:bg-slate-800/60 border-slate-200 dark:border-slate-700/30 hover:bg-slate-100 dark:bg-slate-700/80'"
                                    class="producto-card border rounded-xl p-3 cursor-pointer transition-all group relative flex flex-col justify-between h-28">
                                    <div>
                                        <div class="flex justify-between items-start mb-1">
                                            <span class="text-[8px] font-bold text-slate-500 font-mono" x-text="producto.codigo"></span>
                                            <div class="flex gap-1">
                                                <span x-show="producto.referencia" class="text-[8px] text-blue-400 font-black bg-blue-400/10 px-1 rounded" x-text="producto.referencia"></span>
                                                <span x-show="producto.has_other_stock == 1" class="text-[8px] text-orange-600 bg-orange-100 dark:bg-orange-900/30 px-1 rounded font-bold" title="Disponible en otras sucursales">📍Otras Suc.</span>
                                            </div>
                                        </div>
                                        <h3 class="text-[11px] font-bold text-slate-800 dark:text-slate-200 leading-tight line-clamp-2 group-hover:text-slate-900 dark:text-white" x-html="highlightMatch(producto.descripcion, searchQuery)"></h3>
                                    </div>
                                    <div class="mt-1 pt-1 border-t border-slate-200 dark:border-slate-700/20 flex flex-col">
                                        <span class="text-[11px] font-black text-blue-600 dark:text-blue-400 text-center" x-text="formatMoney(producto.precio) + ' Gs'"></span>
                                        <div class="flex justify-between items-center mt-0.5">
                                            <template x-if="parseInt(producto.controla_stock) === -1">
                                                <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm bg-slate-400">No controla stock</span>
                                            </template>
                                            <template x-if="parseInt(producto.controla_stock) !== -1">
                                                <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm" :class="parseFloat(producto.stock) > 0 ? 'bg-green-600' : 'bg-red-600'" x-text="'Stock: ' + producto.stock"></span>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- List View -->
                        <div x-show="viewMode === 'list'" class="space-y-1">
                            <template x-for="(producto, index) in productos" :key="producto.id">
                                <div
                                    @click="POSAudio.play('success'); addToCart(producto)"
                                    @contextmenu.prevent="openProductDetail(producto)"
                                    @mouseenter="selectedIndex = index"
                                    :class="selectedIndex === index ? 'bg-blue-600/20 border-blue-500/50' : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700/30 hover:bg-slate-100 dark:bg-slate-700/60'"
                                    class="flex items-center justify-between p-2 rounded-lg border cursor-pointer transition-colors group">
                                    <div class="flex-1 min-w-0 pr-4">
                                        <div class="flex items-center gap-2 mb-0.5">
                                            <span class="text-[10px] bg-slate-50 dark:bg-slate-900 text-slate-400 px-1.5 py-0.5 rounded font-mono" x-text="producto.codigo"></span>
                                            <span x-show="producto.referencia" class="text-[9px] text-blue-400 font-bold" x-text="producto.referencia"></span>
                                            <span x-show="producto.has_other_stock == 1" class="text-[8px] text-orange-600 bg-orange-100 dark:bg-orange-900/30 px-1.5 rounded-full font-bold ml-1">📍 Otras Suc.</span>
                                        </div>
                                        <div class="font-bold text-sm text-slate-800 dark:text-slate-200 truncate group-hover:text-slate-900 dark:text-white" x-html="highlightMatch(producto.descripcion, searchQuery)"></div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="font-black text-blue-600 dark:text-blue-400 text-sm" x-text="formatMoney(producto.precio)"></div>
                                        <template x-if="parseInt(producto.controla_stock) === -1">
                                            <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm inline-block min-w-[50px] text-center bg-slate-400">No controla stock</span>
                                        </template>
                                        <template x-if="parseInt(producto.controla_stock) !== -1">
                                            <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm inline-block min-w-[50px] text-center" :class="parseFloat(producto.stock) > 0 ? 'bg-green-600' : 'bg-red-600'" x-text="'Stock: ' + producto.stock"></span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>


                    <!-- Productos Más Vendidos (Cuadricula 3x3 cuando está vacío) -->
                    <div x-show="searchQuery.trim() === ''" class="flex flex-col h-full">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                Artículos Frecuentes
                            </h2>
                            <div class="flex items-center gap-2">
                                <span class="text-[9px] text-slate-600 dark:text-slate-400 font-bold px-2 py-0.5 bg-white dark:bg-slate-800/50 rounded-full">Top 9</span>
                                <!-- Toggle View -->
                                <div class="flex bg-white dark:bg-slate-800/50 rounded-lg p-0.5 border border-slate-200 dark:border-slate-700/50">
                                    <button
                                        @click="viewMode = 'grid'"
                                        @contextmenu.prevent="setViewModeDefault('grid')"
                                        :class="viewMode === 'grid' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-slate-900 dark:text-white'"
                                        class="p-1 rounded transition-all"
                                        title="Clic izquierdo: Grid / Clic derecho: Fijar defecto">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="viewMode = 'list'"
                                        @contextmenu.prevent="setViewModeDefault('list')"
                                        :class="viewMode === 'list' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-slate-900 dark:text-white'"
                                        class="p-1 rounded transition-all"
                                        title="Clic izquierdo: Lista / Clic derecho: Fijar defecto">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Loading skeleton -->
                        <div x-show="loadingPopular" class="grid grid-cols-3 gap-3">
                            <template x-for="i in 9">
                                <div class="bg-white dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700/20 h-24 rounded-xl animate-pulse"></div>
                            </template>
                        </div>

                        <!-- Popular Grid View -->
                        <div x-show="!loadingPopular && viewMode === 'grid'" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                            <template x-for="producto in popularProducts" :key="producto.id">
                                <div
                                    @click="POSAudio.play('success'); addToCart(producto)"
                                    @contextmenu.prevent="openProductDetail(producto)"
                                    class="producto-card bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/30 rounded-xl p-3 cursor-pointer hover:bg-blue-600/10 hover:border-blue-500/30 transition-all group relative flex flex-col justify-between h-28">
                                    <div>
                                        <div class="flex justify-between items-start mb-1">
                                            <span class="text-[8px] font-bold text-slate-500 font-mono" x-text="producto.codigo"></span>
                                            <div class="flex gap-1">
                                                <span x-show="producto.referencia" class="text-[8px] text-blue-400 font-black bg-blue-400/10 px-1 rounded" x-text="producto.referencia"></span>
                                                <span x-show="producto.has_other_stock == 1" class="text-[8px] text-orange-600 bg-orange-100 dark:bg-orange-900/30 px-1 rounded font-bold" title="Disponible en otras sucursales">📍Otras Suc.</span>
                                            </div>
                                        </div>
                                        <h3 class="text-[11px] font-bold text-slate-800 dark:text-slate-200 leading-tight line-clamp-2 group-hover:text-slate-900 dark:text-white" x-text="producto.descripcion"></h3>
                                    </div>
                                    <div class="mt-1 pt-1 border-t border-slate-200 dark:border-slate-700/20 flex flex-col">
                                        <span class="text-[11px] font-black text-blue-600 dark:text-blue-400 text-center" x-text="formatMoney(producto.precio) + ' Gs'"></span>
                                        <div class="flex justify-between items-center mt-0.5">
                                            <template x-if="parseInt(producto.controla_stock) === -1">
                                                <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm bg-slate-400">No controla stock</span>
                                            </template>
                                            <template x-if="parseInt(producto.controla_stock) !== -1">
                                                <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm" :class="parseFloat(producto.stock) > 0 ? 'bg-green-600' : 'bg-red-600'" x-text="'Stock: ' + producto.stock"></span>
                                            </template>
                                        </div>
                                    </div>
                                    <!-- Badge Popular -->
                                    <div class="absolute -top-1 -right-1 bg-amber-500 text-[8px] font-black text-slate-950 px-1.5 py-0.5 rounded-lg shadow-lg opacity-0 group-hover:opacity-100 transition-opacity translate-y-1 group-hover:translate-y-0">POP</div>
                                </div>
                            </template>
                        </div>

                        <!-- Popular List View -->
                        <div x-show="!loadingPopular && viewMode === 'list'" class="space-y-1">
                            <template x-for="producto in popularProducts" :key="producto.id">
                                <div
                                    @click="POSAudio.play('success'); addToCart(producto)"
                                    @contextmenu.prevent="openProductDetail(producto)"
                                    class="flex items-center justify-between p-2 rounded-lg bg-white dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700/30 cursor-pointer hover:bg-slate-100 dark:bg-slate-700/60 transition-colors group relative overflow-hidden">
                                    <div class="absolute left-0 top-0 bottom-0 w-0.5 bg-amber-500/50 group-hover:bg-amber-500 transition-colors"></div>
                                    <div class="flex-1 min-w-0 pr-4 pl-2">
                                        <div class="flex items-center gap-2 mb-0.5">
                                            <span class="text-[10px] bg-slate-50 dark:bg-slate-900 text-slate-400 px-1.5 py-0.5 rounded font-mono" x-text="producto.codigo"></span>
                                            <span x-show="producto.referencia" class="text-[9px] text-blue-400 font-bold" x-text="producto.referencia"></span>
                                            <span x-show="producto.has_other_stock == 1" class="text-[8px] text-orange-600 bg-orange-100 dark:bg-orange-900/30 px-1.5 rounded-full font-bold ml-1">📍 Otras Suc.</span>
                                        </div>
                                        <div class="font-bold text-sm text-slate-800 dark:text-slate-200 truncate group-hover:text-slate-900 dark:text-white" x-text="producto.descripcion"></div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="font-black text-blue-600 dark:text-blue-400 text-sm" x-text="formatMoney(producto.precio)"></div>
                                        <template x-if="parseInt(producto.controla_stock) === -1">
                                            <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm inline-block min-w-[50px] text-center bg-slate-400">No controla stock</span>
                                        </template>
                                        <template x-if="parseInt(producto.controla_stock) !== -1">
                                            <span class="text-[9px] px-2 py-0.5 rounded font-bold text-white shadow-sm inline-block min-w-[50px] text-center" :class="parseFloat(producto.stock) > 0 ? 'bg-green-600' : 'bg-red-600'" x-text="'Stock: ' + producto.stock"></span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>


                        <!-- Si no hay populares -->
                        <div x-show="!loadingPopular && popularProducts.length === 0" class="flex-1 flex flex-col items-center justify-center text-slate-600 dark:text-slate-400">
                            <div class="text-4xl mb-2">📦</div>
                            <p class="text-xs">Busque productos para comenzar</p>
                        </div>
                    </div>

                    <!-- Empty Search state -->
                    <div x-show="searchQuery.trim() !== '' && productos.length === 0 && !loading" class="text-center text-slate-500 py-10">
                        <div class="text-4xl mb-2">📦</div>
                        <p>No se encontraron resultados</p>
                    </div>

                    <!-- Loading Search -->
                    <div x-show="loading" class="text-center text-slate-400 py-10">
                        <div class="animate-spin text-2xl">⏳</div>
                    </div>
                </div>
            </section>

            <!-- Panel Derecho: Carrito -->
            <section class="w-[30%] flex flex-col bg-slate-850">

                <!-- Items del Carrito -->
                <div class="flex-1 overflow-y-auto custom-scroll">
                    <table class="w-full">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 sticky top-0 border-b border-slate-200 dark:border-slate-700 z-10">
                            <tr class="text-left text-slate-500 text-[11px] uppercase tracking-widest font-bold">
                                <th class="px-4 py-3">Detalle de Productos en Carrito</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                            <template x-for="(item, index) in cart" :key="index + '-' + (item.id ?? '')">
                                <tr class="cart-item hover:bg-white dark:hover:bg-slate-800/60 transition-colors">
                                    <td class="px-4 py-3">
                                        <!-- Fila 1: Código y Descripción -->
                                        <div class="flex items-center gap-3 mb-3">
                                            <span class="text-[10px] bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded font-mono font-black" x-text="item.codigo"></span>
                                            <div class="flex-1 min-w-0">
                                                <template x-if="item.editableDescripcion">
                                                    <input
                                                        type="text"
                                                        class="w-full text-sm font-bold text-slate-800 dark:text-slate-200 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-blue-500 outline-none transition-all shadow-sm"
                                                        :value="item.descripcion"
                                                        @input="updateDescripcion(index, $event.target.value)">
                                                </template>
                                                <template x-if="!item.editableDescripcion">
                                                    <h4 class="font-bold text-sm text-slate-800 dark:text-white leading-tight truncate" x-text="item.descripcion"></h4>
                                                </template>
                                            </div>
                                            <button @click="POSAudio.play('warning'); setTimeout(() => removeFromCart(index), 0)" class="text-slate-400 hover:text-red-500 transition-colors p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg" title="Eliminar">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- Fila 2: Cantidad, Precio, Subtotal -->
                                        <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-900/40 rounded-xl p-2.5">
                                            <!-- Cantidad -->
                                            <div class="flex flex-col gap-1">
                                                <span class="text-[9px] uppercase text-slate-400 dark:text-slate-500 font-bold tracking-tighter">Cantidad</span>
                                                <div class="flex items-center">
                                                    <input
                                                        type="text"
                                                        inputmode="decimal"
                                                        :value="formatNum(item.cantidad, 0, 3)"
                                                        @change="POSAudio.play('info'); updateQtyManual(index, parseNum($event.target.value))"
                                                        class="w-20 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-center py-1.5 text-xs font-black shadow-inner focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                                                </div>
                                            </div>

                                            <!-- Precio -->
                                            <div class="flex flex-col gap-1 items-center">
                                                <span class="text-[9px] uppercase text-slate-400 dark:text-slate-500 font-bold tracking-tighter text-center">Precio Unit.</span>
                                                <template x-if="item.editablePrecio">
                                                    <input
                                                        type="text"
                                                        inputmode="numeric"
                                                        class="w-24 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-center py-1.5 text-xs font-black shadow-inner focus:ring-2 focus:ring-blue-500 outline-none transition-all"
                                                        :value="formatNum(item.precio, 0, 0)"
                                                        @change="updatePrice(index, parseNum($event.target.value))">
                                                </template>
                                                <template x-if="!item.editablePrecio">
                                                    <div class="h-8 flex items-center justify-center font-bold text-xs text-slate-700 dark:text-slate-300" x-text="formatMoney(item.precio)"></div>
                                                </template>
                                            </div>

                                            <!-- Subtotal -->
                                            <div class="flex flex-col gap-1 items-end">
                                                <span class="text-[9px] uppercase text-slate-400 dark:text-slate-500 font-bold tracking-tighter">Total Item</span>
                                                <div class="h-8 flex items-center font-black text-sm text-blue-600 dark:text-blue-400" x-text="formatMoney(item.precio * item.cantidad)"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <!-- Empty Cart -->
                    <div x-show="cart.length === 0" class="text-center text-slate-500 py-20">
                        <div class="text-6xl mb-4 text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-24 h-24 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <p class="text-lg">Carrito vacío</p>
                        <p class="text-sm">Busque productos para agregar</p>
                    </div>
                </div>

                <!-- Footer: Totales y Acciones -->
                <div class="bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 p-4 rounded-br-[19px]">

                    <!-- Cliente y Forma de Pago -->
                    <!-- Cliente -->
                    <div class="mb-4">
                        <label class="text-sm text-slate-400 mb-1 block">Cliente</label>
                        <div class="relative flex items-center">
                            <input
                                type="text"
                                :value="currentTicket.clienteSearch"
                                @input="currentTicket.clienteSearch = $event.target.value; clienteSelectedIdx = 0; $nextTick(() => searchClientes(false))"
                                @focus="showClienteDropdown = true; clienteSelectedIdx = 0"
                                @keydown.arrow-down.prevent="if(clientesResults.length) clienteSelectedIdx = (clienteSelectedIdx + 1) % clientesResults.length"
                                @keydown.arrow-up.prevent="if(clientesResults.length) clienteSelectedIdx = (clienteSelectedIdx - 1 + clientesResults.length) % clientesResults.length"
                                @keydown.enter.prevent="if(clientesResults.length > 0 && showClienteDropdown) { POSAudio.play('success'); selectCliente(clientesResults[clienteSelectedIdx]); } else { searchClientes(true); }"
                                @keydown.escape="showClienteDropdown = false"
                                placeholder="Buscar por nombre, RUC, teléfono o email..."
                                x-ref="clienteInput"
                                class="w-full bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded px-3 py-2 pr-40 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">

                            <!-- Select de Pago Embebido -->
                            <div class="absolute right-1 top-1 bottom-1 flex items-center">
                                <select
                                    x-model="currentTicket.formaPago"
                                    class="bg-white dark:bg-slate-800 border-none text-xs font-bold text-slate-600 dark:text-slate-300 rounded focus:ring-0 focus:outline-none h-[calc(100%-4px)] mr-0.5"
                                    style="box-shadow: -2px 0 5px rgba(0,0,0,0.05);">
                                    <option value="contado">CONTADO</option>
                                    <option value="credito">CRÉDITO</option>
                                </select>
                            </div>

                            <!-- Dropdown clientes -->
                            <div
                                x-show="showClienteDropdown && (clientesResults.length > 0 || loadingClientes)"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                @click.away="showClienteDropdown = false"
                                class="absolute top-full left-0 z-20 w-full mt-1 bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg shadow-xl max-h-[300px] overflow-y-auto">
                                <div x-show="loadingClientes" class="p-4 text-center">
                                    <div class="animate-spin h-5 w-5 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-2"></div>
                                    <span class="text-[10px] text-slate-500 uppercase font-bold tracking-widest">Buscando local y en SIFEN...</span>
                                </div>

                                <template x-for="(cli, idx) in clientesResults" :key="cli.id">
                                    <div
                                        @click="POSAudio.play('success'); setTimeout(() => selectCliente(cli), 0)"
                                        @mouseenter="clienteSelectedIdx = idx"
                                        :class="clienteSelectedIdx === idx ? 'bg-blue-600/50' : 'hover:bg-slate-600'"
                                        class="px-3 py-2 cursor-pointer text-sm transition-colors border-b border-slate-600/50 last:border-none">
                                        <div class="flex justify-between items-start">
                                            <span class="font-medium" x-text="cli.nombre"></span>
                                            <span class="text-xs text-blue-400 font-mono" x-text="cli.ruc"></span>
                                        </div>
                                        <div class="text-xs text-slate-400 flex gap-3 mt-0.5">
                                            <span x-show="cli.telefono" x-text="'📞 ' + cli.telefono"></span>
                                            <span x-show="cli.email" x-text="'✉️ ' + cli.email"></span>
                                        </div>
                                    </div>
                                </template>
                                <!-- Footer -->
                                <div class="px-3 py-1.5 bg-white dark:bg-slate-800/50 text-xs text-slate-500 flex justify-between">
                                    <span>↑↓ navegar • Enter seleccionar</span>
                                    <span x-text="clientesResults.length + ' resultados'"></span>
                                </div>
                            </div>
                        </div>
                    </div>





                    <!-- Indicador de Modo Edición -->
                    <div x-show="editingVenta" class="mb-2 bg-amber-500/20 border border-amber-500 rounded-xl p-3 flex items-center justify-between animate-pulse">
                        <div class="flex items-center gap-2">
                            <span class="text-xl">✏️</span>
                            <div>
                                <div class="text-amber-500 font-black text-sm">MODO EDICIÓN</div>
                                <div class="text-amber-400 text-xs" x-text="'Editando: ' + editingVenta?.nro_factura"></div>
                            </div>
                        </div>
                        <button @click="cancelEditMode()" class="text-amber-500 hover:text-red-500 text-xs font-bold px-2 py-1 rounded bg-amber-500/20 hover:bg-red-500/20 transition-all">
                            ✕ Cancelar
                        </button>
                    </div>

                    <!-- Acciones -->
                    <div class="flex gap-2">
                        <!-- Botón COBRAR -->
                        <button
                            @click="handleFinishSale()"
                            :disabled="cart.length === 0 || processingSale"
                            :class="cart.length === 0 ? 'bg-slate-600 cursor-not-allowed' : (editingVenta ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700')"
                            class="w-full text-white font-bold py-4 rounded-xl transition-all flex items-center justify-center gap-3 text-2xl shadow-lg hover:shadow-green-500/20 active:scale-[0.98]">
                            <span x-show="!processingSale" class="flex items-center gap-3">
                                <span x-text="editingVenta ? 'GUARDAR' : 'TERMINAR'"></span>
                                <span class="bg-white dark:bg-slate-800/20 px-3 py-1 rounded-lg text-xl text-red-600 font-black" x-text="formatMoney(total) + ' Gs'"></span>
                            </span>
                            <span x-show="processingSale" class="animate-spin text-3xl">⏳</span>
                        </button>
                    </div>
                </div>
            </section>

        </main>

        <!-- Modal Pago Exitoso -->
        <div
            x-show="showSuccessModal"
            x-cloak
            class="fixed inset-0 bg-black/70 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 max-w-md w-full mx-4 text-center">
                <div class="text-6xl mb-4">✅</div>
                <h2 class="text-2xl font-bold mb-2">¡Venta Exitosa!</h2>
                <p class="text-slate-400 mb-4">Factura generada correctamente</p>
                <div class="bg-slate-50 dark:bg-slate-900 rounded-lg p-4 mb-4">
                    <div class="text-sm text-slate-400">Nro. Factura</div>
                    <div class="text-xl font-bold" x-text="lastVenta?.nro_factura"></div>
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-2" x-text="formatMoney(lastVenta?.total) + ' Gs'"></div>
                </div>
                <div class="flex gap-3">
                    <button
                        @click="printTicket()"
                        class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-bold py-2 rounded-lg">🖨️ Imprimir</button>
                    <button
                        @click="newSale()"
                        class="flex-1 bg-green-600 hover:bg-blue-600 text-white font-bold py-2 rounded-lg">➕ Nueva Venta</button>
                </div>
            </div>
        </div>

        <!-- Modal de Ticket Impresión (Para modo Pendiente) -->
        <div
            x-show="showTicketModal"
            x-cloak
            class="fixed inset-0 bg-black/80 flex items-center justify-center z-[60] backdrop-blur-sm">
            <div class="relative flex flex-col items-center gap-4 max-w-full">
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl overflow-hidden flex flex-col"
                    :style="`width: ${userConfig.ancho_papel > 0 ? (userConfig.ancho_papel > 1000 ? 1000 : userConfig.ancho_papel) + 'px' : '400px'}; height: 85vh;`">
                    <div class="bg-slate-100 dark:bg-slate-700 px-4 py-2 flex items-center justify-between border-b border-slate-200 dark:border-slate-600">
                        <span class="text-xs font-black uppercase tracking-widest text-slate-500 flex items-center gap-2">
                            <span class="text-lg">📄</span> Comprobante de Venta
                        </span>
                        <button @click="showTicketModal = false" class="text-slate-400 hover:text-red-500 transition-colors text-xl font-bold p-1">✕</button>
                    </div>
                    <div class="flex-1 overflow-hidden bg-white">
                        <template x-if="showTicketModal">
                            <iframe :src="ticketUrl" class="w-full h-full border-none"></iframe>
                        </template>
                    </div>
                </div>

                <!-- Botones fuera del bloque de comprobante -->
                <div class="flex gap-4 w-full justify-center">
                    <button
                        @click="window.open(ticketUrl, '_blank')"
                        class="bg-slate-100/10 hover:bg-slate-100/20 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all flex items-center justify-center gap-2 border border-white/20 backdrop-blur-md">
                        <span>🖨️</span> Imprimir
                    </button>

                    <button
                        @click="showWhatsAppModal = true; phoneInput = lastVenta.cliente_telefono || ''"
                        class="bg-[#25D366] hover:bg-[#128C7E] text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-green-500/20 active:scale-[0.98] flex items-center justify-center gap-2">
                        <span>💬</span> WhatsApp
                    </button>

                    <button
                        @click="showEmailModal = true; emailInput = lastVenta.cliente_email || ''"
                        class="bg-slate-700 hover:bg-slate-600 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-slate-900/20 active:scale-[0.98] flex items-center justify-center gap-2 border border-slate-600">
                        <span>📧</span> Email
                    </button>

                    <button
                        x-show="isLastVentaEligibleForSifen()"
                        @click="convertirSifen()"
                        :disabled="loadingSifen"
                        class="bg-amber-500 hover:bg-amber-600 text-slate-900 font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-amber-500/20 active:scale-[0.98] flex items-center justify-center gap-2">
                        <span x-show="!loadingSifen" class="flex items-center gap-2">⚡ <span>Emitir FE</span></span>
                        <span x-show="loadingSifen" class="animate-spin text-lg">⏳</span>
                    </button>

                    <button
                        @click="showTicketModal = false; newSale()"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-blue-500/20 active:scale-[0.98]">
                        Nueva Venta
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Preview de Venta (Antes de Confirmar) -->
        <div
            x-show="showPreviewModal"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-md"
            @keydown.escape.window="showPreviewModal = false">
            <div
                @click.away="showPreviewModal = false"
                class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl border-2 border-green-500 w-full max-w-md max-h-[90vh] overflow-hidden flex flex-col">
                <!-- Header -->
                <div class="bg-gradient-to-r from-green-600 to-green-700 text-white p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">📄</span>
                        <div>
                            <h2 class="text-xl font-black">PREVIEW DE VENTA</h2>
                            <p class="text-green-200 text-xs">Verifique antes de confirmar</p>
                        </div>
                    </div>
                    <button @click="showPreviewModal = false" class="text-white/70 hover:text-white text-2xl font-bold">&times;</button>
                </div>

                <!-- Content - Scrollable -->
                <div class="flex-1 overflow-y-auto p-4 space-y-4" style="font-family: 'Courier New', monospace; font-size: 11px;">

                    <!-- Empresa Header -->
                    <div class="text-center border-b border-dashed border-slate-300 dark:border-slate-600 pb-3">
                        <div class="font-black text-slate-900 dark:text-white text-sm uppercase"><?php echo htmlspecialchars($empresa['empresa'] ?? 'EMPRESA'); ?></div>
                        <div class="text-slate-500 text-[10px]">RUC: <?php echo htmlspecialchars($empresa['ruc'] ?? ''); ?>-<?php echo htmlspecialchars($empresa['dv'] ?? ''); ?></div>
                        <div class="inline-block bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-[10px] font-black px-2 py-0.5 mt-1 rounded" x-text="selectedDocType === 'electro' ? 'FACTURA ELECTRÓNICA' : 'NOTA DE VENTA'"></div>
                        <div class="text-slate-400 text-[9px] mt-1" x-text="new Date().toLocaleString('es-PY')"></div>
                    </div>

                    <!-- Cliente -->
                    <div class="border-b border-slate-200 dark:border-slate-700 pb-2">
                        <div class="text-[9px] font-black text-slate-500 uppercase">Cliente</div>
                        <div class="flex justify-between text-slate-900 dark:text-white">
                            <span x-text="currentTicket.selectedCliente?.nombre || 'Consumidor Final'"></span>
                            <span class="text-slate-500" x-text="currentTicket.selectedCliente?.ruc || '4444440-1'"></span>
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="border-b border-slate-200 dark:border-slate-700 pb-2">
                        <div class="text-[9px] font-black text-slate-500 uppercase mb-1">Detalle</div>
                        <table class="w-full text-[10px]">
                            <thead>
                                <tr class="border-b border-slate-300 dark:border-slate-600">
                                    <th class="text-left py-0.5 text-slate-500">Cant</th>
                                    <th class="text-left py-0.5 text-slate-500">Descripción</th>
                                    <th class="text-right py-0.5 text-slate-500">P.Unit</th>
                                    <th class="text-right py-0.5 text-slate-500">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="item in cart" :key="item.id">
                                    <tr class="text-slate-900 dark:text-white">
                                        <td class="py-0.5" x-text="item.cantidad"></td>
                                        <td class="py-0.5 truncate max-w-[120px]" x-text="item.descripcion"></td>
                                        <td class="py-0.5 text-right" x-text="formatMoney(item.precio)"></td>
                                        <td class="py-0.5 text-right font-bold" x-text="formatMoney(item.precio * item.cantidad)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totales -->
                    <div class="bg-slate-100 dark:bg-slate-900 rounded-lg p-3 space-y-1">
                        <div class="flex justify-between items-center text-lg font-black text-slate-900 dark:text-white border-b border-slate-300 dark:border-slate-700 pb-2">
                            <span>TOTAL:</span>
                            <span x-text="formatMoney(total) + ' Gs'"></span>
                        </div>

                        <!-- Detalle de Pagos -->
                        <template x-for="p in paymentsList" :key="p.method">
                            <div class="space-y-0.5">
                                <div class="flex justify-between text-sm text-slate-700 dark:text-slate-300">
                                    <span class="uppercase font-bold" x-text="p.name || p.method"></span>
                                    <span x-text="formatMoney(p.amount) + ' Gs'"></span>
                                </div>
                                <template x-if="p.method === 'efectivo' && p.cash_received > 0">
                                    <div class="text-[11px] text-slate-500 pl-2">
                                        <div class="flex justify-between">
                                            <span>Entregado:</span>
                                            <span class="font-bold" x-text="formatMoney(p.cash_received) + ' Gs'"></span>
                                        </div>
                                        <div class="flex justify-between text-green-600 font-bold" x-show="p.cash_change > 0">
                                            <span>VUELTO:</span>
                                            <span x-text="formatMoney(p.cash_change) + ' Gs'"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                </div>

                <!-- Footer Buttons -->
                <div class="p-4 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 flex gap-3">
                    <button
                        @click="showPreviewModal = false"
                        class="flex-1 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-bold py-3 rounded-xl transition-all">
                        ← VOLVER
                    </button>
                    <button
                        @click="showPreviewModal = false; editingVenta ? updateVenta() : confirmSale()"
                        :disabled="processingSale"
                        :class="editingVenta ? 'bg-amber-600 hover:bg-amber-700 shadow-amber-900/30' : 'bg-green-600 hover:bg-green-700 shadow-green-900/30'"
                        class="flex-[2] text-white font-black py-3 rounded-xl shadow-lg transition-all flex items-center justify-center gap-2 active:scale-[0.98]">
                        <template x-if="!processingSale">
                            <span class="flex items-center gap-2">
                                <span class="text-xl" x-text="editingVenta ? '💾' : '✅'"></span>
                                <span x-text="editingVenta ? 'GUARDAR CAMBIOS' : 'CONFIRMAR VENTA'"></span>
                            </span>
                        </template>
                        <template x-if="processingSale">
                            <span class="flex items-center gap-2">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Procesando...
                            </span>
                        </template>
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Buscar Venta para Editar -->
        <div
            x-show="showEditSearchModal"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            class="fixed inset-0 z-[85] flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-md"
            @keydown.escape.window="showEditSearchModal = false">
            <div
                @click.away="showEditSearchModal = false"
                class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl border-2 border-amber-500 w-full max-w-lg max-h-[85vh] overflow-hidden flex flex-col">
                <!-- Header -->
                <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">📋</span>
                        <div>
                            <h2 class="text-xl font-black">MIS VENTAS</h2>
                            <p class="text-amber-200 text-xs">Últimos 30 días - Solo notas editables</p>
                        </div>
                    </div>
                    <button @click="showEditSearchModal = false" class="text-white/70 hover:text-white text-2xl font-bold">&times;</button>
                </div>

                <!-- Search Input -->
                <div class="p-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
                        <input
                            type="text"
                            x-model="editSearchQuery"
                            @input.debounce.300ms="searchEditableVentas()"
                            placeholder="Buscar por nro. factura o cliente..."
                            class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl pl-10 pr-4 py-3 text-sm outline-none focus:border-amber-500 transition-all">
                    </div>
                </div>

                <!-- Results List -->
                <div class="flex-1 overflow-y-auto p-2">
                    <!-- Loading -->
                    <div x-show="loadingEditSearch" class="flex items-center justify-center py-8">
                        <svg class="animate-spin h-8 w-8 text-amber-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </div>

                    <!-- No Results -->
                    <div x-show="!loadingEditSearch && editSearchResults.length === 0" class="text-center py-8 text-slate-500">
                        <span class="text-4xl block mb-2">📋</span>
                        <p>No se encontraron notas de venta editables</p>
                        <p class="text-xs mt-1">Solo se pueden editar ventas de los últimos 30 días</p>
                    </div>

                    <!-- Results -->
                    <div x-show="!loadingEditSearch && editSearchResults.length > 0" class="space-y-2">
                        <template x-for="venta in editSearchResults" :key="venta.id_factura">
                            <button
                                @click="loadVentaForEdit(venta.id_factura)"
                                class="w-full bg-slate-50 dark:bg-slate-900 hover:bg-amber-50 dark:hover:bg-amber-900/30 border border-slate-200 dark:border-slate-700 hover:border-amber-400 rounded-xl p-3 text-left transition-all group">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-black text-slate-900 dark:text-white text-sm" x-text="venta.nro_factura"></div>
                                        <div class="text-xs text-slate-500" x-text="venta.cliente_nombre || 'Sin cliente'"></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-bold text-amber-600" x-text="formatMoney(venta.total) + ' Gs'"></div>
                                        <div class="text-[10px] text-slate-400" x-text="new Date(venta.fecha).toLocaleDateString('es-PY')"></div>
                                    </div>
                                </div>
                                <div class="mt-1 flex items-center gap-2">
                                    <span class="text-[9px] bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded font-bold">NOTA DE VENTA</span>
                                    <span class="text-amber-500 opacity-0 group-hover:opacity-100 transition-opacity text-xs">→ Click para editar</span>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Footer -->
                <div class="p-4 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700">
                    <button
                        @click="showEditSearchModal = false"
                        class="w-full bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-bold py-2 rounded-xl transition-all">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Detalle de Producto -->
        <div
            x-show="showProductDetailModal"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @keydown.escape.window="showProductDetailModal = false"
            class="fixed inset-0 bg-black/80 backdrop-blur-sm flex items-start justify-center z-50 p-4 pt-8 overflow-y-auto">
            <div
                x-show="showProductDetailModal"
                x-transition:enter="transition ease-out duration-200 delay-75"
                x-transition:enter-start="opacity-0 scale-95 translate-y-4"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                @click.away="showProductDetailModal = false"
                class="bg-white dark:bg-slate-800 rounded-2xl w-full max-w-5xl max-h-[85vh] overflow-hidden flex flex-col shadow-2xl ring-1 ring-white/10 my-auto">
                <!-- Header del Modal -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-white dark:bg-slate-800/20 rounded-xl flex items-center justify-center text-2xl">📦</div>
                        <div>
                            <h2 class="text-xl font-bold" x-text="productDetail?.producto?.descripcion || 'Producto'"></h2>
                            <div class="flex items-center gap-3 text-sm text-slate-900 dark:text-white/80">
                                <span x-text="'Cód: ' + (productDetail?.producto?.codigo || '')"></span>
                                <span>•</span>
                                <span x-text="productDetail?.producto?.categoria || 'Sin categoría'"></span>
                            </div>
                        </div>
                    </div>
                    <button @click="showProductDetailModal = false" class="text-slate-900 dark:text-white/80 hover:text-slate-900 dark:text-white text-2xl">✕</button>
                </div>

                <!-- Contenido del Modal -->
                <div class="flex-1 overflow-y-auto p-6 custom-scroll">
                    <!-- Loading -->
                    <div x-show="loadingDetail" class="text-center py-10">
                        <div class="animate-spin text-4xl">⏳</div>
                        <p class="text-slate-400 mt-2">Cargando información...</p>
                    </div>

                    <div x-show="!loadingDetail && productDetail" class="space-y-6">
                        <!-- Fila Superior: Foto + Info Básica + Precios -->
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                            <!-- Foto del Producto -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4 flex items-center justify-center">
                                <template x-if="productDetail?.producto?.foto">
                                    <img :src="'../uploads/productos/' + productDetail.producto.foto"
                                        class="max-w-full max-h-48 object-contain rounded-lg"
                                        alt="Foto producto">
                                </template>
                                <template x-if="!productDetail?.producto?.foto">
                                    <div class="text-6xl text-slate-600 dark:text-slate-400">📷</div>
                                </template>
                            </div>

                            <!-- Información Básica -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                    📋 Información
                                </h3>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">Código:</span>
                                        <span class="font-mono text-blue-400" x-text="productDetail?.producto?.codigo"></span>
                                    </div>
                                    <div class="flex justify-between" x-show="productDetail?.producto?.marca_nombre">
                                        <span class="text-slate-400">Marca:</span>
                                        <span x-text="productDetail?.producto?.marca_nombre"></span>
                                    </div>
                                    <div class="flex justify-between" x-show="productDetail?.producto?.modelo_nombre">
                                        <span class="text-slate-400">Modelo:</span>
                                        <span x-text="productDetail?.producto?.modelo_nombre"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">IVA:</span>
                                        <span x-text="(productDetail?.producto?.tasa_iva || 10) + '%'"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">Stock Mín:</span>
                                        <span x-text="productDetail?.producto?.stock_minimo || 0"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">Stock Máx:</span>
                                        <span x-text="productDetail?.producto?.stock_maximo || 0"></span>
                                    </div>
                                    <template x-if="productDetail?.codigos_barra?.length > 0">
                                        <div class="pt-2 border-t border-slate-200 dark:border-slate-700">
                                            <span class="text-slate-400 block mb-1">Códigos de barra:</span>
                                            <template x-for="cb in productDetail.codigos_barra" :key="cb">
                                                <span class="inline-block bg-slate-100 dark:bg-slate-700 px-2 py-1 rounded text-xs mr-1 mb-1 font-mono" x-text="cb"></span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Precios Habilitados -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                    Precios Habilitados
                                </h3>
                                <div class="space-y-2">
                                    <template x-for="precio in productDetail?.precios || []" :key="precio.tipo">
                                        <div class="flex justify-between items-center bg-white dark:bg-slate-800 rounded-lg px-3 py-2">
                                            <span class="text-sm" x-text="precio.nombre_tipo || ('Precio ' + precio.tipo)"></span>
                                            <span class="font-bold text-blue-600 dark:text-blue-400" x-text="formatMoney(precio.precio) + ' Gs'"></span>
                                        </div>
                                    </template>
                                    <div x-show="!productDetail?.precios?.length" class="text-slate-500 text-sm text-center py-2">
                                        Sin precios definidos
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Fila: Stock por Sucursal -->
                        <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                            <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                🏢 Stock por Sucursal
                            </h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                <template x-for="suc in productDetail?.stock_por_sucursal || []" :key="suc.id_sucursal">
                                    <div class="bg-white dark:bg-slate-800 rounded-lg px-3 py-2 flex justify-between items-center">
                                        <div>
                                            <div class="font-medium text-sm" x-text="suc.nombre_sucursal"></div>
                                            <div class="text-xs text-slate-500" x-text="suc.ciudad"></div>
                                        </div>
                                        <div :class="parseFloat(suc.stock) > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-red-400'"
                                            class="font-bold text-lg" x-text="parseFloat(suc.stock || 0).toFixed(0)">
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Fila: Equivalentes + Clientes -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                            <!-- Productos Equivalentes -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                    🔄 Productos Equivalentes
                                    <span class="text-xs bg-blue-600/50 px-2 py-0.5 rounded-full" x-text="(productDetail?.equivalentes?.length || 0) + ' encontrados'"></span>
                                </h3>
                                <div class="max-h-48 overflow-y-auto custom-scroll space-y-2">
                                    <template x-for="eq in productDetail?.equivalentes || []" :key="eq.id">
                                        <div @click="addToCart(eq); showProductDetailModal = false"
                                            class="bg-white dark:bg-slate-800 hover:bg-slate-100 dark:bg-slate-700 rounded-lg px-3 py-2 cursor-pointer transition flex justify-between items-center">
                                            <div>
                                                <div class="font-medium text-sm truncate" x-text="eq.descripcion"></div>
                                                <div class="text-xs text-slate-500" x-text="'Cód: ' + eq.codigo"></div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-blue-600 dark:text-blue-400 font-bold text-sm" x-text="formatMoney(eq.precio_venta) + ' Gs'"></div>
                                                <div class="text-xs text-slate-500" x-text="'Stock: ' + (eq.stock || 0)"></div>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="!productDetail?.equivalentes?.length" class="text-slate-500 text-sm text-center py-4">
                                        No hay productos equivalentes
                                    </div>
                                </div>
                            </div>

                            <!-- Clientes que Compraron -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                    👥 Clientes que Compraron
                                    <span class="text-xs bg-purple-600/50 px-2 py-0.5 rounded-full" x-text="(productDetail?.clientes_compraron?.length || 0) + ' registros'"></span>
                                </h3>
                                <div class="max-h-48 overflow-y-auto custom-scroll">
                                    <table class="w-full text-sm">
                                        <thead class="text-slate-400 text-xs">
                                            <tr>
                                                <th class="text-left py-1">Cliente</th>
                                                <th class="text-right py-1">Fecha</th>
                                                <th class="text-right py-1">Cant.</th>
                                                <th class="text-right py-1">Importe</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="cli in productDetail?.clientes_compraron || []" :key="cli.id_cliente + '-' + cli.fecha">
                                                <tr class="border-t border-slate-200 dark:border-slate-700/50">
                                                    <td class="py-2">
                                                        <div class="font-medium truncate max-w-[150px]" x-text="cli.cliente_nombre"></div>
                                                        <div class="text-xs text-slate-500" x-text="cli.cliente_ruc"></div>
                                                    </td>
                                                    <td class="text-right text-xs text-slate-400" x-text="formatDate(cli.fecha)"></td>
                                                    <td class="text-right" x-text="cli.cantidad"></td>
                                                    <td class="text-right text-blue-600 dark:text-blue-400" x-text="formatMoney(cli.importe)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                    <div x-show="!productDetail?.clientes_compraron?.length" class="text-slate-500 text-sm text-center py-4">
                                        No hay historial de ventas
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer del Modal -->
                <div class="bg-slate-50 dark:bg-slate-900 px-6 py-4 flex justify-between items-center border-t border-slate-200 dark:border-slate-700">
                    <div class="text-sm text-slate-400">
                        <span class="text-xs">Clic derecho = Ver detalle</span>
                    </div>
                    <div class="flex gap-3">
                        <button
                            @click="showProductDetailModal = false"
                            class="px-4 py-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-600 rounded-lg transition">Cerrar</button>
                        <button
                            @click="POSAudio.play('success'); setTimeout(() => { addToCart({ 
                            id: productDetail.producto.id,
                            codigo: productDetail.producto.codigo,
                            descripcion: productDetail.producto.descripcion,
                            precio: parseFloat(productDetail.producto.precio),
                            tasa_iva: productDetail.producto.tasa_iva
                        }); showProductDetailModal = false }, 0)"
                            class="w-full bg-green-600 hover:bg-blue-600 text-white font-bold py-4 rounded-xl shadow-lg transition-all transform active:scale-95 flex items-center justify-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-400 group-hover:text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <span>Agregar al Carrito</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de Impresión de Ticket -->
        <div
            x-show="showTicketModal"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-md"
            @keydown.escape.window="showTicketModal = false">
            <div
                @click.away="showTicketModal = false"
                class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl border-2 border-slate-600 w-full h-[85vh] flex flex-col overflow-hidden transition-all duration-300"
                :class="userConfig.ancho_papel > 500 ? 'max-w-4xl' : 'max-w-[380px]'">
                <div class="bg-slate-900 text-white p-2 flex justify-between items-center">
                    <span class="font-bold text-sm">🖨️ TICKET</span>
                    <button @click="showTicketModal = false" class="text-white hover:text-red-400 text-xl font-bold px-2">&times;</button>
                </div>

                <div class="flex-1 bg-white relative">
                    <!-- Iframe para cargar el ticket -->
                    <iframe
                        :src="ticketUrl"
                        class="w-full h-full border-none"
                        name="ticketFrame"></iframe>
                </div>

                <div class="p-2 bg-slate-100 dark:bg-slate-700 flex gap-2">
                    <button
                        @click="showTicketModal = false; $nextTick(() => document.body.focus())"
                        autofocus
                        @keydown.enter="showTicketModal = false"
                        class="flex-1 bg-slate-600 hover:bg-slate-500 text-white font-bold py-3 rounded-lg text-sm">
                        CERRAR [ESC]
                    </button>
                    <button
                        @click="document.getElementsByName('ticketFrame')[0].contentWindow.print()"
                        class="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-lg text-sm">
                        🖨️ IMPRIMIR
                    </button>
                </div>
            </div>
        </div>

        <!-- Toast Notifications -->
        <div class="fixed top-4 right-4 z-50 flex flex-col gap-2 pointer-events-none">
            <template x-for="(toast, index) in toasts" :key="toast.id">
                <div
                    x-show="toast.show"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-x-8"
                    x-transition:enter-end="opacity-100 translate-x-0"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-x-0"
                    x-transition:leave-end="opacity-0 translate-x-8"
                    :class="{
                    'bg-green-600': toast.type === 'success',
                    'bg-red-600': toast.type === 'error',
                    'bg-yellow-600': toast.type === 'warning',
                    'bg-blue-600': toast.type === 'info'
                }"
                    class="px-4 py-3 rounded-lg shadow-xl text-slate-900 dark:text-white flex items-center gap-3 min-w-72 max-w-sm pointer-events-auto">
                    <span x-text="toast.type === 'success' ? '✅' : toast.type === 'error' ? '❌' : toast.type === 'warning' ? '⚠️' : 'ℹ️'" class="text-lg"></span>
                    <span class="flex-1" x-text="toast.message"></span>
                    <button @click="removeToast(toast.id)" class="opacity-60 hover:opacity-100 text-lg">&times;</button>
                </div>
            </template>
        </div>

        <!-- Modal de Confirmación -->
        <div
            x-show="confirmModal.show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
            @click.self="cancelConfirm()">
            <div
                x-show="confirmModal.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl max-w-md w-full mx-4 overflow-hidden border border-slate-200 dark:border-slate-700">
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <span class="text-3xl" x-text="confirmModal.icon || '❓'"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2" x-text="confirmModal.title || 'Confirmar'"></h3>
                            <p class="text-slate-600 dark:text-slate-400 whitespace-pre-line" x-text="confirmModal.message"></p>
                        </div>
                    </div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-900/50 px-6 py-4 flex justify-end gap-3">
                    <button
                        @click="cancelConfirm()"
                        class="px-4 py-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-600 text-slate-900 dark:text-white rounded-lg transition-colors">Cancelar</button>
                    <button
                        @click="acceptConfirm()"
                        :class="confirmModal.type === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"
                        class="px-4 py-2 text-slate-900 dark:text-white rounded-lg transition-colors font-medium"
                        x-text="confirmModal.confirmText || 'Aceptar'"></button>
                </div>
            </div>
        </div>

        <!-- Modal de Cobro (Checkout) -->
        <div
            x-show="showCheckoutModal"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm"
            @keydown.escape.window="if(showCheckoutModal) { if(selectedPaymentMethod) { selectedPaymentMethod = null; } else { showCheckoutModal = false; } }"
            @keydown.arrow-down.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowDown')"
            @keydown.arrow-up.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowUp')"
            @keydown.arrow-left.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowLeft')"
            @keydown.arrow-right.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowRight')"
            @keydown.enter.window.prevent="if(showCheckoutModal) { if(selectedPaymentMethod) { confirmUnifiedPayment(); } else { selectFocusedItem(); } }">
            <div
                @click.away="showCheckoutModal = false"
                class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Header Modal -->
                <div class="bg-slate-100 dark:bg-slate-700/30 p-4 border-b border-slate-600 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <div class="bg-blue-600/20 p-2 rounded-lg text-blue-600 dark:text-blue-400 text-xl font-black">$</div>
                        <div>
                            <h2 class="text-lg font-bold text-slate-900 dark:text-white leading-tight">Finalizar Venta</h2>
                            <p class="text-[10px] text-slate-400 uppercase tracking-tighter">Click derecho para fijar predeterminados</p>
                        </div>
                    </div>
                    <button @click="showCheckoutModal = false" class="text-slate-400 hover:text-slate-900 dark:text-white transition-colors text-2xl px-2">×</button>
                </div>

                <div class="p-4 overflow-y-auto custom-scroll flex-1">
                    <div class="space-y-6 max-w-xl mx-auto">


                        <!-- Resumen de Pagos e Interfaz Multi-Medio -->
                        <div class="bg-slate-50 dark:bg-slate-900/50 rounded-2xl border-2 border-dashed border-slate-700 p-4 space-y-4">
                            <div class="flex justify-between items-center bg-white dark:bg-slate-800 p-3 rounded-xl shadow-sm border border-slate-700">
                                <div>
                                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest block">Total Venta</span>
                                    <span class="text-2xl font-black text-slate-900 dark:text-white" x-text="formatMoney(total)"></span>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] font-black uppercase tracking-widest block"
                                        :class="(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)) < 0 ? 'text-green-500' : 'text-slate-500'"
                                        x-text="(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)) < 0 ? 'Vuelto' : 'Faltante'"></span>
                                    <span class="text-2xl font-black"
                                        :class="(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)) > 0 ? 'text-red-500' : 'text-green-500'"
                                        x-text="formatMoney(Math.abs(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)))"></span>
                                </div>
                            </div>

                            <!-- Lista de Pagos Agregados -->
                            <div x-show="paymentsList.length > 0" class="space-y-2">
                                <template x-for="(p, pidx) in paymentsList" :key="pidx">
                                    <div class="bg-blue-600/10 border border-blue-500/30 p-2 rounded-lg group animate-in fade-in slide-in-from-left-2">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <div class="bg-blue-600 text-white text-[10px] font-black px-1.5 py-0.5 rounded capitalize" x-text="p.name || p.method"></div>
                                                <span class="text-sm font-bold text-slate-900 dark:text-white" x-text="formatMoney(p.amount)"></span>
                                                <template x-if="p.voucher_number || p.transfer_reference">
                                                    <span class="text-[10px] text-slate-500 italic" x-text="'Ref: ' + (p.voucher_number || p.transfer_reference)"></span>
                                                </template>
                                            </div>
                                            <button @click="paymentsList.splice(pidx, 1); POSAudio.play('warning')" class="text-slate-500 hover:text-red-500 transition-colors p-1">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                        <!-- Detalle de Efectivo: Entregado y Vuelto -->
                                        <template x-if="p.method === 'efectivo' && p.cash_received > 0">
                                            <div class="mt-1 pt-1 border-t border-blue-500/20 grid grid-cols-2 gap-2 text-[11px]">
                                                <div class="flex justify-between">
                                                    <span class="text-slate-400">Entregado:</span>
                                                    <span class="font-bold text-slate-200" x-text="formatMoney(p.cash_received) + ' Gs'"></span>
                                                </div>
                                                <div class="flex justify-between" x-show="p.cash_change > 0">
                                                    <span class="text-green-400 font-bold">Vuelto:</span>
                                                    <span class="font-black text-green-400" x-text="formatMoney(p.cash_change) + ' Gs'"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <!-- Boton Finalizar Venta -->
                            <div x-show="remainingAmount <= 0 && paymentsList.length > 0">
                                <button
                                    @click="editingVenta ? updateVenta() : confirmSale()"
                                    :disabled="processingSale"
                                    :class="editingVenta ? 'bg-amber-600 hover:bg-amber-700' : 'bg-green-600 hover:bg-green-700'"
                                    class="w-full text-white font-black py-4 rounded-xl shadow-lg shadow-green-900/20 transition-all flex items-center justify-center gap-3 transform active:scale-95">
                                    <template x-if="!processingSale">
                                        <span class="text-lg" x-text="editingVenta ? 'GUARDAR CAMBIOS' : 'CONFIRMAR E IMPRIMIR'"></span>
                                    </template>
                                    <template x-if="processingSale">
                                        <span class="flex items-center gap-2">
                                            <svg class="animate-spin h-6 w-6" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            Procesando...
                                        </span>
                                    </template>
                                </button>
                            </div>
                        </div>

                        <!-- Panel de Entrada de Pago (Unificado) -->
                        <div x-show="selectedPaymentMethod" class="bg-blue-600/5 dark:bg-blue-600/10 rounded-2xl border border-blue-500/30 p-4 space-y-4 animate-in zoom-in-95 duration-200">
                            <div class="flex items-center gap-3 mb-2">
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white uppercase tracking-tight" x-text="'Detalle de ' + (paymentMethods.find(m => m.id === selectedPaymentMethod)?.name || '')"></h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Monto -->
                                <div>
                                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Monto a Pagar</label>
                                    <div class="relative group">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-sm">Gs.</span>
                                        <input
                                            type="text"
                                            inputmode="numeric"
                                            :value="formatNum(cashAmountReceived, 0, 0)"
                                            @input="cashAmountReceived = parseNum($event.target.value)"
                                            @keydown.enter.prevent="confirmUnifiedPayment()"
                                            x-ref="unifiedAmountInput"
                                            class="w-full bg-white dark:bg-slate-900 border border-slate-700 focus:border-blue-500 rounded-xl px-10 py-3 text-2xl font-black text-slate-900 dark:text-white outline-none transition-all shadow-inner">
                                    </div>
                                </div>

                                <!-- Campos Específicos -->
                                <div class="space-y-3">
                                    <!-- Tarjeta -->
                                    <template x-if="selectedPaymentMethod === 'tarjeta'">
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Nro. de Boucher / Lote</label>
                                            <input type="text" x-model="voucherNumber" class="w-full bg-white dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-white outline-none" placeholder="000XXX">
                                        </div>
                                    </template>

                                    <!-- Transferencia -->
                                    <template x-if="selectedPaymentMethod === 'transferencia'">
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Referencia / Observación</label>
                                            <input type="text" x-model="transferReference" class="w-full bg-white dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-white outline-none" placeholder="Nro de Transacción">
                                        </div>
                                    </template>

                                    <!-- QR -->
                                    <template x-if="selectedPaymentMethod === 'qr'">
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Cód. Confirmación QR</label>
                                            <input type="text" x-model="qrTransactionCode" class="w-full bg-white dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-white outline-none" placeholder="Opcional">
                                        </div>
                                    </template>

                                    <!-- Crédito -->
                                    <template x-if="selectedPaymentMethod === 'credito'">
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Cuotas</label>
                                                <input type="number" x-model="creditInstallments" class="w-full bg-white dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-white outline-none">
                                            </div>
                                            <div>
                                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Vto. 1ra Cuota</label>
                                                <input type="date" x-model="creditDueDate" class="w-full bg-white dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-xs font-bold text-white outline-none">
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <button
                                    @click="confirmUnifiedPayment()"
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl shadow-lg transition-all transform active:scale-95">AGREGAR PAGO</button>
                                <button
                                    @click="selectedPaymentMethod = null"
                                    class="px-6 bg-slate-700 hover:bg-slate-600 text-slate-300 font-bold py-3 rounded-xl transition-all">CANCELAR</button>
                            </div>
                        </div>

                        <!-- Botones de Medio de Pago -->
                        <div x-show="remainingAmount > 0 && !selectedPaymentMethod" class="animate-in fade-in slide-in-from-top-4 duration-300">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 block">Seleccionar Medio de Pago</label>
                            <div class="grid grid-cols-4 gap-2">
                                <template x-for="(pm, index) in paymentMethods" :key="pm.id">
                                    <button
                                        @click="selectUnifiedMethod(pm)"
                                        :class="{
                                        'bg-blue-600 border-blue-400 ring-2 ring-white/50 scale-[1.01]': focusedCheckoutSection === 'paymentMethods' && focusedCheckoutIdx === index,
                                        'bg-slate-100 dark:bg-slate-700 border-slate-600 opacity-90 hover:opacity-100': !(focusedCheckoutSection === 'paymentMethods' && focusedCheckoutIdx === index)
                                    }"
                                        class="flex flex-col items-center justify-center p-3 rounded-xl border-2 transition-all gap-1.5 h-24 text-center relative overflow-hidden group">
                                        <span class="text-[11px] font-black text-slate-900 dark:text-white uppercase leading-tight px-1" x-text="pm.name"></span>

                                        <div x-show="defaultPaymentMethod === pm.id" class="absolute top-1 left-1">
                                            <span class="text-[8px] bg-yellow-400 text-slate-900 dark:text-white px-1 rounded font-black">⭐</span>
                                        </div>

                                        <div class="absolute inset-0 bg-blue-600 opacity-0 group-hover:opacity-10 transition-opacity"></div>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Feedback de Procesamiento -->
                        <div x-show="processingSale" class="flex flex-col items-center justify-center py-4 bg-slate-50 dark:bg-slate-900/50 rounded-xl border border-blue-500/30 gap-2 animate-pulse">
                            <div class="animate-spin h-8 w-8 border-4 border-blue-400 border-t-transparent rounded-full"></div>
                            <span class="text-blue-400 font-black text-[10px] uppercase tracking-widest">Procesando Venta...</span>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Modal de Espera UENO -->
            <div
                x-show="showUenoModal"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                class="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-blue-900/60 backdrop-blur-md">
                <div class="bg-white dark:bg-slate-800 rounded-3xl p-8 max-w-sm w-full text-center shadow-2xl border-4 border-blue-500">
                    <div class="mb-6 relative">
                        <div class="w-20 h-20 bg-blue-600 rounded-full flex items-center justify-center mx-auto animate-bounce shadow-lg">
                            <span class="text-4xl text-white font-black">UENO</span>
                        </div>
                        <!-- Spinner alrededor -->
                        <div class="absolute inset-0 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin w-24 h-24 -top-2 -left-2 mx-auto"></div>
                    </div>

                    <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-2 italic">¡Esperando Pago!</h3>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mb-6 leading-relaxed">
                        Hemos abierto la pasarela de pagos de <b>ueno</b> en una ventana nueva.
                        Por favor, complete la transacción allí.
                    </p>

                    <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-2xl mb-6 border border-blue-200 dark:border-blue-700">
                        <span class="text-[10px] font-black uppercase tracking-widest text-blue-500 block mb-1">Estado en tiempo real</span>
                        <span class="text-sm font-bold text-blue-700 dark:text-blue-300">Sincronizando con Webhook...</span>
                    </div>

                    <button
                        @click="showUenoModal = false; clearCart();"
                        class="w-full bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-white font-bold py-3 rounded-xl transition-all">
                        Cerrar Ventana y Nueva Venta
                    </button>
                    <p class="text-[9px] text-slate-400 mt-4 uppercase tracking-tighter">La factura se generará automáticamente al confirmar</p>
                </div>
            </div>

            <!-- Modal de Contraseña Admin -->
            <div
                x-show="passwordModal.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                @click.self="cancelPassword()">
                <div
                    x-show="passwordModal.show"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-90"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl max-w-sm w-full mx-4 overflow-hidden border border-slate-200 dark:border-slate-700">
                    <div class="p-6">
                        <div class="text-center mb-4">
                            <span class="text-4xl">🔐</span>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mt-2" x-text="passwordModal.title || 'Autorización Requerida'"></h3>
                            <p class="text-slate-400 text-sm mt-1" x-text="passwordModal.message"></p>
                        </div>
                        <div class="mb-4">
                            <label class="text-sm text-slate-400 mb-1 block">Contraseña del Administrador</label>
                            <input
                                type="password"
                                x-model="passwordModal.password"
                                @keydown.enter="verifyAdminPassword()"
                                @focus="$el.removeAttribute('readonly')"
                                x-ref="adminPasswordInput"
                                class="w-full bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg px-4 py-3 text-center text-lg tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="••••••"
                                autocomplete="off"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                data-lpignore="true"
                                data-form-type="other"
                                readonly>
                        </div>
                        <p x-show="passwordModal.error" class="text-red-400 text-sm text-center mb-3" x-text="passwordModal.error"></p>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-900/50 px-6 py-4 flex justify-end gap-3">
                        <button
                            @click="cancelPassword()"
                            class="px-4 py-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-600 text-slate-900 dark:text-white rounded-lg transition-colors">Cancelar</button>
                        <button
                            @click="verifyAdminPassword()"
                            :disabled="passwordModal.verifying"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white dark:text-white rounded-lg transition-colors font-medium disabled:opacity-50">
                            <span x-show="!passwordModal.verifying">Autorizar</span>
                            <span x-show="passwordModal.verifying">Verificando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // Global Audio System (Outside Alpine to bypass reactivity overhead)
            const POSAudio = {
                ctx: null,
                muted: localStorage.getItem('pos_sound_muted') === 'true',
                sounds: {
                    success: {
                        freq: 880,
                        duration: 0.1,
                        type: 'sine',
                        vol: 0.15
                    },
                    error: {
                        freq: 220,
                        duration: 0.2,
                        type: 'square',
                        vol: 0.1
                    },
                    warning: {
                        freq: 440,
                        duration: 0.15,
                        type: 'triangle',
                        vol: 0.12
                    },
                    info: {
                        freq: 660,
                        duration: 0.08,
                        type: 'sine',
                        vol: 0.1
                    }
                },
                init() {
                    if (this.ctx) return;
                    try {
                        this.ctx = new(window.AudioContext || window.webkitAudioContext)();
                        const resume = () => {
                            if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
                        };
                        ['click', 'keydown', 'touchstart'].forEach(e => document.addEventListener(e, resume, {
                            once: true
                        }));
                        console.log('🔈 Audio System initialized (muted: ' + this.muted + ')');
                    } catch (e) {
                        console.warn('AudioContext not available');
                    }
                },
                toggleMute() {
                    this.muted = !this.muted;
                    localStorage.setItem('pos_sound_muted', this.muted.toString());
                    console.log('🔊 Sound ' + (this.muted ? 'muted' : 'unmuted'));
                    return this.muted;
                },
                play(type) {
                    // Si está muteado, no reproducir
                    if (this.muted) return;

                    const startTime = performance.now();
                    if (!this.ctx) this.init();

                    try {
                        if (this.ctx && this.ctx.state === 'suspended') {
                            this.ctx.resume();
                        }

                        const sound = this.sounds[type] || this.sounds.info;
                        const ctx = this.ctx;
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();

                        osc.connect(gain);
                        gain.connect(ctx.destination);

                        osc.type = sound.type;
                        osc.frequency.setValueAtTime(sound.freq, ctx.currentTime);

                        gain.gain.setValueAtTime(sound.vol, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + sound.duration);

                        osc.start(ctx.currentTime);
                        osc.stop(ctx.currentTime + sound.duration);

                        const endTime = performance.now();
                        console.log(`🎵 Sound [${type}] triggered in ${(endTime - startTime).toFixed(2)}ms`);
                    } catch (e) {
                        console.error('❌ Audio Error:', e);
                    }
                }
            };

            function posApp() {
                return {
                    // Estado
                    isDarkMode: localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches),
                    soundMuted: localStorage.getItem('pos_sound_muted') === 'true',
                    searchQuery: '',
                    filterCategory: null,
                    productos: [],
                    categories: [],
                    loading: false,
                    processing: false,
                    // Balanza
                    balanzaData: null,
                    balanzaLoading: false,

                    // ===== SISTEMA DE TICKETS MÚLTIPLES =====
                    tickets: [{
                        id: 1,
                        cart: [],
                        selectedCliente: null,
                        clienteSearch: '',
                        formaPago: 'contado'
                    }],
                    activeTicket: 0,
                    nextTicketId: 2,

                    // Getter ticket activo
                    get currentTicket() {
                        return this.tickets[this.activeTicket] || this.tickets[0];
                    },
                    get cart() {
                        return this.currentTicket?.cart || [];
                    },
                    set cart(value) {
                        if (this.currentTicket) this.currentTicket.cart = value;
                    },
                    get selectedCliente() {
                        return this.currentTicket?.selectedCliente || null;
                    },
                    set selectedCliente(value) {
                        if (this.currentTicket) this.currentTicket.selectedCliente = value;
                    },
                    get clienteSearch() {
                        return this.currentTicket?.clienteSearch || '';
                    },
                    set clienteSearch(value) {
                        if (this.currentTicket) this.currentTicket.clienteSearch = value;
                    },
                    get formaPago() {
                        return this.currentTicket?.formaPago || 'contado';
                    },
                    set formaPago(value) {
                        if (this.currentTicket) this.currentTicket.formaPago = value;
                    },

                    // Autocompletado y Vistas
                    // showSuggestions: false, // Deprecado
                    viewMode: localStorage.getItem('pos_view_mode') || 'grid', // 'grid' | 'list'
                    selectedIndex: 0,

                    // Cliente search results (compartido)
                    clientesResults: [],
                    showClienteDropdown: false,
                    clienteSelectedIdx: 0,
                    loadingClientes: false,

                    // UI
                    currentTime: '',
                    showSuccessModal: false,
                    lastVenta: null,

                    // Simple Change Modal
                    showSimpleChangeModal: false,
                    simpleCashReceived: 0,

                    // ===== SISTEMA DE NOTIFICACIONES =====
                    toasts: [],
                    toastId: 0,
                    confirmModal: {
                        show: false,
                        title: '',
                        message: '',
                        icon: '❓',
                        type: 'info',
                        confirmText: 'Aceptar',
                        onConfirm: null,
                        onCancel: null
                    },

                    // Mostrar toast
                    toast(message, type = 'info', duration = 3000) {
                        const id = ++this.toastId;
                        const toast = {
                            id,
                            message,
                            type,
                            show: true
                        };
                        this.toasts.push(toast);
                        this.playSound(type); // Reproducir sonido
                        if (duration > 0) {
                            setTimeout(() => this.removeToast(id), duration);
                        }
                        return id;
                    },

                    removeToast(id) {
                        const index = this.toasts.findIndex(t => t.id === id);
                        if (index > -1) {
                            this.toasts[index].show = false;
                            setTimeout(() => {
                                this.toasts = this.toasts.filter(t => t.id !== id);
                            }, 300);
                        }
                    },

                    // Toggle sonido del POS
                    toggleSound() {
                        this.soundMuted = POSAudio.toggleMute();
                        // Mostrar feedback visual (sin sonido si está muteado)
                        this.toast(this.soundMuted ? 'Sonido desactivado' : 'Sonido activado', 'info');
                    },

                    // Mostrar confirm modal (retorna Promise)
                    showConfirm(options) {
                        return new Promise((resolve) => {
                            this.confirmModal = {
                                show: true,
                                title: options.title || 'Confirmar',
                                message: options.message || '¿Está seguro?',
                                icon: options.icon || '❓',
                                type: options.type || 'info',
                                confirmText: options.confirmText || 'Aceptar',
                                onConfirm: () => resolve(true),
                                onCancel: () => resolve(false)
                            };
                            // Sonido al mostrar confirmación
                            this.playSound('warning');
                        });
                    },

                    acceptConfirm() {
                        this.confirmModal.show = false;
                        this.playSound('success');
                        if (this.confirmModal.onConfirm) this.confirmModal.onConfirm();
                    },

                    cancelConfirm() {
                        this.confirmModal.show = false;
                        if (this.confirmModal.onCancel) this.confirmModal.onCancel();
                    },

                    // ===== MODAL DE CONTRASEÑA ADMIN =====
                    passwordModal: {
                        show: false,
                        title: 'Autorización Requerida',
                        message: '',
                        password: '',
                        error: '',
                        verifying: false,
                        onSuccess: null,
                        onCancel: null,
                        actionData: null
                    },

                    showPasswordPrompt(options) {
                        return new Promise((resolve) => {
                            this.passwordModal = {
                                show: true,
                                title: options.title || 'Autorización Requerida',
                                message: options.message || 'Ingrese la contraseña del administrador',
                                password: '',
                                error: '',
                                verifying: false,
                                onSuccess: () => resolve(true),
                                onCancel: () => resolve(false),
                                actionData: options.data || null
                            };
                            this.playSound('warning');
                            this.$nextTick(() => {
                                this.$refs.adminPasswordInput?.focus();
                            });
                        });
                    },

                    async verifyAdminPassword() {
                        if (!this.passwordModal.password) {
                            this.passwordModal.error = 'Ingrese la contraseña';
                            this.playSound('error');
                            return;
                        }

                        this.passwordModal.verifying = true;
                        this.passwordModal.error = '';

                        try {
                            const res = await fetch('api/verificar_admin.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    password: this.passwordModal.password,
                                    id_empresa: this.idEmpresa
                                })
                            });

                            const data = await res.json();

                            if (data.success) {
                                this.passwordModal.show = false;
                                this.playSound('success');
                                if (this.passwordModal.onSuccess) this.passwordModal.onSuccess();
                            } else {
                                this.passwordModal.error = data.message || 'Contraseña incorrecta';
                                this.passwordModal.password = '';
                                this.playSound('error');
                                this.$refs.adminPasswordInput?.focus();
                            }
                        } catch (e) {
                            this.passwordModal.error = 'Error de conexión';
                            this.playSound('error');
                        }

                        this.passwordModal.verifying = false;
                    },

                    cancelPassword() {
                        this.passwordModal.show = false;
                        if (this.passwordModal.onCancel) this.passwordModal.onCancel();
                    },

                    // ===== SISTEMA DE SONIDOS =====
                    initAudio() {
                        POSAudio.init();
                    },

                    playSound(type = 'info') {
                        POSAudio.play(type);
                    },

                    focusPrimaryInput() {
                        this.$nextTick(() => {
                            if (!this.currentTicket?.selectedCliente) {
                                this.$refs.clienteInput?.focus();
                            } else {
                                this.$refs.searchInput?.focus();
                            }
                        });
                    },

                    // Modal Detalle Producto
                    showProductDetailModal: false,
                    productDetail: null,
                    loadingDetail: false,
                    currentProductId: null,

                    // Config
                    idEmpresa: localStorage.getItem('id_empresa') || <?php echo $id_empresa; ?>,
                    idUsuario: localStorage.getItem('id_login') || <?php echo $id_login; ?>,
                    idCaja: <?php echo $id_caja; ?>,
                    userConfig: {
                        cobro_df: <?php echo $cobro_df; ?>,
                        ancho_papel: <?php echo $ancho_papel; ?>,
                        forma_pago_def: <?php echo $forma_pago_def; ?>
                    },
                    searchEstado: 1, // 1: Activos, 0: Descontinuados
                    selectedPriceType: 1, // Tipo de precio seleccionado (por defecto 1)

                    // Modales de Venta
                    showTicketModal: false,
                    ticketUrl: '',
                    loadingTicket: false,
                    loadingSifen: false,
                    showEmailModal: false,
                    showWhatsAppModal: false,
                    emailInput: '',
                    phoneInput: '',
                    sendingEmail: false,
                    paymentsList: [],
                    showPreviewModal: false,

                    // Modo Edición de Venta
                    showEditSearchModal: false,
                    editSearchQuery: '',
                    editSearchResults: [],
                    loadingEditSearch: false,
                    editingVenta: null, // { id_factura, nro_factura, ... } cuando estamos editando


                    // Productos Populares
                    popularProducts: [],
                    loadingPopular: false,

                    // Computed
                    get subtotal() {
                        return this.cart.reduce((sum, item) => sum + (item.precio * item.cantidad), 0);
                    },
                    get iva() {
                        return Math.round(this.subtotal / 11);
                    },
                    get total() {
                        return this.subtotal;
                    },
                    get totalPaid() {
                        return this.paymentsList.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
                    },
                    get remainingAmount() {
                        return this.total - this.totalPaid;
                    },

                    // ===== FUNCIONES DE TICKETS =====
                    addTicket() {
                        if (this.tickets.length >= 8) {
                            this.toast('Máximo 8 tickets simultáneos', 'warning');
                            return;
                        }
                        const newTicket = {
                            id: this.nextTicketId++,
                            cart: [],
                            selectedCliente: null,
                            clienteSearch: '',
                            formaPago: 'contado'
                        };
                        this.tickets.push(newTicket);
                        this.activeTicket = this.tickets.length - 1;
                        this.focusPrimaryInput();
                    },

                    async removeTicket(index) {
                        if (this.tickets.length === 1) {
                            // No eliminar el último, solo limpiar
                            this.tickets[0] = {
                                id: this.nextTicketId++,
                                cart: [],
                                selectedCliente: null,
                                clienteSearch: '',
                                formaPago: 'contado'
                            };
                            this.activeTicket = 0;
                            return;
                        }

                        const ticket = this.tickets[index];
                        if (ticket.cart.length > 0) {
                            const confirmed = await this.showConfirm({
                                title: 'Cerrar Ticket',
                                message: `¿Cerrar Ticket #${ticket.id}?\nTiene ${ticket.cart.length} producto(s) en el carrito.`,
                                icon: '🎫',
                                type: 'danger',
                                confirmText: 'Cerrar'
                            });
                            if (!confirmed) return;
                        }

                        this.tickets.splice(index, 1);
                        if (this.activeTicket >= this.tickets.length) {
                            this.activeTicket = this.tickets.length - 1;
                        }
                    },

                    switchTicket(index) {
                        if (index >= 0 && index < this.tickets.length) {
                            this.activeTicket = index;
                            this.saveTickets();
                            this.focusPrimaryInput();
                        }
                    },

                    // ===== PERSISTENCIA EN LOCALSTORAGE =====
                    saveTickets() {
                        const start = performance.now();
                        try {
                            const data = {
                                tickets: this.tickets,
                                activeTicket: this.activeTicket,
                                nextTicketId: this.nextTicketId,
                                timestamp: Date.now()
                            };
                            const json = JSON.stringify(data);
                            localStorage.setItem('pos_tickets_' + this.idEmpresa, json);
                            const end = performance.now();
                            if (end - start > 100) {
                                console.warn(`⚠️ saveTickets() was slow: ${(end - start).toFixed(2)}ms`);
                            }
                        } catch (e) {
                            console.warn('Error saving tickets to localStorage:', e);
                        }
                    },

                    loadTickets() {
                        try {
                            const saved = localStorage.getItem('pos_tickets_' + this.idEmpresa);
                            if (saved) {
                                const data = JSON.parse(saved);
                                // Solo cargar si los datos son recientes (menos de 24 horas)
                                if (data.timestamp && (Date.now() - data.timestamp) < 86400000) {
                                    if (data.tickets && data.tickets.length > 0) {
                                        this.tickets = data.tickets;
                                        this.activeTicket = data.activeTicket || 0;
                                        this.nextTicketId = data.nextTicketId || (this.tickets.length + 1);
                                        console.log('✅ Tickets recuperados:', this.tickets.length);
                                        return true;
                                    }
                                }
                            }
                        } catch (e) {
                            console.warn('Error loading tickets from localStorage:', e);
                        }
                        return false;
                    },

                    clearSavedTickets() {
                        localStorage.removeItem('pos_tickets_' + this.idEmpresa);
                    },

                    // Init
                    init() {
                        // Cargar IDs en localStorage para persistencia en APIs
                        localStorage.setItem('id_empresa', this.idEmpresa);
                        localStorage.setItem('id_login', this.idUsuario);

                        // Cargar productos populares
                        this.loadPopularProducts();

                        // Cargar tickets guardados
                        this.loadTickets();

                        this.updateTime();
                        setInterval(() => this.updateTime(), 1000);
                        this.loadCategories();
                        this.focusPrimaryInput();

                        // Pre-inicializar AudioContext para sonidos inmediatos
                        this.initAudio();

                        // Auto-guardar tickets de forma eficiente (cada 5 segundos si hay cambios)
                        setInterval(() => this.saveTickets(), 5000);

                        // Guardar antes de cerrar la página
                        window.addEventListener('beforeunload', () => this.saveTickets());

                        // Verificar parámetros de URL para edición directa
                        const urlParams = new URLSearchParams(window.location.search);
                        const editId = urlParams.get('id_venta') || urlParams.get('edit_id');
                        if (editId) {
                            console.log('🔗 Modo edición activado por URL:', editId);
                            this.$nextTick(() => {
                                // Pequeño delay para asegurar que los componentes estén listos
                                setTimeout(() => this.loadVentaForEdit(editId), 500);
                            });
                        }

                        // Atajos de teclado
                        document.addEventListener('keydown', (e) => {
                            if (e.key === 'F2') {
                                e.preventDefault();
                                this.$refs.searchInput.focus();
                            }
                            if (e.key === 'F5' && this.cart.length > 0) {
                                e.preventDefault();
                                this.procesarVenta();
                            }
                            if (e.key === 'Escape') {
                                this.showSuccessModal = false;
                            }

                            // Atajos para tickets múltiples
                            if (e.ctrlKey && e.key === 't') {
                                e.preventDefault();
                                this.addTicket();
                            }
                            if (e.ctrlKey && e.key >= '1' && e.key <= '8') {
                                e.preventDefault();
                                const idx = parseInt(e.key) - 1;
                                if (idx < this.tickets.length) {
                                    this.switchTicket(idx);
                                }
                            }
                            if (e.ctrlKey && e.key === 'ArrowLeft') {
                                e.preventDefault();
                                if (this.activeTicket > 0) this.switchTicket(this.activeTicket - 1);
                            }
                            if (e.ctrlKey && e.key === 'ArrowRight') {
                                e.preventDefault();
                                if (this.activeTicket < this.tickets.length - 1) this.switchTicket(this.activeTicket + 1);
                            }
                        });
                    },

                    updateTime() {
                        this.currentTime = new Date().toLocaleString('es-PY', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    },

                    formatMoney(amount) {
                        return Number(amount || 0).toLocaleString('es-PY');
                    },

                    formatNum(n, minDec = 0, maxDec = 2) {
                        if (n === null || n === undefined || isNaN(n)) return '';
                        return Number(n).toLocaleString('es-PY', {
                            minimumFractionDigits: minDec,
                            maximumFractionDigits: maxDec
                        });
                    },

                    parseNum(s) {
                        if (s === null || s === undefined || s === '') return 0;
                        // Eliminar separadores de miles (puntos) y cambiar coma decimal por punto
                        let clean = s.toString().replace(/\./g, '').replace(',', '.');
                        return parseFloat(clean) || 0;
                    },

                    // Productos
                    async loadCategories() {
                        try {
                            const res = await fetch(`api/productos.php?action=categories&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();
                            this.categories = data.categories || [];
                        } catch (e) {
                            console.error('Error loading categories:', e);
                        }
                    },

                    async loadPopularProducts() {
                        this.loadingPopular = true;
                        try {
                            const res = await fetch(`api/productos.php?action=popular&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}`);
                            const data = await res.json();
                            this.popularProducts = data.productos || [];
                        } catch (e) {
                            console.error('Error loading popular products:', e);
                        } finally {
                            this.loadingPopular = false;
                        }
                    },

                    // Cuando cambia el tipo de precio
                    onPriceTypeChange() {
                        // Actualizar productos populares con el nuevo precio
                        this.loadPopularProducts();
                        // Si hay resultados de búsqueda, actualizarlos también
                        if (this.productos.length > 0) {
                            this.searchProducts();
                        }
                    },

                    // ===== BALANZA ELECTRÓNICA =====
                    async detectarBalanza(codigo) {
                        // Solo intentar si parece un código numérico de balanza
                        if (!codigo || !/^[0-9]{6,}$/.test(codigo)) {
                            return false;
                        }
                        this.balanzaLoading = true;
                        try {
                            const url = `../interpretar_codigo/interpretar_codigo.php?codigo=${encodeURIComponent(codigo)}&id_empresa=${this.idEmpresa}`;
                            const res = await fetch(url);
                            const data = await res.json();
                            if (data && data.es_balanza === true) {
                                await this.mostrarBalanzaUI(data);
                                return true;
                            }
                        } catch (e) {
                            console.error('Error detectando balanza', e);
                        } finally {
                            this.balanzaLoading = false;
                        }
                        return false;
                    },

                    async mostrarBalanzaUI(datos) {
                        let enriched = {
                            ...(datos || {})
                        };
                        const prod = await this.resolverProductoBalanza(enriched);
                        if (prod) {
                            enriched.descripcion = prod.descripcion || enriched.descripcion;
                            enriched.codigo = prod.codigo || enriched.codigo || prod.barcode;
                            enriched.idproducto = prod.id || enriched.idproducto;
                            // Prellenar buscador con código interno para visibilidad/acción rápida
                            if (prod.codigo) {
                                this.searchQuery = prod.codigo;
                            } else if (prod.id) {
                                this.searchQuery = String(prod.id);
                            }
                        }
                        if (!enriched.descripcion) {
                            enriched.descripcion = enriched.codigo || enriched.raw_producto || 'N/D';
                        }
                        this.balanzaData = enriched;
                        this.productos = [];
                        this.playSound('info');
                        this.$nextTick(() => this.$refs.searchInput?.focus());
                    },

                    cancelarBalanza() {
                        this.balanzaData = null;
                        this.$refs.searchInput?.focus();
                    },

                    async confirmarBalanza() {
                        if (!this.balanzaData) return;
                        await this.procesarBalanza(this.balanzaData);
                        this.balanzaData = null;
                        this.searchQuery = '';
                        this.$nextTick(() => this.$refs.searchInput?.focus());
                    },

                    async obtenerProductoPorId(idProd) {
                        try {
                            const params = new URLSearchParams({
                                action: 'by_id',
                                id: idProd,
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            return data.producto || null;
                        } catch (e) {
                            console.error('Error obteniendo producto por ID', e);
                            return null;
                        }
                    },

                    async obtenerProductoPorCodigo(code) {
                        try {
                            const params = new URLSearchParams({
                                action: 'search',
                                q: code,
                                id_empresa: this.idEmpresa,
                                estado: this.searchEstado
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            const lista = data.productos || [];
                            if (!lista.length) return null;
                            // Coincidencia exacta por código
                            const exact = lista.find(p => String(p.codigo) === String(code));
                            return exact || lista[0];
                        } catch (e) {
                            console.error('Error obteniendo producto por código', e);
                            return null;
                        }
                    },

                    async obtenerProductoPorBarcode(code) {
                        try {
                            const params = new URLSearchParams({
                                action: 'barcode',
                                cod: code,
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            return data.producto || null;
                        } catch (e) {
                            console.error('Error obteniendo producto por barcode', e);
                            return null;
                        }
                    },

                    async obtenerProductoPorBarcode(code) {
                        try {
                            const params = new URLSearchParams({
                                action: 'barcode',
                                cod: code,
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            return data.producto || null;
                        } catch (e) {
                            console.error('Error obteniendo producto por barcode', e);
                            return null;
                        }
                    },

                    extraerArticuloDeCodigoBalanza(code) {
                        // Formato típico: PP AAAAA VVVVV C (prefijo, 5 dig producto, 5 dig valor, check)
                        const clean = String(code || '').replace(/\D+/g, '');
                        if (clean.length >= 7) {
                            // Tomar 5 dígitos después de los 2 de prefijo
                            const articulo = clean.slice(2, 7);
                            return articulo.replace(/^0+/, '') || articulo;
                        }
                        return null;
                    },

                    async resolverProductoBalanza(enriched) {
                        // 1) Extraer artículo desde el código de balanza (prioritario para cve_producto interno)
                        const articulo = this.extraerArticuloDeCodigoBalanza(enriched.codigo || enriched.raw_producto);
                        if (articulo) {
                            const prodArticulo = await this.obtenerProductoPorCodigo(articulo);
                            if (prodArticulo) return prodArticulo;
                            // probar como id numérico
                            const prodArticuloId = await this.obtenerProductoPorId(articulo);
                            if (prodArticuloId) return prodArticuloId;
                        }
                        // 2) Por raw_producto (podría venir ya como código interno)
                        if (enriched.raw_producto) {
                            const prodCode = await this.obtenerProductoPorCodigo(enriched.raw_producto);
                            if (prodCode) return prodCode;
                            // También intentar con raw_producto sin ceros
                            const trimmed = String(enriched.raw_producto).replace(/^0+/, '');
                            if (trimmed && trimmed !== enriched.raw_producto) {
                                const prodCode2 = await this.obtenerProductoPorCodigo(trimmed);
                                if (prodCode2) return prodCode2;
                            }
                        }
                        // 3) Por idproducto explícito
                        if (enriched.idproducto) {
                            const prod = await this.obtenerProductoPorId(enriched.idproducto);
                            if (prod) return prod;
                        }
                        // 4) Por barcode completo
                        if (enriched.codigo) {
                            const prodBarcode = await this.obtenerProductoPorBarcode(enriched.codigo);
                            if (prodBarcode) return prodBarcode;
                        }
                        return null;
                    },

                    async procesarBalanza(datos) {
                        const idProd = datos.idproducto;
                        const modo = datos.modo;
                        const valor = parseFloat(datos.valor);

                        if (!idProd || !modo || Number.isNaN(valor)) {
                            this.toast('Lectura de balanza incompleta', 'error');
                            return;
                        }

                        const producto = await this.obtenerProductoPorId(idProd);
                        if (!producto) {
                            this.toast('Producto de balanza no encontrado', 'error');
                            return;
                        }

                        // Agregar como línea independiente (no fusionar con otros registros del mismo producto)
                        const nuevoItem = {
                            id: producto.id,
                            codigo: producto.codigo,
                            descripcion: producto.descripcion,
                            precio: modo === 'PRECIO' ? Math.max(valor, 0) : parseFloat(producto.precio || 0),
                            cantidad: modo === 'PESO' ? Math.max(valor, 0.001) : 1,
                            tasa_iva: producto.tasa_iva || 10,
                            editablePrecio: parseInt(producto.edita_precio) === 1,
                            editableDescripcion: parseInt(producto.editable) === 1
                        };
                        this.cart = [...this.cart, nuevoItem];
                        this.toast('Producto agregado desde balanza', 'success');
                    },

                    // Parsear búsqueda inteligente (ej: "zap rojo <50000")
                    parseSmartSearch(query) {
                        let priceFilter = null;
                        let priceOp = null;
                        let searchTerms = query;

                        // Detectar filtros de precio: <50000, >10000, =40000
                        const priceMatch = query.match(/([<>=])\s*(\d+)/);
                        if (priceMatch) {
                            priceOp = priceMatch[1];
                            priceFilter = parseInt(priceMatch[2]);
                            searchTerms = query.replace(/[<>=]\s*\d+/, '').trim();
                        }

                        return {
                            searchTerms,
                            priceOp,
                            priceFilter
                        };
                    },

                    async searchProducts() {
                        if (!this.searchQuery && !this.filterCategory) {
                            this.productos = [];
                            return;
                        }

                        this.loading = true;
                        this.selectedIndex = 0;

                        try {
                            // Parsear búsqueda inteligente
                            const {
                                searchTerms,
                                priceOp,
                                priceFilter
                            } = this.parseSmartSearch(this.searchQuery || '');

                            const params = new URLSearchParams({
                                action: 'search',
                                q: searchTerms,
                                category: this.filterCategory || '',
                                id_empresa: this.idEmpresa,
                                estado: this.searchEstado,
                                tipo_precio: this.selectedPriceType
                            });

                            // Agregar filtros de precio
                            if (priceOp && priceFilter) {
                                params.append('price_op', priceOp);
                                params.append('price_val', priceFilter);
                            }

                            const res = await fetch(`api/productos.php?${params}`);
                            const data = await res.json();
                            this.productos = data.productos || [];
                        } catch (e) {
                            console.error('Error searching products:', e);
                        }
                        this.loading = false;
                    },

                    async handleSearchEnter() {
                        let query = this.searchQuery?.trim();
                        if (!query) return;

                        // Atajo de cambio de precio rápido (ej: .50000)
                        if (query.startsWith('.') && query.length > 1) {
                            const newPriceStr = query.substring(1);
                            const newPrice = this.parseNum(newPriceStr);
                            if (!isNaN(newPrice)) {
                                if (this.cart.length === 0) {
                                    this.toast('No hay productos en el carrito', 'warning');
                                    this.searchQuery = '';
                                    return;
                                }
                                const lastItem = this.cart[this.cart.length - 1];
                                const minPrice = parseFloat(lastItem.precio_min || 0);

                                if (newPrice >= minPrice) {
                                    lastItem.precio = newPrice;
                                    this.searchQuery = '';
                                    this.toast(`Precio de "${lastItem.descripcion}" actualizado a ${this.formatMoney(newPrice)}`, 'success');
                                    this.playSound('success');
                                    this.recalculate();
                                    return;
                                } else {
                                    this.toast(`Monto no autorizado. El precio mínimo es ${this.formatMoney(minPrice)}`, 'error');
                                    this.playSound('error');
                                    return;
                                }
                            }
                        }

                        // Atajo de cambio de cantidad (ej: +5)
                        if (query.startsWith('+') && query.length > 1) {
                            const newQtyStr = query.substring(1);
                            const newQty = this.parseNum(newQtyStr);
                            if (!isNaN(newQty)) {
                                if (this.cart.length === 0) {
                                    this.toast('No hay productos en el carrito', 'warning');
                                    this.searchQuery = '';
                                    return;
                                }
                                const lastItem = this.cart[this.cart.length - 1];

                                // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                                if (parseInt(lastItem.controla_stock) !== -1 && parseInt(lastItem.vende_sin_stock) === 0) {
                                    const available = parseFloat(lastItem.stock_inicial || 0);
                                    if (newQty > available) {
                                        this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                        this.playSound('error');
                                        return;
                                    }
                                }

                                lastItem.cantidad = newQty;
                                this.searchQuery = '';
                                this.toast(`Cantidad de "${lastItem.descripcion}" actualizada a ${newQty}`, 'success');
                                this.playSound('success');
                                this.recalculate();
                                return;
                            }
                        }

                        // Atajo de cambio de subtotal rápido (ej: *40000) - AJUSTANDO CANTIDAD
                        if (query.startsWith('*') && query.length > 1) {
                            const targetTotalStr = query.substring(1);
                            const targetTotal = this.parseNum(targetTotalStr);
                            if (!isNaN(targetTotal)) {
                                if (this.cart.length === 0) {
                                    this.toast('No hay productos en el carrito', 'warning');
                                    this.searchQuery = '';
                                    return;
                                }
                                const lastItem = this.cart[this.cart.length - 1];
                                const unitPrice = parseFloat(lastItem.precio || 0);

                                if (unitPrice <= 0) {
                                    this.toast('El precio unitario debe ser mayor a cero para calcular cantidad', 'error');
                                    this.playSound('error');
                                    return;
                                }

                                const newQty = targetTotal / unitPrice;

                                // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                                if (parseInt(lastItem.controla_stock) !== -1 && parseInt(lastItem.vende_sin_stock) === 0) {
                                    const available = parseFloat(lastItem.stock_inicial || 0);
                                    if (newQty > available) {
                                        this.toast(`Stock insuficiente para alcanzar ese total. Disponible: ${available}`, 'error');
                                        this.playSound('error');
                                        return;
                                    }
                                }

                                lastItem.cantidad = newQty;
                                this.searchQuery = '';
                                this.toast(`Cantidad de "${lastItem.descripcion}" ajustada a ${newQty.toFixed(3)} para totalizar ${this.formatMoney(targetTotal)}`, 'success');
                                this.playSound('success');
                                this.recalculate();
                                return;
                            }
                        }

                        // Si hay lectura de balanza pendiente, usarla directamente con Enter
                        if (this.balanzaData) {
                            await this.confirmarBalanza();
                            return;
                        }
                        const queryBalanza = this.searchQuery?.trim();
                        if (queryBalanza) {
                            const fueBalanza = await this.detectarBalanza(queryBalanza);
                            if (fueBalanza) return;
                        }

                        // Si hay resultados visibles y uno seleccionado, añadir al carrito
                        if (this.productos.length > 0) {
                            this.addToCart(this.productos[this.selectedIndex]);
                            this.searchQuery = '';
                            this.productos = [];
                            this.playSound('success');
                            return;
                        }

                        query = this.searchQuery?.trim();
                        if (!query) return;

                        // 1. Ver si hay una coincidencia exacta en las sugerencias actuales
                        let found = null;

                        // Si el usuario navegó con flechas, usar ese
                        if (this.selectedIndex >= 0 && this.selectedIndex < this.productos.length) {
                            found = this.productos[this.selectedIndex];
                        } else {
                            // Buscar coincidencia exacta por código o descripción en lo que ya cargó
                            found = this.productos.find(p => p.codigo === query || p.descripcion.toLowerCase() === query.toLowerCase());
                        }

                        if (found) {
                            this.addToCart(found);
                            this.showSuggestions = false;
                            this.playSound('success');
                            return;
                        }

                        // 2. Si no hay nada en sugerencias, forzar búsqueda rápida en servidor
                        this.loading = true;
                        try {
                            const params = new URLSearchParams({
                                action: 'search',
                                q: query,
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/productos.php?${params}`);
                            const data = await res.json();
                            const results = data.productos || [];

                            const exact = results.find(p => p.codigo === query);
                            if (exact) {
                                this.addToCart(exact);
                                this.showSuggestions = false;
                                this.playSound('success');
                            } else if (results.length === 1) {
                                this.addToCart(results[0]);
                                this.showSuggestions = false;
                                this.playSound('success');
                            } else {
                                this.toast('El producto no existe o no fue encontrado', 'error');
                                this.playSound('error');
                            }
                        } catch (e) {
                            this.toast('Error al buscar producto', 'error');
                            this.playSound('error');
                        } finally {
                            this.loading = false;
                        }
                    },

                    // Navegación de sugerencias
                    navigateSuggestion(delta) {
                        if (this.productos.length === 0) return;
                        this.selectedIndex = (this.selectedIndex + delta + this.productos.length) % this.productos.length;
                    },

                    // Navegación en Grid/List
                    navigateGrid(direction) {
                        if (this.productos.length === 0) return;

                        const cols = this.viewMode === 'grid' ? 3 : 1;

                        if (direction === 1) { // Abajo
                            this.selectedIndex = Math.min(this.selectedIndex + cols, this.productos.length - 1);
                        } else if (direction === -1) { // Arriba
                            this.selectedIndex = Math.max(this.selectedIndex - cols, 0);
                        } else if (direction === 'right') {
                            this.selectedIndex = Math.min(this.selectedIndex + 1, this.productos.length - 1);
                        } else if (direction === 'left') {
                            this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
                        }

                        this.scrollToSelected();
                    },

                    scrollToSelected() {
                        const container = document.querySelector('.custom-scroll');
                        const selectedEl = document.querySelectorAll('.producto-card, .group')[this.selectedIndex];

                        if (container && selectedEl) {
                            const containerRect = container.getBoundingClientRect();
                            const elRect = selectedEl.getBoundingClientRect();

                            if (elRect.bottom > containerRect.bottom) {
                                selectedEl.scrollIntoView({
                                    block: 'nearest',
                                    behavior: 'smooth'
                                });
                            } else if (elRect.top < containerRect.top) {
                                selectedEl.scrollIntoView({
                                    block: 'nearest',
                                    behavior: 'smooth'
                                });
                            }
                        }
                    },

                    setViewModeDefault(mode) {
                        this.viewMode = mode;
                        localStorage.setItem('pos_view_mode', mode);
                        this.playSound('success');
                        this.toast(`Vista ${mode === 'grid' ? 'Cuadrícula' : 'Lista'} fijada por defecto`, 'success');
                    },

                    // Deprecado: Navegación sugerencias
                    selectSuggestion(index) {
                        if (this.productos.length > 0 && index >= 0 && index < this.productos.length) {
                            this.addToCart(this.productos[index]);
                            this.showSuggestions = false;
                        }
                    },

                    // Checkout Modal
                    showCheckoutModal: false,
                    isFacturaElectronica: <?php echo $isFacturaElectronica; ?>,
                    selectedDocType: '<?php echo $isFacturaElectronica ? "electro" : "auto"; ?>',
                    selectedPaymentMethod: 'efectivo',
                    voucherNumber: '',
                    showCardModal: false,
                    showTransferModal: false,
                    transferReference: '',
                    showQrModal: false,
                    qrTransactionCode: '',
                    showCreditModal: false,
                    creditInstallments: 1,
                    creditDueDate: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0], // 30 días
                    creditNotes: '',
                    showUenoModal: false,
                    processingSale: false,
                    processingUeno: false,

                    // Predeterminados
                    defaultDocType: localStorage.getItem('pos_default_doc') || 'comun',
                    defaultPaymentMethod: localStorage.getItem('pos_default_pm') || 'efectivo',

                    setDefault(type, id) {
                        if (type === 'doc') {
                            this.defaultDocType = id;
                            localStorage.setItem('pos_default_doc', id);
                            this.toast(`Documento "${id}" fijado como predeterminado`, 'success');
                        } else {
                            this.defaultPaymentMethod = id;
                            localStorage.setItem('pos_default_pm', id);
                            this.toast(`Medio de pago "${id}" fijado como predeterminado`, 'success');
                        }
                        this.playSound('success');
                    },

                    get docTypes() {
                        const types = [];
                        if (this.isFacturaElectronica) {
                            types.push({
                                id: 'electro',
                                name: 'Factura Electrónica',
                                icon: '⚡'
                            });
                        } else {
                            types.push({
                                id: 'auto',
                                name: 'Factura Autoimpreso',
                                icon: '📝'
                            });
                        }
                        types.push({
                            id: 'comun',
                            name: 'Nota Común',
                            icon: '📄'
                        });
                        return types;
                    },

                    focusedCheckoutSection: 'paymentMethods',
                    focusedCheckoutIdx: 0,

                    navigateCheckout(key) {
                        const pms = this.paymentMethods;
                        const dts = this.docTypes;

                        if (this.focusedCheckoutSection === 'paymentMethods') {
                            const cols = window.innerWidth >= 768 ? 3 : 2;
                            if (key === 'ArrowLeft' && this.focusedCheckoutIdx > 0) this.focusedCheckoutIdx--;
                            if (key === 'ArrowRight' && this.focusedCheckoutIdx < pms.length - 1) this.focusedCheckoutIdx++;
                            if (key === 'ArrowUp') {
                                if (this.focusedCheckoutIdx < cols) {
                                    this.focusedCheckoutSection = 'docTypes';
                                    this.focusedCheckoutIdx = dts.length - 1;
                                } else {
                                    this.focusedCheckoutIdx -= cols;
                                }
                            }
                            if (key === 'ArrowDown') {
                                if (this.focusedCheckoutIdx + cols < pms.length) {
                                    this.focusedCheckoutIdx += cols;
                                }
                            }
                        } else {
                            // DocTypes Section
                            if (key === 'ArrowDown') {
                                if (this.focusedCheckoutIdx < dts.length - 1) {
                                    this.focusedCheckoutIdx++;
                                } else {
                                    this.focusedCheckoutSection = 'paymentMethods';
                                    this.focusedCheckoutIdx = 0;
                                }
                            }
                            if (key === 'ArrowUp') {
                                if (this.focusedCheckoutIdx > 0) {
                                    this.focusedCheckoutIdx--;
                                }
                            }
                            if (key === 'ArrowRight' || key === 'ArrowLeft') {
                                this.focusedCheckoutSection = 'paymentMethods';
                                this.focusedCheckoutIdx = 0;
                            }
                        }
                    },

                    selectFocusedItem() {
                        if (this.focusedCheckoutSection === 'docTypes') {
                            const dt = this.docTypes[this.focusedCheckoutIdx];
                            this.selectedDocType = dt.id;
                            this.playSound('info');
                        } else {
                            const pm = this.paymentMethods[this.focusedCheckoutIdx];
                            this.handlePaymentSelection(pm);
                        }
                    },

                    focusedCashIdx: -1, // -1 means input is focused

                    navigateCash(key) {
                        // Indices: 0-6 (bills), 7 (Limpiar), 8 (Exacto), 9 (Cerrar), 10 (Confirmar)
                        if (this.focusedCashIdx === -1) {
                            if (key === 'ArrowDown') this.focusedCashIdx = 0;
                            return;
                        }

                        if (this.focusedCashIdx >= 0 && this.focusedCashIdx <= 7) {
                            // Bills grid (4 columns)
                            if (key === 'ArrowLeft' && this.focusedCashIdx % 4 > 0) this.focusedCashIdx--;
                            if (key === 'ArrowRight' && this.focusedCashIdx % 4 < 3) this.focusedCashIdx++;
                            if (key === 'ArrowUp') {
                                if (this.focusedCashIdx < 4) {
                                    this.focusedCashIdx = -1;
                                    this.$nextTick(() => {
                                        this.$refs.cashInput.focus();
                                        this.$refs.cashInput.select();
                                    });
                                } else {
                                    this.focusedCashIdx -= 4;
                                }
                            }
                            if (key === 'ArrowDown') {
                                if (this.focusedCashIdx >= 4) {
                                    this.focusedCashIdx = 8;
                                } else {
                                    this.focusedCashIdx += 4;
                                }
                            }
                        } else if (this.focusedCashIdx === 8) {
                            // Monto Exacto
                            if (key === 'ArrowUp') this.focusedCashIdx = 4;
                            if (key === 'ArrowDown') this.focusedCashIdx = 10;
                        } else if (this.focusedCashIdx >= 9) {
                            // Footer
                            if (key === 'ArrowLeft') this.focusedCashIdx = 9;
                            if (key === 'ArrowRight') this.focusedCashIdx = 10;
                            if (key === 'ArrowUp') this.focusedCashIdx = 8;
                        }
                    },

                    selectFocusedCashItem() {
                        if (this.focusedCashIdx === -1) {
                            this.confirmCashAmount();
                            return;
                        }
                        if (this.focusedCashIdx <= 6) {
                            const vals = [2000, 5000, 10000, 20000, 50000, 100000, 200000];
                            this.cashAmountReceived = vals[this.focusedCashIdx];
                            this.playSound('success');
                        } else if (this.focusedCashIdx === 7) {
                            this.cashAmountReceived = 0;
                            this.playSound('warning');
                            this.focusedCashIdx = -1;
                            this.$nextTick(() => this.$refs.cashInput.focus());
                        } else if (this.focusedCashIdx === 8) {
                            this.cashAmountReceived = this.total;
                            this.playSound('success');
                        } else if (this.focusedCashIdx === 9) {
                            this.showCashModal = false;
                        } else if (this.focusedCashIdx === 10) {
                            this.confirmCashAmount();
                        }
                    },

                    paymentMethods: [{
                            id: 'efectivo',
                            name: 'Efectivo',
                            icon: '💵'
                        },
                        {
                            id: 'tarjeta',
                            name: 'Tarjeta',
                            icon: '💳'
                        },
                        {
                            id: 'transferencia',
                            name: 'Transfer.',
                            icon: '🏦'
                        },
                        // { id: 'qr', name: 'Pago QR / PIX', icon: '📱' },
                        {
                            id: 'credito',
                            name: 'Crédito',
                            icon: '📅'
                        }
                    ],

                    cashAmountReceived: 0,
                    showCashModal: false,
                    get cashChange() {
                        const change = this.cashAmountReceived - this.total;
                        return change > 0 ? change : 0;
                    },

                    openCashModal() {
                        this.showCashModal = true;
                        this.cashAmountReceived = this.remainingAmount; // Sugerir monto faltante
                        this.focusedCashIdx = -1; // Reset to input focus
                        this.playSound('info');
                        this.$nextTick(() => {
                            this.$refs.cashInput.focus();
                            this.$refs.cashInput.select();
                        });
                    },

                    confirmCashAmount() {
                        if (this.cashAmountReceived < this.remainingAmount) {
                            // Permitir pagos parciales en efectivo
                            const amtToAdd = this.cashAmountReceived;
                            this.paymentsList.push({
                                method: 'efectivo',
                                amount: amtToAdd,
                                name: 'Efectivo',
                                cash_received: amtToAdd,
                                cash_change: 0
                            });
                            this.showCashModal = false;
                            this.playSound('success');
                            return;
                        }

                        // Si el monto recibido es mayor o igual al faltante
                        const finalAmount = this.remainingAmount;
                        const change = this.cashAmountReceived - finalAmount;

                        this.paymentsList.push({
                            method: 'efectivo',
                            amount: finalAmount,
                            name: 'Efectivo',
                            cash_received: this.cashAmountReceived,
                            cash_change: change > 0 ? change : 0
                        });

                        this.showCashModal = false;
                        this.playSound('success');
                    },

                    openCardModal() {
                        console.log('Abriendo modal de tarjeta...');
                        this.toast('Cargando Boucher...', 'info');
                        this.showCardModal = true;
                        this.voucherNumber = '';
                        this.playSound('info');
                        this.$nextTick(() => {
                            this.$refs.voucherInput?.focus();
                        });
                    },

                    confirmCardPayment() {
                        const amt = this.remainingAmount;
                        this.paymentsList.push({
                            method: 'tarjeta',
                            amount: amt,
                            name: 'Tarjeta',
                            voucher_number: this.voucherNumber
                        });
                        this.showCardModal = false;
                        this.playSound('success');
                    },

                    openTransferModal() {
                        console.log('Abriendo modal de transferencia...');
                        this.toast('Pago por Transferencia...', 'info');
                        this.showTransferModal = true;
                        this.transferReference = '';
                        this.playSound('info');
                        this.$nextTick(() => {
                            this.$refs.transferInput?.focus();
                        });
                    },

                    selectUnifiedMethod(pm) {
                        if (pm.id === 'ueno') {
                            this.pagarConUeno();
                            return;
                        }
                        this.selectedPaymentMethod = pm.id;
                        // Auto-llenar con el monto faltante
                        this.cashAmountReceived = this.remainingAmount > 0 ? this.remainingAmount : 0;

                        // Reset fields
                        if (pm.id === 'tarjeta') this.voucherNumber = '';
                        if (pm.id === 'transferencia') this.transferReference = '';
                        if (pm.id === 'qr') this.qrTransactionCode = '';
                        if (pm.id === 'credito') {
                            this.creditInstallments = 1;
                            this.creditDueDate = new Date().toISOString().split('T')[0];
                            this.creditNotes = '';
                            if (!this.currentTicket.selectedCliente?.id) {
                                this.toast('Atención: Venta a CRÉDITO sin cliente seleccionado', 'warning');
                            }
                        }

                        this.playSound('info');
                        this.$nextTick(() => {
                            const input = this.$refs.unifiedAmountInput;
                            if (input) {
                                input.focus();
                                input.select(); // Seleccionar todo el texto para escribir encima
                            }
                        });
                    },

                    confirmUnifiedPayment() {
                        if (this.cashAmountReceived <= 0 && this.remainingAmount > 0) {
                            this.toast('Monto debe ser mayor a 0', 'warning');
                            return;
                        }

                        const methodId = this.selectedPaymentMethod;
                        const pm = this.paymentMethods.find(m => m.id === methodId);

                        const paymentObj = {
                            method: methodId,
                            amount: 0,
                            name: pm ? pm.name : methodId
                        };

                        if (methodId === 'efectivo') {
                            const finalAmount = Math.min(this.cashAmountReceived, this.remainingAmount);
                            const change = this.cashAmountReceived - finalAmount;
                            paymentObj.amount = finalAmount;
                            paymentObj.cash_received = this.cashAmountReceived;
                            paymentObj.cash_change = change > 0 ? change : 0;
                        } else if (methodId === 'tarjeta') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.voucher_number = this.voucherNumber;
                        } else if (methodId === 'transferencia') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.transfer_reference = this.transferReference;
                        } else if (methodId === 'qr') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.qr_transaction_code = this.qrTransactionCode;
                        } else if (methodId === 'credito') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.credit_installments = this.creditInstallments;
                            paymentObj.credit_due_date = this.creditDueDate;
                            paymentObj.credit_notes = this.creditNotes;
                        } else {
                            paymentObj.amount = this.cashAmountReceived;
                        }

                        this.paymentsList.push(paymentObj);
                        this.selectedPaymentMethod = null;
                        this.playSound('success');
                    },

                    confirmCreditPayment() {
                        // Mantenido por compatibilidad si se llama desde otro lado, 
                        // pero ahora se usa el flujo unificado.
                    },

                    async pagarConUeno() {
                        // Primero debemos guardar la venta como pendiente para tener un ID real
                        // Aunque venta.php ya guarda y procesa, para Ueno necesitamos el ID antes del webhook.
                        // En este sistema, confirmSale ya lo hace. Así que intentaremos un flujo asíncrono.

                        this.playSound('info');
                        this.toast('Iniciando pago con UENO...', 'info');
                        this.processingUeno = true;

                        try {
                            // 1. Crear la venta preliminar (o usar la actual si ya tiene ID)
                            // Para este flujo usaremos un endpoint que 'congelará' el carrito y devolverá el ID
                            const resVenta = await fetch('api/venta.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    items: this.cart,
                                    total: this.total,
                                    id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                    doc_type: this.selectedDocType,
                                    payment_method: 'ueno',
                                    id_empresa: this.idEmpresa,
                                    id_usuario: this.idUsuario,
                                    id_caja: this.idCaja,
                                    status: 'PENDIENTE' // Nuevo flag para venta.php
                                })
                            });

                            const ventaData = await resVenta.json();
                            if (!ventaData.success) throw new Exception(ventaData.message);

                            const idFactura = ventaData.data.id_factura;

                            // 2. Llamar a create-payment de Ueno
                            const resUeno = await fetch('api/ueno_create_payment.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    id_factura: idFactura,
                                    id_empresa: this.idEmpresa
                                })
                            });

                            const uenoData = await resUeno.json();
                            if (uenoData.success) {
                                this.toast('Redirigiendo a UENO...', 'success');
                                // Abrir Checkout en nueva ventana
                                const win = window.open(uenoData.checkout_url, '_blank');

                                // Mostrar modal de espera
                                this.showUenoModal = true;

                                // Opcional: Polling para detectar cuando se pague
                            } else {
                                this.toast(uenoData.message, 'error');
                            }

                        } catch (e) {
                            console.error('Ueno Error:', e);
                            this.toast('Error al procesar con UENO', 'error');
                        } finally {
                            this.processingUeno = false;
                        }
                    },

                    async openCheckout() {
                        if (this.cart.length === 0) {
                            this.toast('El carrito está vacío', 'error');
                            this.playSound('error');
                            return;
                        }
                        if (!this.currentTicket.selectedCliente) {
                            const confirmed = await this.showConfirm({
                                title: 'Cliente no seleccionado',
                                message: '¿Desea continuar la venta con un cliente "SIN NOMBRE"?',
                                icon: '👤',
                                type: 'warning',
                                confirmText: 'Sí, Sin Nombre',
                                cancelText: 'Cancelar'
                            });

                            if (confirmed) {
                                this.currentTicket.selectedCliente = {
                                    id: 0,
                                    nombre: 'SIN NOMBRE',
                                    numero: '4444440-1'
                                };
                                this.currentTicket.clienteSearch = 'SIN NOMBRE';
                                // Continuar después de asignar cliente
                            } else {
                                this.$nextTick(() => {
                                    this.$refs.clienteInput.focus();
                                });
                                return;
                            }
                        }
                        if (this.userConfig.cobro_df === 0) {
                            this.selectedPaymentMethod = this.userConfig.forma_pago_def || 1;
                            // Forzar factura común (ticket/comun) en lugar de electrónica
                            this.selectedDocType = 'comun';
                            this.confirmSale();
                            return;
                        }

                        this.showCheckoutModal = true;
                        this.cashAmountReceived = 0; // Reset cash received
                        this.paymentsList = []; // Limpiar lista de pagos

                        // Si el monto es 0, agregar pago en efectivo automático
                        if (this.total === 0) {
                            this.paymentsList.push({
                                method: 'efectivo',
                                amount: 0,
                                name: 'Efectivo'
                            });
                        }

                        // Aplicar predeterminados
                        this.selectedDocType = this.defaultDocType;
                        this.selectedPaymentMethod = null; // Abrir opciones del medio de pago

                        // Validar que el tipo de documento seleccionado sea válido para esta empresa
                        if (!this.docTypes.find(d => d.id === this.selectedDocType)) {
                            this.selectedDocType = this.docTypes[0].id;
                        }

                        this.focusedCheckoutSection = 'paymentMethods';
                        this.focusedCheckoutIdx = this.paymentMethods.findIndex(pm => pm.id === this.selectedPaymentMethod);
                        if (this.focusedCheckoutIdx === -1) this.focusedCheckoutIdx = 0;

                        this.voucherNumber = ''; // Reset voucher
                        this.playSound('info');
                    },

                    // Mostrar preview del documento antes de confirmar
                    showSalePreview() {
                        this.showPreviewModal = true;
                        this.playSound('info');
                    },

                    async handleFinishSale() {
                        if (this.cart.length === 0) {
                            this.toast('El carrito está vacío', 'error');
                            return;
                        }

                        // 1. Validar Crédito
                        if (this.currentTicket.formaPago === 'credito') {
                            const cliente = this.currentTicket.selectedCliente;
                            if (!cliente || !cliente.id) {
                                this.toast('Debe seleccionar un cliente para venta a Crédito', 'error');
                                this.playSound('error');
                                return;
                            }

                            // Calcular saldo y límite
                            const saldoActual = parseFloat(cliente.saldo_guaranies) || 0;
                            const lineaCredito = parseFloat(cliente.linea_credito) || 0;
                            const nuevoSaldo = saldoActual + this.total;

                            if (nuevoSaldo > lineaCredito) {
                                this.toast(`Límite de crédito excedido. Dif: ${this.formatMoney(nuevoSaldo - lineaCredito)}`, 'error');
                                this.toast(`Saldo: ${this.formatMoney(saldoActual)} | Límite: ${this.formatMoney(lineaCredito)}`, 'info');
                                this.playSound('error');
                                return;
                            }

                            // Si pasa, configurar pago total a crédito y confirmar directo
                            this.paymentsList = [{
                                method: 'credito',
                                amount: this.total,
                                name: 'Crédito',
                                credit_installments: 1
                            }];
                            this.selectedPaymentMethod = 'credito';

                            // Proceed to Confirm Sale directly
                            await this.confirmSale();
                            return;
                        }

                        // 2. Si es Contado, abrir modal de cobro (Flow normal)

                        // 2. Si es Contado
                        if (this.currentTicket.formaPago === 'contado') {
                            // Si la regla de cobro es "COBRADO" (1), usar modal simplificado
                            if (this.userConfig.cobro_df === 1) {
                                // Forzar Factura Común por defecto para venta rápida al contado
                                this.selectedDocType = 'comun';

                                this.simpleCashReceived = this.total; // Pre-cargar con el total
                                this.showSimpleChangeModal = true;
                                this.$nextTick(() => {
                                    setTimeout(() => {
                                        if (this.$refs.simpleCashInput) {
                                            this.$refs.simpleCashInput.focus();
                                            this.$refs.simpleCashInput.select(); // Seleccionar todo el texto
                                        }
                                    }, 100);
                                });
                            } else {
                                // Si es "Pasar por Caja" (0), imprimir directo sin modal
                                // Forzar Factura Común
                                this.selectedDocType = 'comun';
                                await this.confirmSale();
                            }
                        }
                    },

                    async confirmSimpleSale() {
                        // Validar entrega mínima
                        const entrega = parseFloat(this.simpleCashReceived) || 0;
                        if (entrega < this.total) {
                            this.toast('El monto recibido es menor al total', 'error');
                            this.playSound('error');
                            return;
                        }

                        // Preparar pago
                        this.paymentsList = [{
                            method: 'efectivo',
                            amount: this.total,
                            name: 'Efectivo',
                            cash_received: entrega,
                            cash_change: entrega - this.total
                        }];
                        this.selectedPaymentMethod = 'efectivo';

                        // Cerrar modal y confirmar
                        this.showSimpleChangeModal = false;
                        await this.confirmSale();
                    },

                    async confirmSale() {
                        if (this.processingSale) return;
                        this.processingSale = true;
                        this.playSound('success');

                        try {
                            const response = await fetch('api/venta.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    items: this.cart,
                                    total: this.total,
                                    id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                    doc_type: this.selectedDocType,
                                    payments: this.paymentsList,
                                    payment_method: this.paymentsList.length > 0 ? this.paymentsList[0].method : 'efectivo',
                                    voucher_number: this.paymentsList.find(p => p.method === 'tarjeta')?.voucher_number || '',
                                    transfer_reference: this.paymentsList.find(p => p.method === 'transferencia')?.transfer_reference || '',
                                    qr_transaction_code: this.paymentsList.find(p => p.method === 'qr')?.qr_transaction_code || '',
                                    credit_installments: this.paymentsList.find(p => p.method === 'credito')?.credit_installments || 1,
                                    credit_due_date: this.paymentsList.find(p => p.method === 'credito')?.credit_due_date || '',
                                    credit_notes: this.paymentsList.find(p => p.method === 'credito')?.credit_notes || '',
                                    cash_received: this.paymentsList.reduce((sum, p) => sum + (p.cash_received || 0), 0),
                                    cash_change: this.paymentsList.reduce((sum, p) => sum + (p.cash_change || 0), 0),
                                    id_empresa: this.idEmpresa,
                                    id_usuario: this.idUsuario,
                                    id_caja: this.idCaja
                                })
                            });

                            const result = await response.json();
                            if (result.success) {
                                this.lastVenta = result.data;
                                this.toast('Venta realizada con éxito', 'success');

                                // Redirección directa a impresión según tipo
                                const idFactura = result.data.id_factura;
                                const isElectro = (result.data.cdc && result.data.cdc.length > 10);
                                let script = isElectro ? '../kude_ticket.php' : '../nota_ticket.php';
                                if (this.userConfig.ancho_papel > 500) {
                                    script = isElectro ? '../kude_a4.php' : '../nota_a4.php';
                                }
                                // Mostrar SIEMPRE en modal, independientemente de configuración cobro_df
                                this.ticketUrl = script + '?id=' + idFactura;
                                this.showTicketModal = true;
                                this.showCheckoutModal = false;

                                // Resetear estados
                                // this.showSuccessModal = true; // No necesario si mostramos el ticket directo

                                this.clearCart();
                                this.toast('Venta finalizada con éxito', 'success');
                            } else {
                                this.toast(result.message || 'Error al procesar venta', 'error');
                            }
                        } catch (e) {
                            console.error('Error in confirmSale:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.processingSale = false;
                        }
                    },

                    clearCart() {
                        this.cart = [];
                        this.currentTicket.selectedCliente = null;
                        this.currentTicket.clienteSearch = '';
                        this.editingVenta = null; // Limpiar modo edición
                        this.saveTickets();
                        this.focusPrimaryInput();
                    },

                    // ===== EDICIÓN DE VENTAS =====
                    openEditSearchModal() {
                        this.showEditSearchModal = true;
                        this.editSearchQuery = '';
                        this.editSearchResults = [];
                        this.searchEditableVentas();
                        this.playSound('info');
                    },

                    async searchEditableVentas() {
                        this.loadingEditSearch = true;
                        try {
                            const res = await fetch(`api/venta_edit.php?action=search&q=${encodeURIComponent(this.editSearchQuery)}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();
                            if (data.success) {
                                this.editSearchResults = data.ventas || [];
                            } else {
                                this.toast(data.message || 'Error al buscar ventas', 'error');
                            }
                        } catch (e) {
                            console.error('Error searching editable sales:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.loadingEditSearch = false;
                        }
                    },

                    async loadVentaForEdit(id_factura) {
                        this.loadingEditSearch = true;
                        try {
                            const res = await fetch(`api/venta_edit.php?action=load&id=${id_factura}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();

                            if (data.success) {
                                // Limpiar carrito actual
                                this.cart = [];
                                this.currentTicket.selectedCliente = null;

                                // Cargar cliente
                                if (data.cliente) {
                                    this.currentTicket.selectedCliente = data.cliente;
                                    this.currentTicket.clienteSearch = data.cliente.nombre;
                                }

                                // Cargar items al carrito
                                data.items.forEach(item => {
                                    this.cart.push({
                                        id: item.id,
                                        extracto_id: item.extracto_id,
                                        codigo: item.codigo,
                                        descripcion: item.descripcion,
                                        cantidad: item.cantidad,
                                        precio: item.precio,
                                        costo: item.costo,
                                        tasa_iva: item.tasa_iva,
                                        stock: item.stock,
                                        controla_stock: item.controla_stock,
                                        vende_sin_stock: item.vende_sin_stock
                                    });
                                });

                                // Guardar referencia a la venta que estamos editando
                                this.editingVenta = data.venta;

                                this.showEditSearchModal = false;
                                this.toast(`Editando Nota #${data.venta.nro_factura}`, 'success');
                                this.playSound('success');
                                this.saveTickets();
                            } else {
                                this.toast(data.message || 'Error al cargar venta', 'error');
                                this.playSound('error');
                            }
                        } catch (e) {
                            console.error('Error loading sale for edit:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.loadingEditSearch = false;
                        }
                    },

                    async updateVenta() {
                        if (!this.editingVenta) {
                            this.toast('No hay venta en edición', 'error');
                            return;
                        }

                        if (this.processingSale) return;
                        this.processingSale = true;
                        this.playSound('info');

                        try {
                            // Preparar pagos
                            const cashPayment = this.paymentsList.find(p => p.method === 'efectivo');
                            const mainPayment = this.paymentsList[0] || {
                                method: 'efectivo'
                            };

                            const response = await fetch(`api/venta_edit.php?action=update&id_empresa=${this.idEmpresa}`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    id_factura: this.editingVenta.id_factura,
                                    id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                    items: this.cart.map(item => ({
                                        id: item.id,
                                        codigo: item.codigo,
                                        descripcion: item.descripcion,
                                        cantidad: item.cantidad,
                                        precio: item.precio,
                                        tasa_iva: item.tasa_iva || 10
                                    })),
                                    total: this.total,
                                    payment_method: mainPayment.method,
                                    cash_received: cashPayment?.cash_received || 0,
                                    cash_change: cashPayment?.cash_change || 0
                                })
                            });

                            const result = await response.json();

                            if (result.success) {
                                this.toast('Venta actualizada correctamente', 'success');
                                this.playSound('success');

                                // Mostrar ticket actualizado
                                const script = '../nota_ticket.php';
                                this.ticketUrl = `${script}?id=${this.editingVenta.id_factura}`;

                                if (this.userConfig.cobro_df === 0) {
                                    this.showTicketModal = true;
                                } else {
                                    window.open(this.ticketUrl + '&autoprint=1', '_blank');
                                }

                                this.showPreviewModal = false;
                                this.showCheckoutModal = false;
                                this.clearCart();
                            } else {
                                this.toast(result.message || 'Error al actualizar venta', 'error');
                                this.playSound('error');
                            }

                            // Si estábamos en modo edición por URL, dar opción de volver o volver automáticamente
                            const urlParams = new URLSearchParams(window.location.search);
                            if (urlParams.get('id_venta') || urlParams.get('edit_id')) {
                                setTimeout(() => {
                                    if (window.opener) {
                                        window.close();
                                    } else {
                                        window.location.href = '../facturas_sifen.php';
                                    }
                                }, 2000);
                            }
                        } catch (e) {
                            console.error('Error updating sale:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.processingSale = false;
                        }
                    },

                    cancelEditMode() {
                        // Verificar si vino por URL directa para edición
                        const urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.get('id_venta') || urlParams.get('edit_id')) {
                            if (window.opener) {
                                window.close();
                            } else {
                                window.location.href = '../facturas_sifen.php';
                            }
                            return;
                        }

                        this.editingVenta = null;
                        this.clearCart();
                        this.toast('Edición cancelada', 'warning');
                    },


                    // Sugerencias y búsquedaltar coincidencias en texto
                    highlightMatch(text, query) {
                        if (!query || !text) return text;
                        const terms = query.replace(/[<>=]\s*\d+/, '').trim().split(/\s+/).filter(t => t.length > 1);
                        let result = text;
                        terms.forEach(term => {
                            const regex = new RegExp(`(${term})`, 'gi');
                            result = result.replace(regex, '<mark>$1</mark>');
                        });
                        return result;
                    },

                    // Carrito
                    addToCart(producto) {
                        // this.playSound('success'); // Sonido AL INICIO - Moved to HTML

                        const existing = this.cart.find(item => item.id === producto.id);
                        if (existing) {
                            // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                            if (parseInt(existing.controla_stock) !== -1 && parseInt(existing.vende_sin_stock) === 0) {
                                const available = parseFloat(existing.stock_inicial || 0);
                                if (existing.cantidad + 1 > available) {
                                    this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                    this.playSound('error');
                                    return;
                                }
                            }
                            existing.cantidad++;
                        } else {
                            // Validación de stock inicial: si controla_stock != -1 y vende_sin_stock == 0
                            if (parseInt(producto.controla_stock) !== -1 && parseInt(producto.vende_sin_stock) === 0) {
                                const available = parseFloat(producto.stock || 0);
                                if (available <= 0) {
                                    this.toast(`Sin stock disponible.`, 'error');
                                    this.playSound('error');
                                    return;
                                }
                            }
                            const editablePrecio = parseInt(producto.edita_precio) === 1;
                            const editableDescripcion = parseInt(producto.editable) === 1;
                            this.cart.push({
                                id: producto.id,
                                codigo: producto.codigo,
                                descripcion: producto.descripcion,
                                precio: parseFloat(producto.precio),
                                precio_min: parseFloat(producto.precio_min || 0),
                                stock_inicial: parseFloat(producto.stock || 0),
                                vende_sin_stock: parseInt(producto.vende_sin_stock || 0),
                                controla_stock: parseInt(producto.controla_stock || 0),
                                cantidad: 1,
                                tasa_iva: producto.tasa_iva || 10,
                                editablePrecio,
                                editableDescripcion
                            });
                        }
                        // Limpiar búsqueda
                        this.searchQuery = '';
                        this.productos = [];
                        this.$refs.searchInput.focus();
                    },

                    updateQty(index, delta) {
                        const item = this.cart[index];
                        const newQty = item.cantidad + delta;
                        if (newQty < 1) return;

                        // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                        if (delta > 0 && parseInt(item.controla_stock) !== -1 && parseInt(item.vende_sin_stock) === 0) {
                            const available = parseFloat(item.stock_inicial || 0);
                            if (newQty > available) {
                                this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                this.playSound('error');
                                return;
                            }
                        }

                        item.cantidad = newQty;
                        this.recalculate();
                    },

                    updateQtyManual(index, value) {
                        const item = this.cart[index];
                        const newQty = parseFloat(value);
                        if (isNaN(newQty) || newQty < 1) {
                            item.cantidad = 1;
                            this.recalculate();
                            return;
                        }

                        // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                        if (parseInt(item.controla_stock) !== -1 && parseInt(item.vende_sin_stock) === 0) {
                            const available = parseFloat(item.stock_inicial || 0);
                            if (newQty > available) {
                                this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                this.playSound('error');
                                // Revertir al valor anterior o al máximo disponible
                                item.cantidad = Math.max(1, Math.min(item.cantidad, available));
                                this.$nextTick(() => this.recalculate());
                                return;
                            }
                        }

                        item.cantidad = newQty;
                        this.recalculate();
                    },

                    updatePrice(index, value) {
                        const precio = parseFloat(value);
                        if (!Number.isNaN(precio) && precio >= 0) {
                            this.cart[index].precio = precio;
                            this.recalculate();
                        }
                    },

                    updateDescripcion(index, value) {
                        this.cart[index].descripcion = value;
                        this.recalculate();
                    },

                    async removeFromCart(index) {
                        this.cart.splice(index, 1);
                        this.playSound('warning');
                    },

                    recalculate() {
                        // Trigger reactivity
                        this.cart = [...this.cart];
                    },

                    // Clientes
                    async searchClientes(checkSifen = false) {
                        const query = this.clienteSearch.trim();
                        if (query.length < 2) {
                            this.clientesResults = [];
                            return;
                        }

                        this.loadingClientes = true;
                        try {
                            const res = await fetch(`api/clientes.php?action=search&q=${encodeURIComponent(query)}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();
                            this.clientesResults = data.clientes || [];

                            // Solo buscar en SET si: se presionó Enter, no hay resultados locales, y parece RUC/Cédula
                            if (checkSifen && this.clientesResults.length === 0 && /^[0-9.-]+$/.test(query) && query.length >= 5) {
                                // Mostrar mensaje de que no existe localmente
                                this.toast('RUC no encontrado en base local. Buscando en SET...', 'info');

                                const resSifen = await fetch(`api/clientes.php?action=sifen_lookup&ruc=${encodeURIComponent(query)}&id_empresa=${this.idEmpresa}`);
                                const dataSifen = await resSifen.json();

                                if (dataSifen.cliente) {
                                    const cliente = dataSifen.cliente;

                                    // El cliente fue encontrado e insertado automáticamente
                                    // Mostrar confirmación con los datos
                                    this.showConfirmModal({
                                        title: 'Cliente Encontrado en SET',
                                        message: `RUC: ${cliente.ruc}\nNombre: ${cliente.nombre}\n\n¿Usar este cliente?`,
                                        icon: '✅',
                                        confirmText: 'Usar Cliente',
                                        cancelText: 'Cancelar',
                                        onConfirm: () => {
                                            // Actualizar el campo cliente
                                            this.currentTicket.selectedCliente = cliente;
                                            this.currentTicket.clienteSearch = cliente.nombre;
                                            this.showClienteDropdown = false;
                                            this.clientesResults = [];
                                            this.saveTickets();

                                            // Forzar actualización del input en el DOM
                                            this.$nextTick(() => {
                                                if (this.$refs.clienteInput) {
                                                    this.$refs.clienteInput.value = cliente.nombre;
                                                }
                                            });

                                            this.toast('Cliente seleccionado: ' + cliente.nombre, 'success');
                                            this.playSound('success');

                                            // Enfocar en COBRAR si hay items en carrito
                                            if (this.cart.length > 0) {
                                                this.$nextTick(() => {
                                                    this.openCheckout();
                                                });
                                            }
                                        }
                                    });
                                } else if (dataSifen.error) {
                                    this.toast(`No encontrado: ${dataSifen.error}`, 'warning');
                                }
                            } else if (checkSifen && this.clientesResults.length === 0) {
                                this.toast('Cliente no encontrado', 'warning');
                            }
                        } catch (e) {
                            console.error('Error searching clients:', e);
                        } finally {
                            this.loadingClientes = false;
                        }
                    },

                    selectCliente(cliente) {
                        this.currentTicket.selectedCliente = cliente;
                        this.currentTicket.clienteSearch = cliente.nombre;
                        this.showClienteDropdown = false;
                        this.clientesResults = [];
                        this.saveTickets();
                        this.$nextTick(() => {
                            this.$refs.searchInput.focus();
                        });
                    },

                    // Venta
                    async procesarVenta() {
                        if (this.cart.length === 0 || this.processing) return;

                        this.processing = true;
                        try {
                            const payload = {
                                id_empresa: this.idEmpresa,
                                id_cliente: this.selectedCliente?.id || 0,
                                forma_pago: this.formaPago,
                                items: this.cart.map(item => ({
                                    id_producto: item.id,
                                    codigo: item.codigo,
                                    descripcion: item.descripcion,
                                    precio: item.precio,
                                    cantidad: item.cantidad,
                                    tasa_iva: item.tasa_iva
                                })),
                                total: this.total
                            };

                            const res = await fetch('api/venta.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify(payload)
                            });

                            const data = await res.json();

                            if (data.success) {
                                this.lastVenta = data.data;
                                this.showSuccessModal = true;
                            } else {
                                this.toast('Error: ' + (data.message || 'Error al procesar la venta'), 'error');
                            }
                        } catch (e) {
                            console.error('Error processing sale:', e);
                            this.toast('Error de conexión', 'error');
                        }
                        this.processing = false;
                    },



                    printTicket() {
                        if (this.lastVenta?.id_factura) {
                            const isElectro = (this.lastVenta.cdc && this.lastVenta.cdc.length > 10);
                            let script = isElectro ? '../kude_ticket.php' : '../nota_ticket.php';
                            if (this.userConfig.ancho_papel > 500) {
                                script = isElectro ? '../kude_a4.php' : '../nota_a4.php';
                            }
                            window.open(`${script}?id=${this.lastVenta.id_factura}&autoprint=1`, '_blank');
                        }
                    },

                    async salir() {
                        // Si estamos editando, limpiar el POS completamente antes de salir
                        if (this.editingVenta) {
                            this.clearCart();
                            // Al limpiar, se guarda estado vacío y se sale del modo edición
                        }

                        // Verificar si hay tickets con items
                        const ticketsConItems = this.tickets.filter(t => t.cart.length > 0);
                        if (ticketsConItems.length > 0) {
                            const confirmed = await this.showConfirm({
                                title: 'Salir del POS',
                                message: `Tienes ${ticketsConItems.length} ticket(s) con productos.\n¿Salir de todos modos?\nLos datos se guardarán automáticamente.`,
                                icon: '🚪',
                                type: 'warning',
                                confirmText: 'Salir'
                            });
                            if (!confirmed) return;
                        }
                        // Guardar antes de salir
                        this.saveTickets();

                        // Intentar cerrar usando la función del padre
                        try {
                            if (typeof parent.cerrarApp === 'function') {
                                parent.cerrarApp();
                                return;
                            }

                            // Fallback: Forzar recarga del menú principal
                            if (window.top && window.top.location) {
                                window.top.location.href = '../menu/menu.php';
                            } else {
                                window.location.href = '../menu/menu.php';
                            }
                        } catch (e) {
                            console.error("Error al salir:", e);
                            window.location.href = '../menu/menu.php';
                        }
                    },

                    isLastVentaEligibleForSifen() {
                        if (!this.lastVenta || !this.lastVenta.fecha || !this.lastVenta.id_factura) return false;

                        // Solo si es de hoy
                        const today = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD
                        const ventaDate = this.lastVenta.fecha.split(' ')[0];

                        const isToday = (today === ventaDate);
                        const isNotElectronic = parseInt(this.lastVenta.tipo_documento) !== 3;
                        const failedElectronic = (parseInt(this.lastVenta.tipo_documento) === 3 && !this.lastVenta.cdc);

                        return isToday && (isNotElectronic || failedElectronic);
                    },

                    async convertirSifen() {
                        if (this.loadingSifen || !this.lastVenta?.id_factura) return;

                        this.loadingSifen = true;
                        try {
                            const formData = new FormData();
                            formData.append('id_factura', this.lastVenta.id_factura);
                            formData.append('id_empresa', this.idEmpresa);
                            // Si no era electrónica, pasar flag para habilitarla
                            if (parseInt(this.lastVenta.tipo_documento) !== 3) {
                                formData.append('convert', '1');
                            }

                            const res = await fetch('api/sifen_retry.php', {
                                method: 'POST',
                                body: formData
                            });

                            const data = await res.json();
                            if (data.success) {
                                this.toast('Factura Electrónica emitida con éxito', 'success');
                                this.lastVenta.cdc = data.cdc;
                                this.lastVenta.tipo_documento = 3;
                                // Actualizar URL del ticket
                                let script = '../kude_ticket.php';
                                if (this.userConfig.ancho_papel > 500) {
                                    script = '../kude_a4.php';
                                }
                                this.ticketUrl = `${script}?id=${this.lastVenta.id_factura}&autoprint=1`;
                            } else {
                                this.toast('Error SIFEN: ' + (data.message || 'Error desconocido'), 'error');
                            }
                        } catch (e) {
                            console.error('Error converting to SIFEN:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.loadingSifen = false;
                        }
                    },

                    async enviarEmail() {
                        if (!this.emailInput || !this.emailInput.includes('@')) {
                            this.toast('Email inválido', 'error');
                            return;
                        }
                        this.sendingEmail = true;
                        try {
                            // Intentar obtener el HTML del iframe del ticket
                            let ticketHtml = '';
                            try {
                                // Buscar cualquier iframe que contenga un documento de venta (.php)
                                const iframe = document.querySelector('iframe[src*=".php"]');
                                if (iframe && iframe.contentDocument) {
                                    ticketHtml = iframe.contentDocument.body.innerHTML;
                                }
                            } catch (e) {
                                console.warn('No se pudo obtener el HTML del iframe:', e);
                            }

                            const res = await fetch('../kude_email.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    email: this.emailInput,
                                    id_factura: this.lastVenta.id_factura,
                                    cdc: this.lastVenta.cdc || '',
                                    nro_factura: this.lastVenta.nro_factura,
                                    cliente: this.lastVenta.cliente,
                                    total: this.formatMoney(this.lastVenta.total),
                                    html_content: ticketHtml
                                })
                            });
                            const data = await res.json();
                            if (data.success) {
                                this.toast('Email enviado correctamente', 'success');
                                this.showEmailModal = false;
                            } else {
                                this.toast('Error: ' + data.error, 'error');
                            }
                        } catch (e) {
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.sendingEmail = false;
                        }
                    },

                    enviarWhatsApp() {
                        let phone = this.phoneInput.replace(/\D/g, '');
                        if (phone.startsWith('0')) phone = phone.substring(1);
                        if (phone.length < 6) {
                            this.toast('Número de teléfono inválido', 'error');
                            return;
                        }

                        const countryCode = '595'; // Predeterminado para Paraguay
                        const full = countryCode + phone;

                        const isElectro = (this.lastVenta.cdc && this.lastVenta.cdc.length > 10);
                        const verificationLink = isElectro ?
                            `https://ekuatia.set.gov.py/consultas/details?cdc=${this.lastVenta.cdc}` :
                            `${window.location.origin}/scriptcase/app/smx/verificar_nota.php?id=${this.lastVenta.id_factura}&id_empresa=${this.idEmpresa}`;

                        const title = isElectro ? '*FACTURA ELECTRÓNICA*' : '*NOTA DE COMPRA*';
                        const msg = `${title}\n\nNro: ${this.lastVenta.nro_factura}\nCliente: ${this.lastVenta.cliente}\nTotal: *${this.formatMoney(this.lastVenta.total)} Gs*\n\n🔗 *Verificar:* ${verificationLink}\n\n_SistemaX_`;

                        window.open(`https://wa.me/${full}?text=${encodeURIComponent(msg)}`, '_blank');
                        this.showWhatsAppModal = false;
                    },

                    newSale() {
                        this.showSuccessModal = false;
                        this.cart = [];
                        this.selectedCliente = null;
                        this.clienteSearch = '';
                        this.formaPago = 'contado';
                        this.lastVenta = null;
                        this.$refs.searchInput.focus();
                    },

                    // ===== MODAL DETALLE PRODUCTO =====

                    async openProductDetail(producto) {
                        if (!producto || !producto.id) return;

                        this.showProductDetailModal = true;
                        this.loadingDetail = true;
                        this.productDetail = null;
                        this.currentProductId = producto.id;

                        try {
                            const res = await fetch(`api/producto_detalle.php?id=${producto.id}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();

                            if (data.success) {
                                this.productDetail = data;
                            } else {
                                console.error('Error loading product detail:', data.error);
                                this.productDetail = null;
                            }
                        } catch (e) {
                            console.error('Error fetching product detail:', e);
                            this.productDetail = null;
                        }

                        this.loadingDetail = false;
                    },

                    addToCartFromDetail() {
                        if (!this.productDetail?.producto) return;

                        const p = this.productDetail.producto;
                        const precio = this.productDetail.precios?.[0]?.precio || p.precio_venta;

                        const producto = {
                            id: p.idproducto,
                            codigo: p.codigo,
                            descripcion: p.descripcion,
                            precio: parseFloat(precio),
                            stock: p.stock_global,
                            tasa_iva: p.tasa_iva || 10
                        };

                        this.addToCart(producto);
                    },

                    formatDate(dateStr) {
                        if (!dateStr) return '-';
                        try {
                            const date = new Date(dateStr);
                            return date.toLocaleDateString('es-PY', {
                                day: '2-digit',
                                month: '2-digit',
                                year: 'numeric'
                            });
                        } catch (e) {
                            return dateStr;
                        }
                    }
                }
            }
        </script>
        <!-- Modal WhatsApp -->
        <div
            x-show="showWhatsAppModal"
            x-cloak
            class="fixed inset-0 bg-black/70 flex items-center justify-center z-[70] backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-800 rounded-3xl p-6 max-w-sm w-full mx-4 shadow-2xl border border-slate-700">
                <div class="flex items-center gap-4 mb-6">
                    <div class="bg-green-500/20 p-3 rounded-2xl text-[#25D366] text-3xl">💬</div>
                    <div>
                        <h3 class="text-xl font-bold dark:text-white">Enviar WhatsApp</h3>
                        <p class="text-xs text-slate-400">Verifique el número del cliente</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1.5">Número de Celular</label>
                        <div class="flex gap-2">
                            <div class="bg-slate-100 dark:bg-slate-900 px-3 py-3 rounded-xl border border-slate-700 text-sm font-bold dark:text-white flex items-center">+595</div>
                            <input
                                type="tel"
                                x-model="phoneInput"
                                class="flex-1 bg-slate-100 dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-lg font-bold dark:text-white focus:ring-2 focus:ring-green-500 outline-none"
                                placeholder="981123456"
                                @keydown.enter="enviarWhatsApp()">
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button @click="showWhatsAppModal = false" class="flex-1 py-3 font-bold text-slate-400 hover:text-white transition-colors">Cancelar</button>
                        <button @click="enviarWhatsApp()" class="flex-[2] bg-[#25D366] hover:bg-[#128C7E] text-white font-bold py-3 rounded-xl shadow-lg transition-all active:scale-95">Abrir WhatsApp</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Email -->
        <div
            x-show="showEmailModal"
            x-cloak
            class="fixed inset-0 bg-black/70 flex items-center justify-center z-[70] backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-800 rounded-3xl p-6 max-w-sm w-full mx-4 shadow-2xl border border-slate-700">
                <div class="flex items-center gap-4 mb-6">
                    <div class="bg-blue-500/20 p-3 rounded-2xl text-blue-400 text-3xl">📧</div>
                    <div>
                        <h3 class="text-xl font-bold dark:text-white">Enviar Email</h3>
                        <p class="text-xs text-slate-400">Se enviará el comprobante digital</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1.5">Correo Electrónico</label>
                        <input
                            type="email"
                            x-model="emailInput"
                            class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-lg font-bold dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="cliente@ejemplo.com"
                            @keydown.enter="enviarEmail()">
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button @click="showEmailModal = false" class="flex-1 py-3 font-bold text-slate-400 hover:text-white transition-colors">Cancelar</button>
                        <button
                            @click="enviarEmail()"
                            :disabled="sendingEmail"
                            class="flex-[2] bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow-lg transition-all active:scale-95 disabled:opacity-50 flex items-center justify-center gap-2">
                            <span x-show="!sendingEmail">Enviar Email</span>
                            <span x-show="sendingEmail" class="animate-spin text-xl">⏳</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Vuelto Simplificado -->
        <div
            x-show="showSimpleChangeModal"
            x-cloak
            class="fixed inset-0 bg-black/80 flex items-center justify-center z-[80] backdrop-blur-sm"
            @keydown.escape.window="showSimpleChangeModal = false">
            <div class="bg-white dark:bg-slate-800 rounded-3xl p-8 max-w-md w-full mx-4 shadow-2xl border border-slate-700 relative overflow-hidden">
                <!-- Header Pattern -->
                <div class="absolute top-0 left-0 right-0 h-2 bg-gradient-to-r from-blue-500 via-purple-500 to-pink-500"></div>

                <h3 class="text-2xl font-black text-slate-800 dark:text-white mb-6 text-center tracking-tight">VUELTO / COBRO</h3>

                <div class="space-y-6">
                    <!-- Total a Pagar -->
                    <div class="bg-slate-100 dark:bg-slate-9000 rounded-2xl p-4 text-center border border-slate-200 dark:border-slate-700">
                        <div class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Total a Pagar</div>
                        <div class="text-4xl font-black text-slate-800 dark:text-white" x-text="formatMoney(total)"></div>
                    </div>

                    <!-- Input Entrega -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Monto Recibido (Entrega)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-xl font-bold text-slate-400">₲</span>
                            <input
                                x-ref="simpleCashInput"
                                type="number"
                                x-model="simpleCashReceived"
                                @keydown.enter="confirmSimpleSale()"
                                class="w-full bg-white dark:bg-slate-900 border-2 border-slate-300 dark:border-slate-600 rounded-xl px-4 py-4 pl-10 text-2xl font-bold text-slate-800 dark:text-white focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all text-right"
                                placeholder="0">
                        </div>
                    </div>

                    <!-- Vuelto Calculado -->
                    <div class="bg-green-500/10 rounded-2xl p-4 text-center border border-green-500/20">
                        <div class="text-xs font-bold text-green-600 dark:text-green-400 uppercase tracking-widest mb-1">Su Vuelto</div>
                        <div class="text-3xl font-black text-green-600 dark:text-green-400">
                            <span x-text="formatMoney(Math.max(0, (parseFloat(simpleCashReceived)||0) - total))"></span>
                        </div>
                    </div>

                    <!-- Acciones -->
                    <div class="grid grid-cols-2 gap-3 pt-2">
                        <button
                            @click="showSimpleChangeModal = false"
                            class="py-4 rounded-xl font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">
                            CANCELAR (Esc)
                        </button>
                        <button
                            @click="confirmSimpleSale()"
                            class="bg-blue-600 hover:bg-blue-700 text-white py-4 rounded-xl font-bold shadow-lg shadow-blue-600/30 active:scale-95 transition-all text-lg">
                            IMPRIMIR 🖨️
                        </button>
                    </div>
                </div>
            </div>
        </div>
</body>

</html>
