<?php
require_once __DIR__ . '/_module.php';
smxAlqRenderHead('Propiedades');
smxAlqRenderTopbar('propiedades', 'Propiedades y Unidades', 'Inventario locativo de la inmobiliaria');
?>

<div x-data="alqPropiedades()" x-init="init()" class="grid gap-5 xl:grid-cols-[420px_1fr]">
    <section class="alq-card-light p-5 dark:alq-card">
        <h2 class="text-[15px] text-slate-900 dark:text-white">Nueva propiedad</h2>
        <div class="mt-4 space-y-3">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Código</label><input x-model="form.codigo" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Nombre</label><input x-model="form.nombre" class="alq-input mt-1"></div>
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Tipo</label><select x-model="form.tipo_inmueble" class="alq-select mt-1"><option>departamento</option><option>casa</option><option>local</option><option>oficina</option><option>galpon</option></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Ciudad</label><input x-model="form.ciudad" class="alq-input mt-1"></div>
            </div>
            <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Dirección</label><input x-model="form.direccion" class="alq-input mt-1"></div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Matrícula</label><input x-model="form.matricula" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Padrón</label><input x-model="form.padron" class="alq-input mt-1"></div>
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Canon mensual</label><input x-model="form.canon_mensual" type="number" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Depósito</label><input x-model="form.deposito_garantia" type="number" class="alq-input mt-1"></div>
            </div>
            <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-1">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Moneda</label><select x-model="form.moneda" class="alq-select mt-1"><option>PYG</option><option>USD</option></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Vence día</label><input x-model="form.dia_vencimiento" type="number" min="1" max="28" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Estado</label><select x-model="form.estado" class="alq-select mt-1"><option>disponible</option><option>alquilado</option><option>mantenimiento</option></select></div>
            </div>
            <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Observación</label><textarea x-model="form.observacion" rows="3" class="alq-textarea mt-1"></textarea></div>
            <div class="flex gap-2">
                <button @click="save()" class="alq-btn bg-teal-600 text-white">Guardar</button>
                <button @click="reset()" class="alq-btn border border-slate-300 dark:border-slate-700">Limpiar</button>
            </div>
        </div>
    </section>

    <section class="alq-card-light p-5 dark:alq-card">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Listado</h2>
            <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="rows.length + ' unidades'"></div>
        </div>
        <div class="grid gap-3 lg:grid-cols-2">
            <template x-for="row in rows" :key="row.id_propiedad">
                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="mb-3 overflow-hidden rounded-2xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-900">
                        <div class="aspect-[16/9] bg-slate-100 dark:bg-slate-900">
                            <img x-show="row.imagen_principal" :src="row.imagen_principal" class="h-full w-full object-cover" alt="">
                            <div x-show="!row.imagen_principal" class="flex h-full items-center justify-center text-[11px] text-slate-400 dark:text-slate-500">
                                Sin imagen principal
                            </div>
                        </div>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-slate-900 dark:text-white" x-text="row.nombre"></div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.codigo + ' · ' + row.tipo_inmueble"></div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="row.direccion || row.ciudad"></div>
                        </div>
                        <span class="alq-badge bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200" x-text="row.estado"></span>
                    </div>
                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-900/60">
                            <div class="text-[11px] text-slate-400">Canon</div>
                            <div x-text="money(row.canon_mensual, row.moneda)"></div>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-900/60">
                            <div class="text-[11px] text-slate-400">Depósito</div>
                            <div x-text="money(row.deposito_garantia, row.moneda)"></div>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="(row.imagenes_count || 0) + ' imagen(es)'"></div>
                        <div class="flex gap-2">
                            <a :href="'/public/alquileres/imagenes.php?id_propiedad=' + row.id_propiedad" class="alq-btn border border-slate-300 dark:border-slate-700">Imágenes</a>
                            <button @click="edit(row)" class="alq-btn border border-teal-500 text-teal-700 dark:text-teal-200">Editar</button>
                        </div>
                    </div>
                </div>
            </template>
            <div x-show="!rows.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">Sin propiedades registradas.</div>
        </div>
    </section>
</div>

<script>
function alqPropiedades() {
    return {
        rows: [],
        form: { id_propiedad: 0, codigo: '', nombre: '', tipo_inmueble: 'departamento', direccion: '', ciudad: '', matricula: '', padron: '', canon_mensual: '', deposito_garantia: '', moneda: 'PYG', dia_vencimiento: 10, estado: 'disponible', observacion: '' },
        async init() { await this.load(); },
        async load() {
            const res = await fetch('/public/alquileres/api/propiedades.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.rows = data.rows || [];
        },
        edit(row) { this.form = { ...row }; },
        reset() { this.form = { id_propiedad: 0, codigo: '', nombre: '', tipo_inmueble: 'departamento', direccion: '', ciudad: '', matricula: '', padron: '', canon_mensual: '', deposito_garantia: '', moneda: 'PYG', dia_vencimiento: 10, estado: 'disponible', observacion: '' }; },
        async save() {
            const res = await fetch('/public/alquileres/api/propiedades.php?action=save', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(this.form)
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
