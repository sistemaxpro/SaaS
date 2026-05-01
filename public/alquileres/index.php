<?php
require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');

// ============================================================
// REDIRECT TO SPA (Reactive pattern with instant tab changes)
// ============================================================
// Use ?legacy=1 to access old full-page navigation version
if (empty($_GET['legacy'])) {
    header('Location: /public/alquileres/index-spa.php');
    exit;
}

// ============================================================
// LEGACY CODE (Full-page navigation - kept for reference)
// ============================================================
require_once __DIR__ . '/_module.php';
smxAlqRenderHead('Alquileres');
smxAlqRenderTopbar('dashboard', 'Gestión de Alquileres', 'Panel operativo, legal y de cobranzas');
?>

<div x-data="alqDashboard()" x-init="init()" class="space-y-5">
    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <template x-for="card in cards" :key="card.label">
            <div class="alq-card-light p-4 dark:alq-card">
                <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="card.label"></div>
                <div class="mt-2 text-[22px] text-slate-900 dark:text-white" x-text="card.value"></div>
                <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400" x-text="card.help"></div>
            </div>
        </template>
    </section>

    <section class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
        <div class="alq-card-light p-5 dark:alq-card">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-[15px] text-slate-900 dark:text-white">Próximos vencimientos</h2>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">Facturas pendientes y vencidas para seguimiento diario.</p>
                </div>
                <a href="/public/alquileres/facturacion.php" class="alq-btn border border-teal-500 bg-teal-600 text-white">Abrir facturación</a>
            </div>
            <div class="space-y-3">
                <template x-for="row in vencimientos" :key="row.id_factura">
                    <div class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-slate-900 dark:text-white" x-text="row.nombre_razon"></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.propiedad + ' · Contrato ' + row.numero_contrato"></div>
                                <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="'Vence: ' + row.fecha_vencimiento + ' · Periodo ' + row.periodo"></div>
                            </div>
                            <div class="text-right">
                                <div class="text-[15px] text-rose-600" x-text="money(row.total)"></div>
                                <span class="alq-badge" :class="row.estado === 'vencida' ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200'" x-text="row.estado"></span>
                            </div>
                        </div>
                    </div>
                </template>
                <div x-show="!vencimientos.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">Sin vencimientos cargados.</div>
            </div>
        </div>

        <div class="space-y-5">
            <div class="alq-card-light p-5 dark:alq-card">
                <h2 class="text-[15px] text-slate-900 dark:text-white">Contratos recientes</h2>
                <div class="mt-4 space-y-3">
                    <template x-for="row in contratos" :key="row.id_contrato">
                        <div class="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div x-text="row.numero_contrato"></div>
                                    <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.nombre_razon + ' · ' + row.propiedad"></div>
                                </div>
                                <span class="alq-badge bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200" x-text="row.estado"></span>
                            </div>
                            <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400" x-text="row.fecha_inicio + ' a ' + row.fecha_fin"></div>
                        </div>
                    </template>
                    <div x-show="!contratos.length" class="text-[11px] text-slate-400">Todavía no hay contratos cargados.</div>
                </div>
            </div>

            <?php smxAlqRenderLegalBox(); ?>

            <div class="alq-card-light p-5 dark:alq-card">
                <h2 class="text-[15px] text-slate-900 dark:text-white">Fuentes oficiales</h2>
                <div class="mt-3 grid gap-2">
                    <template x-for="link in legalLinks" :key="link.url">
                        <a :href="link.url" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-3 py-2 text-[11px] text-slate-600 hover:border-teal-500 hover:text-teal-700 dark:border-slate-700 dark:text-slate-300 dark:hover:border-teal-400 dark:hover:text-teal-200">
                            <i class="fas fa-arrow-up-right-from-square"></i>
                            <span x-text="link.label"></span>
                        </a>
                    </template>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
function alqDashboard() {
    return {
        cards: [
            { label: 'Propiedades', value: '0', help: 'Unidades administradas' },
            { label: 'Contratos activos', value: '0', help: 'Locaciones vigentes' },
            { label: 'Facturas pendientes', value: '0', help: 'Cobros por gestionar' },
            { label: 'Monto pendiente', value: 'Gs 0', help: 'Exposición actual de cobranza' },
            { label: 'Gastos pendientes', value: 'Gs 0', help: 'Gastos trasladables aún no facturados' },
        ],
        vencimientos: [],
        contratos: [],
        legalLinks: [],
        async init() {
            const res = await fetch('/public/alquileres/api/dashboard.php', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            if (!data.ok) return;
            this.cards[0].value = String(data.stats.propiedades || 0);
            this.cards[1].value = String(data.stats.contratos_activos || 0);
            this.cards[2].value = String(data.stats.facturas_pendientes || 0);
            this.cards[3].value = this.money(data.stats.monto_pendiente || 0);
            this.cards[4].value = this.money(data.stats.gastos_pendientes_traslado || 0);
            this.vencimientos = data.vencimientos || [];
            this.contratos = data.contratos || [];
            this.legalLinks = [
                { label: 'Código Civil - Locación', url: data.legal.civil_code },
                { label: 'Ley 5638/2016 Vivienda', url: data.legal.vivienda },
                { label: 'Ley 7593/2025 Datos Personales', url: data.legal.datos },
                { label: 'DNIT e-Kuatia', url: data.legal.ekuatia },
            ];
        },
        money(value) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: 'PYG', maximumFractionDigits: 0 }).format(Number(value || 0));
        }
    };
}
</script>

<?php smxAlqRenderFoot(); ?>
