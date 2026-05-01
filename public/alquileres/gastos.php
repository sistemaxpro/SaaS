<?php
require_once __DIR__ . '/_module.php';
smxAlqRenderHead('Gastos');
smxAlqRenderTopbar('gastos', 'Gastos por Propiedad', 'Mantenimiento, servicios y cargos trasladables al inquilino');
?>

<div x-data="alqGastos()" x-init="init()" class="grid gap-5 xl:grid-cols-[430px_1fr]">
    <section class="alq-card-light p-5 dark:alq-card">
        <h2 class="text-[15px] text-slate-900 dark:text-white">Registrar gasto</h2>
        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Si el gasto debe refacturarse al inquilino, quedará pendiente para el periodo aplicable.</p>
        <div class="mt-4 space-y-3">
            <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Propiedad</label><select x-model="form.id_propiedad" class="alq-select mt-1"><option value="">Seleccione</option><template x-for="p in propiedades" :key="p.id_propiedad"><option :value="p.id_propiedad" x-text="p.codigo + ' · ' + p.nombre"></option></template></select></div>
            <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Contrato activo</label><select x-model="form.id_contrato" class="alq-select mt-1"><option value="">Sin contrato específico</option><template x-for="c in contratosFiltrados" :key="c.id_contrato"><option :value="c.id_contrato" x-text="c.numero_contrato + ' · ' + c.nombre_razon"></option></template></select></div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Fecha gasto</label><input x-model="form.fecha_gasto" type="date" class="alq-input mt-1 dark:[color-scheme:dark]"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Periodo aplicable</label><input x-model="form.periodo_aplicable" type="month" class="alq-input mt-1 dark:[color-scheme:dark]"></div>
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Categoría</label><select x-model="form.categoria" class="alq-select mt-1"><option>mantenimiento</option><option>expensas</option><option>servicio_publico</option><option>impuesto</option><option>reparacion</option><option>seguro</option><option>otro</option></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Pagado por</label><select x-model="form.pagado_por" class="alq-select mt-1"><option>propietario</option><option>inmobiliaria</option><option>inquilino</option></select></div>
            </div>
            <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Concepto</label><input x-model="form.concepto" class="alq-input mt-1"></div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Proveedor</label><input x-model="form.proveedor" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Comprobante</label><input x-model="form.comprobante" class="alq-input mt-1"></div>
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Moneda</label><select x-model="form.moneda" class="alq-select mt-1"><option>PYG</option><option>USD</option></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Monto</label><input x-model="form.monto" type="number" class="alq-input mt-1"></div>
            </div>
            <div class="rounded-2xl border border-slate-200 p-3 dark:border-slate-700">
                <label class="inline-flex items-center gap-2"><input x-model="form.trasladar_inquilino" type="checkbox" class="h-4 w-4 rounded"><span>Trasladar al inquilino en la facturación</span></label>
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Si se activa, se incluirá en la factura mensual del periodo aplicable y luego quedará marcado como facturado.</p>
            </div>
            <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Observación</label><textarea x-model="form.observacion" rows="3" class="alq-textarea mt-1"></textarea></div>
            <div class="flex gap-2">
                <button @click="save()" class="alq-btn bg-teal-600 text-white">Guardar</button>
                <button @click="reset()" class="alq-btn border border-slate-300 dark:border-slate-700">Limpiar</button>
            </div>
        </div>
    </section>

    <section class="space-y-5">
        <div class="grid gap-4 md:grid-cols-3">
            <div class="alq-card-light p-4 dark:alq-card"><div class="text-[11px] text-slate-500 dark:text-slate-400">Cantidad</div><div class="mt-2 text-[22px] text-slate-900 dark:text-white" x-text="stats.cantidad || 0"></div></div>
            <div class="alq-card-light p-4 dark:alq-card"><div class="text-[11px] text-slate-500 dark:text-slate-400">Monto total</div><div class="mt-2 text-[22px] text-slate-900 dark:text-white" x-text="money(stats.monto_total || 0)"></div></div>
            <div class="alq-card-light p-4 dark:alq-card"><div class="text-[11px] text-slate-500 dark:text-slate-400">Pendiente traslado</div><div class="mt-2 text-[22px] text-amber-600" x-text="money(stats.pendiente_traslado || 0)"></div></div>
        </div>

        <section class="alq-card-light p-5 dark:alq-card">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-[15px] text-slate-900 dark:text-white">Gastos registrados</h2>
                <span class="text-[11px] text-slate-500 dark:text-slate-400" x-text="rows.length + ' registros'"></span>
            </div>
            <div class="space-y-3">
                <template x-for="row in rows" :key="row.id_gasto">
                    <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-slate-900 dark:text-white" x-text="row.concepto"></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.propiedad + (row.numero_contrato ? ' · ' + row.numero_contrato : '')"></div>
                                <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="row.fecha_gasto + ' · Periodo ' + row.periodo_aplicable + ' · ' + row.categoria"></div>
                            </div>
                            <div class="text-right">
                                <div class="text-[16px] text-rose-600" x-text="money(row.monto, row.moneda)"></div>
                                <div class="mt-1 flex gap-2 justify-end">
                                    <span class="alq-badge bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200" x-text="row.pagado_por"></span>
                                    <span class="alq-badge" :class="row.trasladar_inquilino == 1 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200'" x-text="row.trasladar_inquilino == 1 ? row.estado_facturacion : 'No trasladable'"></span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400" x-text="row.proveedor ? ('Proveedor: ' + row.proveedor + (row.comprobante ? ' · Comp. ' + row.comprobante : '')) : (row.comprobante ? ('Comp. ' + row.comprobante) : '')"></div>
                        <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400" x-show="row.factura_periodo" x-text="'Facturado en periodo ' + row.factura_periodo"></div>
                        <div class="mt-3 flex justify-end">
                            <button @click="edit(row)" class="alq-btn border border-teal-500 text-teal-700 dark:text-teal-200">Editar</button>
                        </div>
                    </div>
                </template>
                <div x-show="!rows.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">Sin gastos por propiedad cargados.</div>
            </div>
        </section>
    </section>
</div>

<script>
function alqGastos() {
    return {
        propiedades: [],
        contratos: [],
        rows: [],
        stats: { cantidad: 0, monto_total: 0, pendiente_traslado: 0 },
        form: { id_gasto: 0, id_propiedad: '', id_contrato: '', fecha_gasto: new Date().toISOString().slice(0, 10), periodo_aplicable: new Date().toISOString().slice(0, 7), categoria: 'mantenimiento', concepto: '', proveedor: '', comprobante: '', moneda: 'PYG', monto: '', pagado_por: 'propietario', trasladar_inquilino: false, observacion: '' },
        get contratosFiltrados() {
            return (this.contratos || []).filter(c => String(c.id_propiedad) === String(this.form.id_propiedad));
        },
        async init() { await this.loadCatalogs(); await this.load(); },
        async loadCatalogs() {
            const res = await fetch('/public/alquileres/api/gastos.php?action=catalogs', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.propiedades = data.propiedades || [];
            this.contratos = data.contratos || [];
        },
        async load() {
            const res = await fetch('/public/alquileres/api/gastos.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.rows = data.rows || [];
            this.stats = data.stats || this.stats;
        },
        edit(row) {
            this.form = { ...row, trasladar_inquilino: Number(row.trasladar_inquilino || 0) === 1 };
        },
        reset() {
            this.form = { id_gasto: 0, id_propiedad: '', id_contrato: '', fecha_gasto: new Date().toISOString().slice(0, 10), periodo_aplicable: new Date().toISOString().slice(0, 7), categoria: 'mantenimiento', concepto: '', proveedor: '', comprobante: '', moneda: 'PYG', monto: '', pagado_por: 'propietario', trasladar_inquilino: false, observacion: '' };
        },
        async save() {
            const payload = { ...this.form, trasladar_inquilino: this.form.trasladar_inquilino ? 1 : 0 };
            const res = await fetch('/public/alquileres/api/gastos.php?action=save', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.ok) { alert(data.error || 'No se pudo guardar'); return; }
            this.reset();
            await this.load();
        },
        money(v, currency) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: currency || 'PYG', maximumFractionDigits: 0 }).format(Number(v || 0));
        }
    };
}
</script>

<?php smxAlqRenderFoot(); ?>
