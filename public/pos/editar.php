<?php

/**
 * POS Desktop - Sistema de Punto de Venta
 * Tailwind CSS + Alpine.js + PHP
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';

$id_login = (int)($_SESSION['id_login'] ?? 0);
$id_empresa_session = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;

// Valores por defecto
$usuario = $_SESSION['usuario'] ?? 'Usuario';
$id_caja = (int)($_SESSION['id_caja_def'] ?? 0);
$id_empresa = $id_empresa_session;
$nombreEmpresa = 'SistemaX';
$isFacturaElectronica = 1;

try {
    // Obtener conexión maestra
    $pdo_init = getMasterConnection();

    // 1. Obtener datos extendidos del usuario desde sec_users (Fuente de Verdad)
    $stmt_user = $pdo_init->prepare("SELECT login, caja_def, id_empresa FROM sec_users WHERE id_login = :id");
    $stmt_user->execute([':id' => $id_login]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
    if ($user_data) {
        $usuario = $user_data['login'];
        $id_caja = (int)$user_data['caja_def'];
        $id_empresa = (int)$user_data['id_empresa']; // Priorizar empresa del usuario
    }

    // 2. Obtener datos de la empresa
    $stmt_init = $pdo_init->prepare("SELECT empresa, fe FROM empresa WHERE id_empresa = :id");
    $stmt_init->execute([':id' => $id_empresa]);
    $emp_data = $stmt_init->fetch(PDO::FETCH_ASSOC);
    if ($emp_data) {
        $nombreEmpresa = $emp_data['empresa'];
        $isFacturaElectronica = (int)$emp_data['fe'];
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
            margin: 0;
            padding: 0;
            height: 100%;
            width: 100%;
            overflow: hidden;
        }

        body {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            /* background-color is handled by tailwind classes */
        }

        .pos-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            /* Solid background logic */
            background-color: inherit;
        }

        /* Tema Claro - Refinado y con Profundidad */
        html:not(.dark) body {
            background-color: #f1f5f9;
            color: #0f172a;
        }

        /* Fondo general gris suave */

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
        <header class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-4 py-2 flex items-center justify-between gap-4">
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
                <!-- Modo Edición: Mostrar solo título -->
                <div x-show="editMode" class="bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 px-4 py-1.5 rounded-lg border border-amber-200 dark:border-amber-700/50 flex items-center gap-2 font-bold shadow-sm">
                    <span class="animate-pulse">✏️</span>
                    <span>Editando Factura #<span x-text="originalInvoiceData?.nro_factura || editingInvoiceId"></span></span>
                </div>

                <!-- Modo Normal: Sistema de Tickets -->
                <template x-if="!editMode">
                    <div class="flex items-center gap-1 flex-1 justify-center">
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
                </template>
            </div>

            <!-- Derecha: Hora, Usuario y Salir -->
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-slate-400 text-sm hidden sm:inline" x-text="currentTime"></span>

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
                        <div class="w-8 h-8 bg-blue-600/10 rounded-lg flex items-center justify-center border border-green-500/20">
                            <span class="w-2 h-2 bg-blue-600 rounded-full animate-pulse"></span>
                        </div>
                    </div>
                </div>

                <!-- Botón Salir -->
                <button
                    @click="salir()"
                    class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors text-sm flex items-center gap-2 border border-slate-200 dark:border-slate-700 font-bold"
                    title="Salir y cerrar el POS">
                    <span class="text-base">🚪</span>
                    <span class="font-bold">SALIR</span>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 flex overflow-hidden">

            <!-- Panel Izquierdo: Productos -->
            <section class="w-1/2 lg:w-2/5 border-r border-slate-200 dark:border-slate-700 flex flex-col overflow-visible">

                <!-- Búsqueda con Autocompletado -->
                <div class="p-4 border-b border-slate-200 dark:border-slate-700 relative overflow-visible">
                    <div class="relative" @click.away="showSuggestions = false">
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
                            placeholder="F2 Buscar por nombre, código o filtrado por precio (<500...)"
                            class="w-full bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg pl-10 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder-slate-500">
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                            <!-- Toggle Descontinuados -->
                            <button
                                @click="searchEstado = (searchEstado === 1 ? 0 : 1); searchProducts()"
                                :class="searchEstado === 0 ? 'bg-purple-500 text-slate-900 dark:text-white border-purple-400' : 'bg-white dark:bg-slate-800 text-slate-400 border-slate-600 hover:text-slate-900 dark:text-white'"
                                class="text-[10px] font-black px-2 py-1 rounded border transition-all flex items-center gap-1 shadow-lg"
                                :title="searchEstado === 1 ? 'Ver productos descontinuados' : 'Ver productos activos'">
                                <span>+ Descontinuado</span>
                            </button>

                            <button
                                x-show="searchQuery"
                                @click="searchQuery = ''; productos = []; showSuggestions = false"
                                class="text-slate-400 hover:text-slate-900 dark:text-white">✕</button>
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
                        <div x-show="viewMode === 'grid'" class="grid grid-cols-3 gap-3">
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
                                <span class="text-amber-500">🔥</span> Artículos Frecuentes
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
                        <div x-show="!loadingPopular && viewMode === 'grid'" class="grid grid-cols-3 gap-3">
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
            <section class="w-1/2 lg:w-3/5 flex flex-col bg-slate-850">

                <!-- Items del Carrito -->
                <div class="flex-1 overflow-y-auto custom-scroll">
                    <table class="w-full">
                        <thead class="bg-white dark:bg-slate-800 sticky top-0 border-b border-slate-200 dark:border-slate-700">
                            <tr class="text-left text-slate-400 text-[10px] uppercase tracking-wider">
                                <th class="px-3 py-2">Producto</th>
                                <th class="px-1 py-2 text-center w-20">Cant.</th>
                                <th class="px-1 py-2 text-right w-24">Precio</th>
                                <th class="px-3 py-2 text-right w-28">Subtotal</th>
                                <th class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, index) in cart" :key="item.id">
                                <tr class="cart-item border-b border-slate-200 dark:border-slate-700/50 hover:bg-white dark:bg-slate-800/40 transition-colors">
                                    <td class="px-3 py-1.5">
                                        <div class="font-bold text-sm text-slate-800 dark:text-slate-200 leading-tight" x-text="item.descripcion"></div>
                                        <div class="text-[9px] text-slate-500 font-mono" x-text="item.codigo"></div>
                                    </td>
                                    <td class="px-1 py-1.5">
                                        <div class="flex items-center justify-center gap-0.5">
                                            <button @click="POSAudio.play('info'); setTimeout(() => updateQty(index, -1), 0)" class="w-6 h-6 bg-slate-100 dark:bg-slate-700 rounded hover:bg-slate-600 text-sm flex items-center justify-center">-</button>
                                            <input
                                                type="number"
                                                x-model.number="item.cantidad"
                                                @change="POSAudio.play('info'); setTimeout(() => recalculate(), 0)"
                                                min="1"
                                                class="w-10 bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded text-center py-0.5 text-xs font-bold">
                                            <button @click="POSAudio.play('info'); setTimeout(() => updateQty(index, 1), 0)" class="w-6 h-6 bg-slate-100 dark:bg-slate-700 rounded hover:bg-slate-600 text-sm flex items-center justify-center">+</button>
                                        </div>
                                    </td>
                                    <td class="px-1 py-1.5 text-right text-xs text-slate-400 font-medium" x-text="formatMoney(item.precio)"></td>
                                    <td class="px-3 py-1.5 text-right font-black text-xs text-blue-600 dark:text-blue-400" x-text="formatMoney(item.precio * item.cantidad)"></td>
                                    <td class="pr-2">
                                        <button @click="POSAudio.play('warning'); setTimeout(() => removeFromCart(index), 0)" class="text-red-500 hover:text-red-700 p-1 transition-colors" title="Eliminar producto">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
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
                <div class="bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 p-4">

                    <!-- Cliente y Forma de Pago -->
                    <!-- Cliente -->
                    <div class="mb-4">
                        <label class="text-sm text-slate-400 mb-1 block">Cliente</label>
                        <div class="relative">
                            <input
                                type="text"
                                :value="currentTicket.clienteSearch"
                                @input="currentTicket.clienteSearch = $event.target.value; clienteSelectedIdx = 0; $nextTick(() => searchClientes())"
                                @focus="showClienteDropdown = true; clienteSelectedIdx = 0"
                                @keydown.arrow-down.prevent="if(clientesResults.length) clienteSelectedIdx = (clienteSelectedIdx + 1) % clientesResults.length"
                                @keydown.arrow-up.prevent="if(clientesResults.length) clienteSelectedIdx = (clienteSelectedIdx - 1 + clientesResults.length) % clientesResults.length"
                                @keydown.enter.prevent="if(clientesResults.length > 0 && showClienteDropdown) POSAudio.play('success'); selectCliente(clientesResults[clienteSelectedIdx])"
                                @keydown.escape="showClienteDropdown = false"
                                placeholder="Buscar por nombre, RUC, teléfono o email..."
                                x-ref="clienteInput"
                                class="w-full bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                            <!-- Dropdown clientes -->
                            <div
                                x-show="showClienteDropdown && clientesResults.length > 0"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                @click.away="showClienteDropdown = false"
                                class="absolute z-20 w-full mt-1 bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg shadow-xl max-h-48 overflow-y-auto">
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

                    <!-- Totales -->
                    <div class="bg-slate-50 dark:bg-slate-900 rounded-lg p-3 mb-3 border border-slate-200 dark:border-slate-700/30">
                        <div class="flex justify-between text-slate-400 mb-1 text-xs">
                            <span>Subtotal</span>
                            <span class="font-bold" x-text="formatMoney(subtotal) + ' Gs'"></span>
                        </div>
                        <div class="flex justify-between text-slate-500 mb-1 text-[10px] italic">
                            <span>IVA (10%) Incluido</span>
                            <span x-text="formatMoney(iva) + ' Gs'"></span>
                        </div>
                        <!--<div class="border-t border-slate-200 dark:border-slate-700 pt-2 mt-2">
                        <div class="flex justify-between items-baseline">
                            <span class="text-xs font-black text-slate-600 dark:text-slate-400 uppercase tracking-tighter">TOTAL A PAGAR</span>
                            <span class="text-2xl font-black text-blue-600 dark:text-blue-400 leading-none" x-text="formatMoney(total) + ' Gs'"></span>
                        </div>
                    </div>-->
                    </div>

                    <!-- Acciones -->
                    <div class="flex gap-3">
                        <button
                            @click="openCheckout()"
                            :disabled="cart.length === 0 || processingSale"
                            :class="cart.length === 0 ? 'bg-slate-600 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700'"
                            class="w-full text-white font-bold py-4 rounded-xl transition-all flex items-center justify-center gap-3 text-2xl shadow-lg hover:shadow-green-500/20 active:scale-[0.98]">
                            <span x-show="!processingSale" class="flex items-center gap-3">
                                <!-- <span class="text-3xl">💸</span> -->
                                <span x-text="editMode ? 'RE-ENVIAR' : 'COBRAR'"></span>
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
                                <template x-if="productDetail?.producto?.imagen_url">
                                    <img :src="productDetail.producto.imagen_url"
                                        class="max-w-full max-h-48 object-contain rounded-lg"
                                        alt="Foto producto">
                                </template>
                                <template x-if="!productDetail?.producto?.imagen_url">
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
                                    💰 Precios Habilitados
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
            @keydown.escape.window="showCheckoutModal = false"
            @keydown.arrow-down.window.prevent="if(showCheckoutModal && !showCashModal) navigateCheckout('ArrowDown')"
            @keydown.arrow-up.window.prevent="if(showCheckoutModal && !showCashModal) navigateCheckout('ArrowUp')"
            @keydown.arrow-left.window.prevent="if(showCheckoutModal && !showCashModal) navigateCheckout('ArrowLeft')"
            @keydown.arrow-right.window.prevent="if(showCheckoutModal && !showCashModal) navigateCheckout('ArrowRight')"
            @keydown.enter.window.prevent="if(showCheckoutModal && !showCashModal) selectFocusedItem()">
            <div
                @click.away="showCheckoutModal = false"
                class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Header Modal -->
                <div class="bg-slate-100 dark:bg-slate-700/30 p-4 border-b border-slate-600 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <div class="bg-blue-600/20 p-2 rounded-lg text-blue-600 dark:text-blue-400 text-xl">💰</div>
                        <div>
                            <h2 class="text-lg font-bold text-slate-900 dark:text-white leading-tight">Finalizar Venta</h2>
                            <p class="text-[10px] text-slate-400 uppercase tracking-tighter">Click derecho para fijar predeterminados</p>
                        </div>
                    </div>
                    <button @click="showCheckoutModal = false" class="text-slate-400 hover:text-slate-900 dark:text-white transition-colors text-2xl px-2">×</button>
                </div>

                <div class="p-4 overflow-y-auto custom-scroll flex-1">
                    <div class="space-y-6 max-w-xl mx-auto">
                        <!-- Tipo de Documento -->
                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 block">Tipo de Documento</label>
                            <div class="grid grid-cols-1 gap-1.5">
                                <template x-for="(doc, index) in docTypes" :key="doc.id">
                                    <button
                                        @click="selectedDocType = doc.id; POSAudio.play('info')"
                                        @contextmenu.prevent="setDefault('doc', doc.id)"
                                        @mouseenter="focusedCheckoutSection = 'docTypes'; focusedCheckoutIdx = index"
                                        :class="{
                                        'bg-blue-600 border-blue-400 ring-2 ring-white/50 scale-[1.01]': focusedCheckoutSection === 'docTypes' && focusedCheckoutIdx === index,
                                        'bg-blue-600 border-blue-400': selectedDocType === doc.id && !(focusedCheckoutSection === 'docTypes' && focusedCheckoutIdx === index),
                                        'bg-slate-100 dark:bg-slate-700 border-slate-600 opacity-60 hover:opacity-100': selectedDocType !== doc.id && !(focusedCheckoutSection === 'docTypes' && focusedCheckoutIdx === index)
                                    }"
                                        class="flex items-center justify-between p-2.5 rounded-xl border-2 transition-all group relative">
                                        <div class="flex items-center gap-2">
                                            <span class="text-lg" x-text="doc.icon"></span>
                                            <span class="text-sm font-bold text-slate-900 dark:text-white" x-text="doc.name"></span>
                                            <span x-show="defaultDocType === doc.id" class="text-[10px] bg-yellow-400 text-slate-900 dark:text-white px-1.5 rounded font-black">⭐ FIX</span>
                                        </div>
                                        <div :class="selectedDocType === doc.id ? 'bg-white dark:bg-slate-800' : 'bg-white dark:bg-slate-800'" class="w-3.5 h-3.5 rounded-full border-2 border-slate-500"></div>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Metodo de Pago -->
                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 block">Medio de Pago (Procesa al seleccionar)</label>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="(pm, index) in paymentMethods" :key="pm.id">
                                    <button
                                        @click="if(pm.id === 'efectivo') { openCashModal() } else { selectedPaymentMethod = pm.id; confirmSale() }"
                                        @contextmenu.prevent="setDefault('pm', pm.id)"
                                        @mouseenter="focusedCheckoutSection = 'paymentMethods'; focusedCheckoutIdx = index"
                                        :class="{
                                        'bg-green-600 border-green-400 ring-2 ring-white/50 scale-[1.01]': focusedCheckoutSection === 'paymentMethods' && focusedCheckoutIdx === index,
                                        'bg-green-600 border-green-400': selectedPaymentMethod === pm.id && !(focusedCheckoutSection === 'paymentMethods' && focusedCheckoutIdx === index),
                                        'bg-slate-100 dark:bg-slate-700 border-slate-600 opacity-70 hover:opacity-100': selectedPaymentMethod !== pm.id && !(focusedCheckoutSection === 'paymentMethods' && focusedCheckoutIdx === index)
                                    }"
                                        class="flex flex-col items-center justify-center p-3 rounded-xl border-2 transition-all gap-1.5 h-24 text-center relative overflow-hidden">
                                        <span class="text-3xl" x-text="pm.icon"></span>
                                        <span class="text-[9px] font-black text-slate-900 dark:text-white uppercase leading-tight px-1" x-text="pm.name"></span>

                                        <div x-show="defaultPaymentMethod === pm.id" class="absolute top-1 left-1">
                                            <span class="text-[8px] bg-yellow-400 text-slate-900 dark:text-white px-1 rounded font-black">⭐</span>
                                        </div>

                                        <div x-show="selectedPaymentMethod === pm.id" class="absolute top-1 right-1">
                                            <div class="bg-white dark:bg-slate-800 text-green-600 rounded-full w-4 h-4 flex items-center justify-center text-[8px] font-bold">✓</div>
                                        </div>
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
        </div>

        <!-- Modal Auxiliar de Efectivo (Cálculo de Vuelto) -->
        <div
            x-show="showCashModal"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md"
            @keydown.escape.window="showCashModal = false"
            @keydown.arrow-down.window.prevent="if(showCashModal) navigateCash('ArrowDown')"
            @keydown.arrow-up.window.prevent="if(showCashModal) navigateCash('ArrowUp')"
            @keydown.arrow-left.window.prevent="if(showCashModal) navigateCash('ArrowLeft')"
            @keydown.arrow-right.window.prevent="if(showCashModal) navigateCash('ArrowRight')"
            @keydown.enter.window.prevent="if(showCashModal) selectFocusedCashItem()">
            <div
                @click.away="showCashModal = false"
                class="bg-white dark:bg-slate-800 border-2 border-slate-600 w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">
                <div class="p-6 space-y-6">
                    <div class="flex items-center gap-4 border-b border-slate-200 dark:border-slate-700 pb-4">
                        <div class="bg-blue-600/20 p-3 rounded-2xl text-blue-600 dark:text-blue-400 text-3xl">💵</div>
                        <div>
                            <h3 class="text-xl font-bold text-slate-900 dark:text-white">Pago en Efectivo</h3>
                            <p class="text-sm text-slate-400">Total: <span class="text-slate-900 dark:text-white font-bold" x-text="formatMoney(total)"></span></p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-1">Monto Recibido</label>
                            <div class="relative group">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold text-xl transition-colors group-focus-within:text-green-500">Gs.</span>
                                <input
                                    type="number"
                                    x-model.number="cashAmountReceived"
                                    x-ref="cashInput"
                                    @keydown.enter="confirmCashAmount()"
                                    class="w-full bg-slate-50 dark:bg-slate-900 border-2 border-slate-200 dark:border-slate-700 focus:border-green-500 rounded-2xl px-12 py-5 text-4xl font-black text-slate-900 dark:text-white focus:outline-none transition-all shadow-inner"
                                    @focus="$event.target.select()">
                            </div>
                        </div>

                        <div class="bg-slate-50 dark:bg-slate-900/50 p-4 rounded-2xl border border-slate-200 dark:border-slate-700 flex justify-between items-center">
                            <span class="text-slate-400 font-bold uppercase text-xs">Vuelto a entregar</span>
                            <div class="text-4xl font-black" :class="cashChange > 0 ? 'text-yellow-400' : 'text-slate-600 dark:text-slate-400'" x-text="formatMoney(cashChange)"></div>
                        </div>

                        <!-- Botones Rápidos -->
                        <div class="grid grid-cols-4 gap-2">
                            <template x-for="(val, vidx) in [2000, 5000, 10000, 20000, 50000, 100000, 200000]" :key="val">
                                <button
                                    @click="cashAmountReceived = val; POSAudio.play('success')"
                                    @mouseenter="focusedCashIdx = vidx"
                                    :class="focusedCashIdx === vidx ? 'bg-green-600 border-green-400 ring-2 ring-white scale-105' : 'bg-slate-100 dark:bg-slate-700 border-slate-600'"
                                    class="text-slate-900 dark:text-white font-bold py-2 rounded-xl border transition-all active:scale-95 text-xs whitespace-nowrap"
                                    x-text="formatMoney(val)"></button>
                            </template>
                            <button
                                @click="cashAmountReceived = 0; POSAudio.play('warning'); focusedCashIdx = -1; $nextTick(() => $refs.cashInput.focus())"
                                @mouseenter="focusedCashIdx = 7"
                                :class="focusedCashIdx === 7 ? 'bg-red-600 ring-2 ring-white scale-105' : 'bg-red-600/20 border-red-500/30 text-red-400'"
                                class="font-bold py-2 rounded-xl border transition-all active:scale-95 text-xs">LIMPIAR</button>
                        </div>
                        <div class="grid grid-cols-1 gap-2">
                            <button
                                @click="cashAmountReceived = total; POSAudio.play('success')"
                                @mouseenter="focusedCashIdx = 8"
                                :class="focusedCashIdx === 8 ? 'bg-blue-600 border-blue-400 ring-2 ring-white scale-105 text-slate-900 dark:text-white' : 'bg-blue-600/20 border-blue-500/30 text-blue-400'"
                                class="w-full font-bold py-3 rounded-xl border transition-all active:scale-95 flex items-center justify-center gap-2">
                                <span>✨ MONTO EXACTO:</span>
                                <span x-text="formatMoney(total)"></span>
                            </button>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button
                            @click="showCashModal = false"
                            @mouseenter="focusedCashIdx = 9"
                            :class="focusedCashIdx === 9 ? 'bg-slate-600 ring-2 ring-white scale-105' : 'bg-slate-100 dark:bg-slate-700'"
                            class="flex-1 text-slate-900 dark:text-white font-bold py-4 rounded-2xl transition-all active:scale-95">CERRAR</button>
                        <button
                            @click="confirmCashAmount()"
                            @mouseenter="focusedCashIdx = 10"
                            :disabled="cashAmountReceived < total"
                            :class="{
                            'bg-blue-600 ring-4 ring-white scale-105 shadow-2xl': focusedCashIdx === 10 && cashAmountReceived >= total,
                            'bg-blue-600': focusedCashIdx !== 10 && cashAmountReceived >= total,
                            'opacity-50 cursor-not-allowed grayscale bg-slate-100 dark:bg-slate-700': cashAmountReceived < total
                        }"
                            class="flex-[2] text-slate-900 dark:text-white font-black py-4 rounded-2xl shadow-xl shadow-green-900/20 transition-all text-lg">CONFIRMAR MONTO</button>
                    </div>
                </div>
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
                    console.log('🔈 Audio System initialized');
                } catch (e) {
                    console.warn('AudioContext not available');
                }
            },
            play(type) {
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
                searchQuery: '',
                filterCategory: null,
                productos: [],
                categories: [],
                loading: false,
                processing: false,

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

                // UI
                currentTime: '',
                showSuccessModal: false,
                lastVenta: null,

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

                // --- Edit Mode Props ---
                editMode: false,
                editingInvoiceId: null,
                originalInvoiceData: null,

                async loadInvoiceForEdit(id) {
                    console.log('loadInvoiceForEdit triggered with ID:', id);
                    this.loading = true;
                    try {
                        const res = await fetch(`api/get_venta.php?id=${id}&id_empresa=${this.idEmpresa}`);
                        const data = await res.json();
                        console.log('Invoice data fetched:', data);

                        if (data.success) {
                            this.editMode = true;
                            this.editingInvoiceId = id;
                            this.originalInvoiceData = data.factura;

                            // Poblar Ticket actual
                            this.tickets[this.activeTicket].cart = data.items.map(item => ({
                                ...item,
                                // Asegurar tipos
                                precio: parseFloat(item.precio),
                                cantidad: parseFloat(item.cantidad),
                                subtotal: parseFloat(item.precio) * parseFloat(item.cantidad)
                            }));

                            if (data.cliente) {
                                this.tickets[this.activeTicket].selectedCliente = data.cliente;
                                this.tickets[this.activeTicket].clienteSearch = data.cliente.nombre;
                            }

                            // Configurar checkout
                            this.selectedDocType = data.factura.doc_type;
                            this.defaultDocType = data.factura.doc_type;

                            this.toast(`Factura #${data.factura.nro_factura} cargada para edición`, 'info');
                        } else {
                            this.toast('Error al cargar factura: ' + data.message, 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Error de conexión al cargar factura', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                // Config
                idEmpresa: localStorage.getItem('id_empresa') || <?php echo $id_empresa; ?>,
                idUsuario: localStorage.getItem('id_login') || <?php echo $id_login; ?>,
                idCaja: <?php echo $id_caja; ?>,
                searchEstado: 1, // 1: Activos, 0: Descontinuados

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

                    // Cargar categories
                    this.loadCategories();

                    // --- MODIFICACIÓN EDITAR ---
                    const urlParams = new URLSearchParams(window.location.search);
                    const editId = urlParams.get('id');
                    console.log('Init executing. Found editId in URL:', editId, 'Full Search:', window.location.search);
                    if (editId) {
                        this.loadInvoiceForEdit(editId);
                    } else {
                        // Solo cargar tickets si NO estamos editando (para no mezclar)
                        this.loadTickets();
                        this.focusPrimaryInput();
                    }

                    this.updateTime();

                    // Pre-inicializar AudioContext para sonidos inmediatos
                    this.initAudio();

                    // Auto-guardar tickets de forma eficiente (cada 5 segundos si hay cambios)
                    setInterval(() => this.saveTickets(), 5000);

                    // Guardar antes de cerrar la página
                    window.addEventListener('beforeunload', () => this.saveTickets());

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
                        const res = await fetch(`api/productos.php?action=popular&id_empresa=${this.idEmpresa}`);
                        const data = await res.json();
                        this.popularProducts = data.productos || [];
                    } catch (e) {
                        console.error('Error loading popular products:', e);
                    } finally {
                        this.loadingPopular = false;
                    }
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
                            estado: this.searchEstado
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
                    // Si hay resultados visibles y uno seleccionado, añadir al carrito
                    if (this.productos.length > 0) {
                        this.addToCart(this.productos[this.selectedIndex]);
                        this.searchQuery = '';
                        this.productos = [];
                        this.playSound('success');
                        return;
                    }

                    const query = this.searchQuery?.trim();
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
                processingSale: false,

                // Predeterminados
                defaultDocType: localStorage.getItem('pos_default_doc') || '<?php echo $isFacturaElectronica ? "electro" : "auto"; ?>',
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
                        if (pm.id === 'efectivo') {
                            this.openCashModal();
                        } else {
                            this.selectedPaymentMethod = pm.id;
                            this.confirmSale();
                        }
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
                        name: 'Transferencia',
                        icon: '🏦'
                    },
                    {
                        id: 'qr',
                        name: 'Pago QR / PIX',
                        icon: '📱'
                    },
                    {
                        id: 'credito',
                        name: 'Crédito / A Cuenta',
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
                    this.cashAmountReceived = 0;
                    this.focusedCashIdx = -1; // Reset to input focus
                    this.playSound('info');
                    this.$nextTick(() => {
                        this.$refs.cashInput.focus();
                        this.$refs.cashInput.select();
                    });
                },

                confirmCashAmount() {
                    if (this.cashAmountReceived < this.total) {
                        this.toast('Monto insuficiente', 'warning');
                        this.playSound('error');
                        return;
                    }
                    this.selectedPaymentMethod = 'efectivo';
                    this.showCashModal = false;
                    this.playSound('success');

                    // Finalizar la venta automáticamente
                    this.confirmSale();
                },

                async openCheckout() {
                    if (this.cart.length === 0) {
                        this.toast('El carrito está vacío', 'error');
                        this.playSound('error');
                        return;
                    }
                    if (!this.currentTicket.selectedCliente) {
                        this.toast('Seleccione un cliente para continuar', 'error');
                        this.playSound('error');
                        this.$nextTick(() => {
                            this.$refs.clienteInput.focus();
                        });
                        return;
                    }
                    this.showCheckoutModal = true;
                    this.cashAmountReceived = 0; // Reset cash received

                    // Aplicar predeterminados
                    this.selectedDocType = this.defaultDocType;
                    this.selectedPaymentMethod = this.defaultPaymentMethod;

                    // Validar que el tipo de documento seleccionado sea válido para esta empresa
                    if (!this.docTypes.find(d => d.id === this.selectedDocType)) {
                        this.selectedDocType = this.docTypes[0].id;
                    }

                    this.focusedCheckoutSection = 'paymentMethods';
                    this.focusedCheckoutIdx = this.paymentMethods.findIndex(pm => pm.id === this.selectedPaymentMethod);
                    if (this.focusedCheckoutIdx === -1) this.focusedCheckoutIdx = 0;

                    this.playSound('info');
                },

                async confirmSale() {
                    if (this.processingSale) return;
                    this.processingSale = true;
                    this.playSound('success');

                    const apiUrl = this.editMode ? 'api/reenviar.php' : 'api/venta.php';

                    try {
                        const payload = {
                            items: this.cart,
                            total: this.total,
                            id_cliente: this.currentTicket.selectedCliente?.id || 0,
                            doc_type: this.selectedDocType,
                            payment_method: this.selectedPaymentMethod,
                            cash_received: this.cashAmountReceived,
                            cash_change: this.cashChange,
                            id_empresa: this.idEmpresa,
                            id_usuario: this.idUsuario,
                            id_caja: this.idCaja
                        };

                        // Add ID if editing
                        if (this.editMode) {
                            payload.id_factura = this.editingInvoiceId;
                        }

                        console.log('confirmSale payload:', payload, 'URL:', apiUrl);

                        const response = await fetch(apiUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });

                        const result = await response.json();
                        if (result.success) {
                            this.toast(this.editMode ? 'Factura Reenviada con Éxito' : 'Venta realizada con éxito', 'success');

                            if (this.editMode) {
                                // Volver al listado
                                setTimeout(() => {
                                    window.location.href = '../facturas_sifen.php';
                                }, 1500);
                            } else {
                                this.lastVenta = result.data;
                                this.showCheckoutModal = false;
                                this.showSuccessModal = true;
                                this.currentTicket.cart = []; // Limpiar carrito
                                this.currentTicket.selectedCliente = null;
                                this.saveTickets();
                            }

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
                    this.saveTickets();
                    this.focusPrimaryInput();
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
                        existing.cantidad++;
                    } else {
                        this.cart.push({
                            id: producto.id,
                            codigo: producto.codigo,
                            descripcion: producto.descripcion,
                            precio: parseFloat(producto.precio),
                            cantidad: 1,
                            tasa_iva: producto.tasa_iva || 10
                        });
                    }
                    // Limpiar búsqueda
                    this.searchQuery = '';
                    this.productos = [];
                    this.$refs.searchInput.focus();
                },

                updateQty(index, delta) {
                    this.cart[index].cantidad = Math.max(1, this.cart[index].cantidad + delta);
                },

                async removeFromCart(index) {
                    const item = this.cart[index];
                    const authorized = await this.showPasswordPrompt({
                        title: 'Eliminar Producto',
                        message: `Para eliminar "${item.descripcion}" se requiere autorización.`,
                        data: {
                            index
                        }
                    });

                    if (authorized) {
                        this.cart.splice(index, 1);
                        this.toast('Producto eliminado', 'warning');
                    }
                },

                recalculate() {
                    // Trigger reactivity
                    this.cart = [...this.cart];
                },

                // Clientes
                async searchClientes() {
                    if (this.clienteSearch.length < 2) {
                        this.clientesResults = [];
                        return;
                    }
                    try {
                        const res = await fetch(`api/clientes.php?action=search&q=${encodeURIComponent(this.clienteSearch)}&id_empresa=${this.idEmpresa}`);
                        const data = await res.json();
                        this.clientesResults = data.clientes || [];
                    } catch (e) {
                        console.error('Error searching clients:', e);
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
                        window.open(`../kude_ticket.php?id=${this.lastVenta.id_factura}&autoprint=1`, '_blank');
                    }
                },

                async salir() {
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

                    // Intentar volver al menú y recargar
                    // Intentar volver al menú y recargar
                    try {
                        // Volver a facturas_sifen.php
                        window.location.href = '../facturas_sifen.php';
                    } catch (e) {
                        // En caso de error de seguridad (CORS), simplemente redirigir
                        window.location.href = '../facturas_sifen.php';
                    }
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
</body>

</html>
