<?php

/**
 * Dashboard de Suscripciones SaaS
 * Panel con KPIs, gráficos de ingresos y estado de pagos
 * Solo accesible para super admin (empresa 169)
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Verificar sesión y permisos
Session::requireLogin('/public/login.php');

$id_empresa = Session::getIdEmpresa();
$usr_priv_admin = ($_SESSION['usr_priv_admin'] ?? 'N') === 'Y';

// Solo empresa 169 con permisos de admin
if ($id_empresa != 169 || !$usr_priv_admin) {
    header('Location: /public/menu/menu.php');
    exit;
}

$usr_name = $_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<script>
    // Detectar tema del sistema
    (function() {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        document.documentElement.style.colorScheme = prefersDark ? 'dark' : 'light';
        if (prefersDark) {
            document.documentElement.classList.add('dark');
        }
    })();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard SaaS - SistemaX</title>
    <link rel="stylesheet" href="assets/tailwind.css">
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.95);
            border-color: rgba(255, 255, 255, 0.1);
        }
        .gradient-text {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        /* Full height app */
        html, body {
            height: 100%;
            overflow: hidden;
        }
        .app-container {
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .app-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }
    </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900">
    <div x-data="dashboardApp()" x-init="init()" x-cloak class="app-container">
        <!-- Header -->
        <header class="bg-white/80 dark:bg-white/10 backdrop-blur-xl border-b border-slate-200 dark:border-white/10 flex-shrink-0">
            <div class="w-full px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <button @click="salir()" class="text-slate-500 dark:text-white/60 hover:text-slate-800 dark:hover:text-white transition-colors">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-slate-800 dark:text-white">Dashboard SaaS</h1>
                        <p class="text-slate-500 dark:text-white/60 text-sm">Métricas e indicadores de suscripciones</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="suscripciones.php" 
                       class="bg-slate-200 dark:bg-white/10 hover:bg-slate-300 dark:hover:bg-white/20 text-slate-700 dark:text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        <i class="fas fa-cog mr-1"></i> Gestionar
                    </a>
                    <button @click="init()" class="text-slate-500 dark:text-white/60 hover:text-slate-800 dark:hover:text-white p-2" title="Actualizar">
                        <i class="fas fa-sync-alt" :class="{ 'fa-spin': cargando }"></i>
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="app-content w-full px-6 py-6 space-y-6">
            
            <!-- KPIs Row 1 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Empresas Activas -->
                <div class="glass-card rounded-2xl p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Empresas Activas</p>
                            <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1" x-text="kpis.empresas_activas || 0"></p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                de <span x-text="kpis.total_empresas || 0"></span> registradas
                            </p>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                            <i class="fas fa-building text-blue-600 dark:text-blue-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Ingresos del Mes -->
                <div class="glass-card rounded-2xl p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Ingresos del Mes</p>
                            <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1">
                                ₲ <span x-text="formatNumber(kpis.ingresos_mes || 0)"></span>
                            </p>
                            <p class="text-xs mt-1" 
                               :class="kpis.variacion_ingresos >= 0 ? 'text-green-500' : 'text-red-500'">
                                <i :class="kpis.variacion_ingresos >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'"></i>
                                <span x-text="Math.abs(kpis.variacion_ingresos || 0) + '%'"></span> vs mes anterior
                            </p>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                            <i class="fas fa-chart-line text-green-600 dark:text-green-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Pendientes de Pago -->
                <div class="glass-card rounded-2xl p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Pendientes de Pago</p>
                            <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1" x-text="kpis.pendientes_pago || 0"></p>
                            <p class="text-xs text-amber-500 mt-1">
                                ₲ <span x-text="formatNumber(kpis.monto_pendiente || 0)"></span>
                            </p>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                            <i class="fas fa-clock text-amber-600 dark:text-amber-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Tasa de Cobro -->
                <div class="glass-card rounded-2xl p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Tasa de Cobro</p>
                            <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1">
                                <span x-text="kpis.tasa_cobro || 0"></span>%
                            </p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1" x-text="kpis.mes_actual || ''"></p>
                        </div>
                        <div class="w-14 h-14 rounded-2xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                            <i class="fas fa-percentage text-purple-600 dark:text-purple-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- KPIs Row 2 - Estados -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- En Gracia -->
                <div class="glass-card rounded-2xl p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                        <i class="fas fa-hourglass-half text-yellow-600 dark:text-yellow-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800 dark:text-white" x-text="kpis.en_gracia || 0"></p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">En Período de Gracia</p>
                    </div>
                </div>
                
                <!-- Vencidas -->
                <div class="glass-card rounded-2xl p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                        <i class="fas fa-ban text-red-600 dark:text-red-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800 dark:text-white" x-text="kpis.vencidas || 0"></p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Bloqueadas por Vencimiento</p>
                    </div>
                </div>
                
                <!-- Tasa de Retención -->
                <div class="glass-card rounded-2xl p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-cyan-100 dark:bg-cyan-900/30 flex items-center justify-center">
                        <i class="fas fa-users text-cyan-600 dark:text-cyan-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-800 dark:text-white">
                            <span x-text="kpis.total_empresas > 0 ? Math.round((kpis.empresas_activas / kpis.total_empresas) * 100) : 0"></span>%
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Tasa de Retención</p>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Gráfico de Ingresos Mensuales -->
                <div class="lg:col-span-2 glass-card rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-gray-800 dark:text-white">
                            <i class="fas fa-chart-bar text-blue-500 mr-2"></i>
                            Ingresos Mensuales
                        </h3>
                        <select x-model="mesesGrafico" @change="cargarIngresosMensuales()"
                                class="text-sm border rounded-lg px-3 py-1.5 bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                            <option value="6">Últimos 6 meses</option>
                            <option value="12" selected>Últimos 12 meses</option>
                            <option value="24">Últimos 24 meses</option>
                        </select>
                    </div>
                    <div class="h-72">
                        <canvas id="chartIngresos"></canvas>
                    </div>
                </div>
                
                <!-- Gráfico Estado de Pagos -->
                <div class="glass-card rounded-2xl p-6">
                    <h3 class="font-semibold text-gray-800 dark:text-white mb-4">
                        <i class="fas fa-pie-chart text-purple-500 mr-2"></i>
                        Estado de Pagos (Año)
                    </h3>
                    <div class="h-64 flex items-center justify-center">
                        <canvas id="chartEstadoPagos"></canvas>
                    </div>
                    <div class="mt-4 space-y-2">
                        <template x-for="(data, estado) in estadoPagos" :key="estado">
                            <div class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full" 
                                          :class="{
                                              'bg-green-500': estado === 'pagado',
                                              'bg-yellow-500': estado === 'pendiente',
                                              'bg-red-500': estado === 'atrasado'
                                          }"></span>
                                    <span class="text-gray-600 dark:text-gray-300 capitalize" x-text="estado"></span>
                                </span>
                                <span class="font-medium text-gray-800 dark:text-white">
                                    <span x-text="data.cantidad"></span> 
                                    <span class="text-gray-400 dark:text-gray-500">(₲ <span x-text="formatNumber(data.monto)"></span>)</span>
                                </span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Próximos a Vencer -->
            <div class="glass-card rounded-2xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-gray-800 dark:text-white">
                        <i class="fas fa-calendar-exclamation text-red-500 mr-2"></i>
                        Próximos a Vencer (Sin Pagar)
                    </h3>
                    <select x-model="diasVencer" @change="cargarProximosVencer()"
                            class="text-sm border rounded-lg px-3 py-1.5 bg-white dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                        <option value="3">Próximos 3 días</option>
                        <option value="7" selected>Próximos 7 días</option>
                        <option value="15">Próximos 15 días</option>
                        <option value="30">Próximos 30 días</option>
                    </select>
                </div>
                
                <div x-show="proximosVencer.length === 0" class="text-center py-8 text-gray-400 dark:text-gray-500">
                    <i class="fas fa-check-circle text-green-400 text-4xl mb-2"></i>
                    <p>No hay suscripciones por vencer sin pagar</p>
                </div>
                
                <div x-show="proximosVencer.length > 0" class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                <th class="pb-3 pl-2">Empresa</th>
                                <th class="pb-3">Factura</th>
                                <th class="pb-3">Vence</th>
                                <th class="pb-3">Días</th>
                                <th class="pb-3">Total</th>
                                <th class="pb-3">Estado</th>
                                <th class="pb-3">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <template x-for="item in proximosVencer" :key="item.id_suscripcion">
                                <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                                    <td class="py-3 pl-2">
                                        <p class="font-medium text-gray-800 dark:text-white" x-text="item.nombre_empresa"></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="item.ruc"></p>
                                    </td>
                                    <td class="py-3">
                                        <span class="text-blue-600 dark:text-blue-400 font-mono text-sm" x-text="item.nro_factura"></span>
                                    </td>
                                    <td class="py-3 text-sm text-gray-600 dark:text-gray-300" x-text="formatDate(item.periodo_fin)"></td>
                                    <td class="py-3">
                                        <span class="px-2 py-1 rounded-full text-xs font-bold"
                                              :class="{
                                                  'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': item.dias_restantes <= 1,
                                                  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400': item.dias_restantes > 1 && item.dias_restantes <= 3,
                                                  'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400': item.dias_restantes > 3
                                              }"
                                              x-text="item.dias_restantes + 'd'">
                                        </span>
                                    </td>
                                    <td class="py-3 font-semibold text-gray-800 dark:text-white">
                                        ₲ <span x-text="formatNumber(item.total)"></span>
                                    </td>
                                    <td class="py-3">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium"
                                              :class="{
                                                  'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': item.estado === 'activa',
                                                  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400': item.estado === 'gracia'
                                              }"
                                              x-text="item.estado">
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        <button @click="enviarRecordatorio(item)" 
                                                class="text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 text-sm">
                                            <i class="fas fa-envelope mr-1"></i> Recordar
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Resumen Rápido -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Acciones Rápidas -->
                <div class="glass-card rounded-2xl p-6">
                    <h3 class="font-semibold text-gray-800 mb-4">
                        <i class="fas fa-bolt text-amber-500 mr-2"></i>
                        Acciones Rápidas
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <a href="suscripciones.php" 
                           class="p-4 bg-blue-50 hover:bg-blue-100 rounded-xl text-center transition-colors">
                            <i class="fas fa-file-invoice-dollar text-blue-600 text-2xl mb-2"></i>
                            <p class="text-sm font-medium text-gray-700">Gestionar Suscripciones</p>
                        </a>
                        <a href="suscripciones.php?tab=catalogo" 
                           class="p-4 bg-purple-50 hover:bg-purple-100 rounded-xl text-center transition-colors">
                            <i class="fas fa-cubes text-purple-600 text-2xl mb-2"></i>
                            <p class="text-sm font-medium text-gray-700">Catálogo Apps</p>
                        </a>
                        <button @click="ejecutarRenovacion()"
                                class="p-4 bg-green-50 hover:bg-green-100 rounded-xl text-center transition-colors">
                            <i class="fas fa-sync text-green-600 text-2xl mb-2"></i>
                            <p class="text-sm font-medium text-gray-700">Renovar Suscripciones</p>
                        </button>
                        <button @click="ejecutarVerificacion()"
                                class="p-4 bg-amber-50 hover:bg-amber-100 rounded-xl text-center transition-colors">
                            <i class="fas fa-search text-amber-600 text-2xl mb-2"></i>
                            <p class="text-sm font-medium text-gray-700">Verificar Vencimientos</p>
                        </button>
                    </div>
                </div>
                
                <!-- Info Cron Jobs -->
                <div class="glass-card rounded-2xl p-6">
                    <h3 class="font-semibold text-gray-800 mb-4">
                        <i class="fas fa-clock text-cyan-500 mr-2"></i>
                        Tareas Programadas
                    </h3>
                    <div class="space-y-4">
                        <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-xl">
                            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-redo text-blue-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">Renovación Mensual</p>
                                <p class="text-xs text-gray-500">Día 1 de cada mes a las 00:00</p>
                                <code class="text-xs bg-gray-200 px-2 py-0.5 rounded mt-1 inline-block">
                                    0 0 1 * * php cron/renovar_suscripciones.php
                                </code>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-xl">
                            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-bell text-amber-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800">Verificar Vencimientos</p>
                                <p class="text-xs text-gray-500">Diario a las 06:00</p>
                                <code class="text-xs bg-gray-200 px-2 py-0.5 rounded mt-1 inline-block">
                                    0 6 * * * php cron/verificar_vencimientos.php
                                </code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function dashboardApp() {
        return {
            cargando: false,
            kpis: {},
            ingresosMensuales: [],
            estadoPagos: {},
            proximosVencer: [],
            mesesGrafico: '12',
            diasVencer: '7',
            chartIngresos: null,
            chartEstadoPagos: null,
            
            async init() {
                this.cargando = true;
                await Promise.all([
                    this.cargarKPIs(),
                    this.cargarIngresosMensuales(),
                    this.cargarEstadoPagos(),
                    this.cargarProximosVencer()
                ]);
                this.cargando = false;
            },
            
            formatNumber(num) {
                return new Intl.NumberFormat('es-PY').format(num || 0);
            },
            
            formatDate(dateStr) {
                if (!dateStr) return '';
                const d = new Date(dateStr);
                return d.toLocaleDateString('es-PY', { day: '2-digit', month: '2-digit', year: 'numeric' });
            },
            
            async cargarKPIs() {
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=dashboard_kpis');
                    const data = await res.json();
                    if (data.success) this.kpis = data.data;
                } catch (e) {
                    console.error('Error cargando KPIs:', e);
                }
            },
            
            async cargarIngresosMensuales() {
                try {
                    const res = await fetch(`api/v1/suscripciones.php?action=dashboard_ingresos_mensuales&meses=${this.mesesGrafico}`);
                    const data = await res.json();
                    if (data.success) {
                        this.ingresosMensuales = data.data;
                        this.$nextTick(() => this.renderChartIngresos());
                    }
                } catch (e) {
                    console.error('Error cargando ingresos:', e);
                }
            },
            
            async cargarEstadoPagos() {
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=dashboard_estado_pagos');
                    const data = await res.json();
                    if (data.success) {
                        this.estadoPagos = data.data;
                        this.$nextTick(() => this.renderChartEstadoPagos());
                    }
                } catch (e) {
                    console.error('Error cargando estado pagos:', e);
                }
            },
            
            async cargarProximosVencer() {
                try {
                    const res = await fetch(`api/v1/suscripciones.php?action=dashboard_proximos_vencer&dias=${this.diasVencer}`);
                    const data = await res.json();
                    if (data.success) this.proximosVencer = data.data;
                } catch (e) {
                    console.error('Error cargando próximos a vencer:', e);
                }
            },
            
            renderChartIngresos() {
                const ctx = document.getElementById('chartIngresos');
                if (!ctx) return;
                
                if (this.chartIngresos) {
                    this.chartIngresos.destroy();
                }
                
                const labels = this.ingresosMensuales.map(d => d.mes_label);
                const ingresos = this.ingresosMensuales.map(d => parseFloat(d.ingresos));
                const pendiente = this.ingresosMensuales.map(d => parseFloat(d.pendiente));
                
                this.chartIngresos = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Cobrado',
                                data: ingresos,
                                backgroundColor: 'rgba(34, 197, 94, 0.7)',
                                borderColor: 'rgb(34, 197, 94)',
                                borderWidth: 1,
                                borderRadius: 4
                            },
                            {
                                label: 'Pendiente',
                                data: pendiente,
                                backgroundColor: 'rgba(251, 191, 36, 0.7)',
                                borderColor: 'rgb(251, 191, 36)',
                                borderWidth: 1,
                                borderRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' }
                        },
                        scales: {
                            x: { stacked: true },
                            y: { 
                                stacked: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₲ ' + new Intl.NumberFormat('es-PY').format(value / 1000000) + 'M';
                                    }
                                }
                            }
                        }
                    }
                });
            },
            
            renderChartEstadoPagos() {
                const ctx = document.getElementById('chartEstadoPagos');
                if (!ctx) return;
                
                if (this.chartEstadoPagos) {
                    this.chartEstadoPagos.destroy();
                }
                
                const labels = [];
                const data = [];
                const colors = [];
                
                const colorMap = {
                    'pagado': 'rgb(34, 197, 94)',
                    'pendiente': 'rgb(251, 191, 36)',
                    'atrasado': 'rgb(239, 68, 68)'
                };
                
                for (const [estado, info] of Object.entries(this.estadoPagos)) {
                    labels.push(estado.charAt(0).toUpperCase() + estado.slice(1));
                    data.push(info.cantidad);
                    colors.push(colorMap[estado] || 'rgb(156, 163, 175)');
                }
                
                this.chartEstadoPagos = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: colors,
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        cutout: '60%'
                    }
                });
            },
            
            async enviarRecordatorio(item) {
                alert(`Enviar recordatorio a ${item.nombre_empresa}\n\nEsta funcionalidad se implementará próximamente.`);
            },
            
            async ejecutarRenovacion() {
                if (!confirm('¿Ejecutar renovación de suscripciones del mes?')) return;
                alert('Ejecutando renovación...\n\nEsta funcionalidad ejecutará el cron job manualmente.');
            },
            
            async ejecutarVerificacion() {
                if (!confirm('¿Ejecutar verificación de vencimientos?')) return;
                alert('Ejecutando verificación...\n\nEsta funcionalidad ejecutará el cron job manualmente.');
            },
            
            salir() {
                // Intentar cerrar usando la función del padre (menu.php)
                try {
                    if (typeof parent.cerrarApp === 'function') {
                        parent.cerrarApp();
                        return;
                    }
                    // Fallback: Forzar recarga del menú principal
                    if (window.top && window.top.location) {
                        window.top.location.href = '/menu/menu.php';
                    } else {
                        window.location.href = '/menu/menu.php';
                    }
                } catch (e) {
                    console.error('Error al salir:', e);
                    window.location.href = '/menu/menu.php';
                }
            }
        };
    }
    </script>
</body>
</html>
