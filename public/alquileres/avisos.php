<?php
require_once __DIR__ . '/_module.php';
smxAlqRenderHead('Avisos');
smxAlqRenderTopbar('avisos', 'Avisos de Vencimiento', 'Programación y envío por WhatsApp con consentimiento');
?>

<div x-data="alqAvisos()" x-init="init()" class="grid gap-5 xl:grid-cols-[1fr_1fr]">
    <section class="alq-card-light p-5 dark:alq-card">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Facturas elegibles</h2>
            <span class="text-[11px] text-slate-500 dark:text-slate-400">Solo pendientes y vencidas</span>
        </div>
        <div class="space-y-3">
            <template x-for="row in facturas" :key="row.id_factura">
                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-slate-900 dark:text-white" x-text="row.nombre_razon + ' · ' + row.propiedad"></div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="'Contrato ' + row.numero_contrato + ' · Vence ' + row.fecha_vencimiento"></div>
                            <div class="mt-1 text-[11px]" :class="row.consentimiento_whatsapp == 1 ? 'text-teal-600 dark:text-teal-300' : 'text-rose-600 dark:text-rose-300'" x-text="row.consentimiento_whatsapp == 1 ? 'Consentimiento WhatsApp registrado' : 'Falta consentimiento WhatsApp'"></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[16px] text-rose-600" x-text="money(row.total, row.moneda)"></div>
                        </div>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button @click="schedule(row.id_factura, 3)" class="alq-btn border border-teal-500 text-teal-700 dark:text-teal-200">Programar 3 días antes</button>
                        <button @click="schedule(row.id_factura, 1)" class="alq-btn border border-amber-500 text-amber-700 dark:text-amber-200">Programar 1 día antes</button>
                    </div>
                </div>
            </template>
            <div x-show="!facturas.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">No hay facturas pendientes para avisar.</div>
        </div>
    </section>

    <section class="alq-card-light p-5 dark:alq-card">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Cola de avisos</h2>
            <span class="text-[11px] text-slate-500 dark:text-slate-400" x-text="rows.length + ' avisos'"></span>
        </div>
        <div class="space-y-3">
            <template x-for="row in rows" :key="row.id_aviso">
                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-slate-900 dark:text-white" x-text="row.nombre_razon + ' · ' + row.propiedad"></div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="'Programado: ' + row.programado_para"></div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="row.destinatario"></div>
                        </div>
                        <span class="alq-badge" :class="row.estado === 'enviado' ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200' : (row.estado === 'error' ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200')" x-text="row.estado"></span>
                    </div>
                    <div class="mt-3 rounded-xl bg-slate-50 p-3 text-[11px] text-slate-600 dark:bg-slate-900/60 dark:text-slate-300" x-text="row.mensaje"></div>
                    <div class="mt-3 flex gap-2" x-show="row.estado !== 'enviado'">
                        <button @click="sendNow(row.id_aviso)" class="alq-btn border border-teal-500 text-teal-700 dark:text-teal-200">Enviar ahora</button>
                    </div>
                </div>
            </template>
        </div>
    </section>
</div>

<script>
function alqAvisos() {
    return {
        rows: [],
        facturas: [],
        async init() { await this.load(); },
        async load() {
            const res = await fetch('/public/alquileres/api/avisos.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.rows = data.rows || [];
            this.facturas = data.facturas || [];
        },
        async schedule(idFactura, diasAntes) {
            const res = await fetch('/public/alquileres/api/avisos.php?action=schedule', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_factura: idFactura, dias_antes: diasAntes })
            });
            const data = await res.json();
            if (!data.ok) { alert(data.result?.error || data.error || 'No se pudo programar'); return; }
            await this.load();
        },
        async sendNow(idAviso) {
            const res = await fetch('/public/alquileres/api/avisos.php?action=send_now', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_aviso: idAviso })
            });
            const data = await res.json();
            if (!data.ok) { alert(data.result?.error || data.error || 'No se pudo enviar'); return; }
            await this.load();
        },
        money(v, currency) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: currency || 'PYG', maximumFractionDigits: 0 }).format(Number(v || 0));
        }
    };
}
</script>

<?php smxAlqRenderFoot(); ?>
