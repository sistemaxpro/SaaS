<?php
require_once __DIR__ . '/_module.php';
smxAlqRenderHead('Contratos');
smxAlqRenderTopbar('contratos', 'Contratos Privados', 'Inquilinos, contratos y documento imprimible');
?>

<div x-data="alqContratos()" x-init="init()" class="space-y-5">
    <div class="grid gap-5 xl:grid-cols-2">
        <section class="alq-card-light p-5 dark:alq-card">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-[15px] text-slate-900 dark:text-white">Inquilino</h2>
                <span class="text-[11px] text-slate-500 dark:text-slate-400">Consentimiento WhatsApp obligatorio para avisos</span>
            </div>
            <div class="grid gap-3 md:grid-cols-2">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Tipo persona</label><select x-model="tenant.tipo_persona" class="alq-select mt-1"><option>fisica</option><option>juridica</option></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Nombre / Razón social</label><input x-model="tenant.nombre_razon" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Documento</label><input x-model="tenant.documento" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">RUC</label><input x-model="tenant.ruc" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Teléfono</label><input x-model="tenant.telefono" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">WhatsApp</label><input x-model="tenant.whatsapp" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Email</label><input x-model="tenant.email" class="alq-input mt-1"></div>
                <div class="flex items-end"><label class="inline-flex items-center gap-2"><input x-model="tenant.consentimiento_whatsapp" type="checkbox" class="h-4 w-4 rounded"><span>Acepta avisos por WhatsApp</span></label></div>
                <div class="md:col-span-2"><label class="text-[11px] text-slate-500 dark:text-slate-400">Domicilio</label><input x-model="tenant.domicilio" class="alq-input mt-1"></div>
            </div>
            <div class="mt-3 flex gap-2">
                <button @click="saveTenant()" class="alq-btn bg-teal-600 text-white">Guardar inquilino</button>
                <button @click="resetTenant()" class="alq-btn border border-slate-300 dark:border-slate-700">Limpiar</button>
            </div>
        </section>

        <section class="alq-card-light p-5 dark:alq-card">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-[15px] text-slate-900 dark:text-white">Contrato</h2>
                <a x-show="contract.id_contrato" :href="'/public/alquileres/documento.php?id_contrato=' + contract.id_contrato" target="_blank" rel="noopener" class="alq-btn border border-amber-500 text-amber-700 dark:text-amber-200">Imprimir contrato</a>
            </div>
            <div class="grid gap-3 md:grid-cols-2">
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">N° contrato</label><input x-model="contract.numero_contrato" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Propiedad</label><select x-model="contract.id_propiedad" @change="syncFromProperty()" class="alq-select mt-1"><option value="">Seleccione</option><template x-for="p in propiedades" :key="p.id_propiedad"><option :value="p.id_propiedad" x-text="p.codigo + ' · ' + p.nombre"></option></template></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Inquilino</label><select x-model="contract.id_inquilino" class="alq-select mt-1"><option value="">Seleccione</option><template x-for="i in inquilinos" :key="i.id_inquilino"><option :value="i.id_inquilino" x-text="i.nombre_razon"></option></template></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Destino</label><select x-model="contract.destino_inmueble" class="alq-select mt-1"><option>vivienda</option><option>comercial</option><option>mixto</option></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Fecha firma</label><input x-model="contract.fecha_firma" type="date" class="alq-input mt-1 dark:[color-scheme:dark]"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Inicio</label><input x-model="contract.fecha_inicio" type="date" class="alq-input mt-1 dark:[color-scheme:dark]"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Fin</label><input x-model="contract.fecha_fin" type="date" class="alq-input mt-1 dark:[color-scheme:dark]"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Moneda</label><select x-model="contract.moneda" class="alq-select mt-1"><option>PYG</option><option>USD</option></select></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Canon mensual</label><input x-model="contract.canon_mensual" type="number" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Expensas</label><input x-model="contract.expensas_mensuales" type="number" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Servicios</label><input x-model="contract.servicios_mensuales" type="number" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Depósito</label><input x-model="contract.deposito_garantia" type="number" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Vence día</label><input x-model="contract.dia_vencimiento" type="number" min="1" max="28" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Mora fija</label><input x-model="contract.mora_fija" type="number" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Firmado en</label><input x-model="contract.firmado_en" class="alq-input mt-1"></div>
                <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Estado</label><select x-model="contract.estado" class="alq-select mt-1"><option>activo</option><option>suspendido</option><option>finalizado</option></select></div>
                <div class="md:col-span-2"><label class="text-[11px] text-slate-500 dark:text-slate-400">Observación</label><textarea x-model="contract.observacion" rows="2" class="alq-textarea mt-1"></textarea></div>
            </div>
            <div class="mt-3 flex gap-2">
                <button @click="saveContract()" class="alq-btn bg-teal-600 text-white">Guardar contrato</button>
                <button @click="resetContract()" class="alq-btn border border-slate-300 dark:border-slate-700">Nuevo</button>
            </div>
        </section>
    </div>

    <section class="alq-card-light p-5 dark:alq-card">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Cláusulas base del contrato privado</h2>
            <span class="text-[11px] text-slate-500 dark:text-slate-400">Editables antes de guardar o imprimir</span>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
            <template x-for="(value, key) in clauses" :key="key">
                <div class="rounded-2xl border border-slate-200 p-3 dark:border-slate-700">
                    <div class="mb-1 text-[11px] text-slate-400" x-text="key"></div>
                    <textarea x-model="clauses[key]" rows="4" class="alq-textarea"></textarea>
                </div>
            </template>
        </div>
    </section>

    <section class="alq-card-light p-5 dark:alq-card">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Contratos registrados</h2>
            <span class="text-[11px] text-slate-500 dark:text-slate-400" x-text="rows.length + ' contratos'"></span>
        </div>
        <div class="grid gap-3 lg:grid-cols-2">
            <template x-for="row in rows" :key="row.id_contrato">
                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-slate-900 dark:text-white" x-text="row.numero_contrato"></div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.nombre_razon + ' · ' + row.nombre"></div>
                            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="row.fecha_inicio + ' a ' + row.fecha_fin"></div>
                        </div>
                        <span class="alq-badge bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200" x-text="row.estado"></span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button @click="editContract(row)" class="alq-btn border border-teal-500 text-teal-700 dark:text-teal-200">Editar</button>
                        <a :href="'/public/alquileres/documento.php?id_contrato=' + row.id_contrato" target="_blank" rel="noopener" class="alq-btn border border-amber-500 text-amber-700 dark:text-amber-200">Imprimir</a>
                    </div>
                </div>
            </template>
            <div x-show="!rows.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">Sin contratos cargados.</div>
        </div>
    </section>
</div>

<script>
function alqContratos() {
    return {
        propiedades: [],
        inquilinos: [],
        rows: [],
        tenant: { id_inquilino: 0, tipo_persona: 'fisica', nombre_razon: '', documento: '', ruc: '', telefono: '', email: '', whatsapp: '', domicilio: '', consentimiento_whatsapp: false, observacion: '' },
        contract: { id_contrato: 0, numero_contrato: '', id_propiedad: '', id_inquilino: '', fecha_firma: new Date().toISOString().slice(0, 10), fecha_inicio: new Date().toISOString().slice(0, 10), fecha_fin: '', destino_inmueble: 'vivienda', moneda: 'PYG', canon_mensual: '', expensas_mensuales: 0, servicios_mensuales: 0, deposito_garantia: '', dia_vencimiento: 10, mora_fija: 0, firmado_en: 'Asunción', estado: 'activo', observacion: '' },
        clauses: {},
        async init() { await this.loadCatalogs(); await this.loadRows(); this.resetClauses(); },
        async loadCatalogs() {
            const res = await fetch('/public/alquileres/api/contratos.php?action=catalogs', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.propiedades = data.propiedades || [];
            this.inquilinos = data.inquilinos || [];
        },
        async loadRows() {
            const res = await fetch('/public/alquileres/api/contratos.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.rows = data.rows || [];
        },
        resetTenant() { this.tenant = { id_inquilino: 0, tipo_persona: 'fisica', nombre_razon: '', documento: '', ruc: '', telefono: '', email: '', whatsapp: '', domicilio: '', consentimiento_whatsapp: false, observacion: '' }; },
        resetContract() {
            this.contract = { id_contrato: 0, numero_contrato: '', id_propiedad: '', id_inquilino: '', fecha_firma: new Date().toISOString().slice(0, 10), fecha_inicio: new Date().toISOString().slice(0, 10), fecha_fin: '', destino_inmueble: 'vivienda', moneda: 'PYG', canon_mensual: '', expensas_mensuales: 0, servicios_mensuales: 0, deposito_garantia: '', dia_vencimiento: 10, mora_fija: 0, firmado_en: 'Asunción', estado: 'activo', observacion: '' };
            this.resetClauses();
        },
        resetClauses() {
            const canon = Number(this.contract.canon_mensual || 0).toLocaleString('es-PY');
            const dep = Number(this.contract.deposito_garantia || 0).toLocaleString('es-PY');
            const dia = this.contract.dia_vencimiento || 10;
            const moneda = this.contract.moneda || 'PYG';
            this.clauses = {
                objeto: 'El inmueble se entrega para el uso pactado, quedando vedado cualquier destino distinto sin autorización escrita.',
                plazo: 'La locación tendrá vigencia durante el plazo indicado y su renovación requerirá acuerdo expreso.',
                canon: `El canon locativo mensual asciende a ${canon} ${moneda}, pagadero hasta el día ${dia} de cada mes.`,
                deposito: `Se entrega depósito de garantía por ${dep} ${moneda}, sujeto a compensación de daños y saldos pendientes.`,
                mora: 'El atraso produce mora automática, habilita recargos y gestión de cobro.',
                subarriendo: 'Se prohíbe subarrendar, ceder o transferir la tenencia sin conformidad escrita.',
                conservacion: 'El inquilino mantendrá el inmueble en condiciones adecuadas y responderá por deterioros imputables.',
                notificaciones: 'Las notificaciones podrán practicarse al domicilio contractual y por WhatsApp si existe consentimiento expreso.',
                datos: 'El inquilino autoriza el tratamiento de sus datos para administración del contrato, cobranzas, facturación y cumplimiento legal.',
                jurisdiccion: 'Las partes se someten a la jurisdicción paraguaya competente.',
            };
        },
        syncFromProperty() {
            const prop = this.propiedades.find((item) => String(item.id_propiedad) === String(this.contract.id_propiedad));
            if (!prop) return;
            this.contract.moneda = prop.moneda || 'PYG';
            this.contract.canon_mensual = prop.canon_mensual || 0;
            this.contract.deposito_garantia = prop.deposito_garantia || 0;
            this.contract.dia_vencimiento = prop.dia_vencimiento || 10;
            this.contract.mora_fija = prop.mora_diaria || 0;
            this.resetClauses();
        },
        editContract(row) {
            this.contract = { ...row };
            this.clauses = row.clausulas || {};
        },
        async saveTenant() {
            const payload = { ...this.tenant, consentimiento_whatsapp: this.tenant.consentimiento_whatsapp ? 1 : 0 };
            const res = await fetch('/public/alquileres/api/contratos.php?action=save_tenant', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.ok) { alert(data.error || 'No se pudo guardar el inquilino'); return; }
            this.contract.id_inquilino = data.id_inquilino;
            await this.loadCatalogs();
            this.resetTenant();
        },
        async saveContract() {
            const payload = { ...this.contract, clausulas: this.clauses };
            const res = await fetch('/public/alquileres/api/contratos.php?action=save_contract', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.ok) { alert(data.error || 'No se pudo guardar el contrato'); return; }
            this.contract.id_contrato = data.id_contrato;
            await this.loadRows();
            alert('Contrato guardado');
        }
    };
}
</script>

<?php smxAlqRenderFoot(); ?>
