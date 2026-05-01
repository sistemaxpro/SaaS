<?php
require_once __DIR__ . '/_module.php';
smxAlqRenderHead('Facturación');
smxAlqRenderTopbar('facturacion', 'Facturación Mensual y FE', 'Generación periódica, estado de cobro y preparación SIFEN');
?>

<div x-data="alqFacturacion()" x-init="init()" class="space-y-5">
    <section class="alq-card-light p-5 dark:alq-card">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-[11px] text-slate-500 dark:text-slate-400">Periodo</label>
                <input x-model="periodo" type="month" class="alq-input mt-1 dark:[color-scheme:dark]">
            </div>
            <button @click="generateMonth()" class="alq-btn bg-teal-600 text-white">Generar facturas del periodo</button>
            <div class="text-[11px] text-slate-500 dark:text-slate-400">La factura electrónica se deja en estado preparada con payload, lista para integrar al circuito DNIT/SIFEN.</div>
        </div>
    </section>

    <section class="alq-card-light p-5 dark:alq-card">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Facturas</h2>
            <span class="text-[11px] text-slate-500 dark:text-slate-400" x-text="rows.length + ' registros'"></span>
        </div>
        <div class="space-y-3">
            <template x-for="row in rows" :key="row.id_factura">
                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-slate-900 dark:text-white" x-text="row.nombre_razon + ' · ' + row.propiedad"></div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="'Contrato ' + row.numero_contrato + ' · Periodo ' + row.periodo"></div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="'Emisión ' + row.fecha_emision + ' · Vence ' + row.fecha_vencimiento"></div>
                            <div class="mt-1 text-[11px] text-amber-600 dark:text-amber-300" x-show="Number(row.monto_gastos || 0) > 0" x-text="'Incluye gastos trasladados: ' + money(row.monto_gastos, row.moneda)"></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[16px] text-rose-600" x-text="money(row.total, row.moneda)"></div>
                            <div class="mt-1 flex gap-2">
                                <span class="alq-badge bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200" x-text="row.estado"></span>
                                <span class="alq-badge bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200" x-text="'FE ' + row.fe_estado"></span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button @click="prepareFe(row.id_factura)" class="alq-btn border border-teal-500 text-teal-700 dark:text-teal-200">Preparar FE</button>
                        <button @click="markPaid(row.id_factura)" class="alq-btn border border-sky-500 text-sky-700 dark:text-sky-200">Marcar pagada</button>
                        <button x-show="row.fe_payload_json" @click="showPayload(row.fe_payload_json)" class="alq-btn border border-amber-500 text-amber-700 dark:text-amber-200">Ver payload</button>
                    </div>
                </div>
            </template>
            <div x-show="!rows.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">Sin facturas emitidas todavía.</div>
        </div>
    </section>

    <div x-show="payloadVisible" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/65 p-4">
        <div class="alq-card-light max-h-[80vh] w-full max-w-4xl overflow-auto p-5 dark:alq-card">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-[15px] text-slate-900 dark:text-white">Payload FE preparado</h2>
                <button @click="payloadVisible=false" class="alq-btn border border-slate-300 dark:border-slate-700">Cerrar</button>
            </div>
            <pre class="overflow-auto rounded-2xl bg-slate-950 p-4 text-[11px] text-emerald-200" x-text="payloadContent"></pre>
        </div>
    </div>
</div>

<script>
function alqFacturacion() {
    return {
        periodo: new Date().toISOString().slice(0, 7),
        rows: [],
        payloadVisible: false,
        payloadContent: '',
        async init() { await this.load(); },
        async load() {
            const res = await fetch('/public/alquileres/api/facturacion.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.rows = data.rows || [];
        },
        async generateMonth() {
            const res = await fetch('/public/alquileres/api/facturacion.php?action=generate_month', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ periodo: this.periodo })
            });
            const data = await res.json();
            if (!data.ok) { alert(data.error || 'No se pudo generar'); return; }
            await this.load();
        },
        async prepareFe(idFactura) {
            const res = await fetch('/public/alquileres/api/facturacion.php?action=prepare_fe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_factura: idFactura })
            });
            const data = await res.json();
            if (!data.ok) { alert(data.error || 'No se pudo preparar FE'); return; }
            this.showPayload(JSON.stringify(data.payload, null, 2));
            await this.load();
        },
        async markPaid(idFactura) {
            const res = await fetch('/public/alquileres/api/facturacion.php?action=mark_paid', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_factura: idFactura })
            });
            const data = await res.json();
            if (!data.ok) { alert(data.error || 'No se pudo marcar'); return; }
            await this.load();
        },
        showPayload(payload) {
            this.payloadContent = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
            this.payloadVisible = true;
        },
        money(v, currency) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: currency || 'PYG', maximumFractionDigits: 0 }).format(Number(v || 0));
        }
    };
}
</script>

<?php smxAlqRenderFoot(); ?>
