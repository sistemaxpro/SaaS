<?php
require_once __DIR__ . '/_module.php';
smxAlqRenderSpaHead('Alquileres SPA');
smxAlqRenderSpaNav();
?>

<!-- CONTENEDOR REACTIVO PRINCIPAL -->
<div x-data="alqSpaManager()" x-init="init()" x-cloak class="space-y-5">

    <!-- ============================================================ -->
    <!-- TAB: DASHBOARD -->
    <!-- ============================================================ -->
    <template x-if="activeTab === 'dashboard'">
        <div class="space-y-5">
            <!-- Cards estadísticas -->
            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <template x-for="card in dashboard.cards" :key="card.label">
                    <div class="alq-card-light p-4 dark:alq-card">
                        <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="card.label"></div>
                        <div class="mt-2 text-[22px] text-slate-900 dark:text-white" x-text="card.value"></div>
                        <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400" x-text="card.help"></div>
                    </div>
                </template>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
                <!-- Próximos vencimientos -->
                <div class="alq-card-light p-5 dark:alq-card">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2 class="text-[15px] text-slate-900 dark:text-white">Próximos vencimientos</h2>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">Facturas pendientes y vencidas para seguimiento diario.</p>
                        </div>
                        <button @click="setTab('facturacion')" class="alq-btn border border-teal-500 bg-teal-600 text-white">Abrir facturación</button>
                    </div>
                    <div class="space-y-3">
                        <template x-for="row in dashboard.vencimientos" :key="row.id_factura">
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
                        <div x-show="!dashboard.vencimientos.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">Sin vencimientos cargados.</div>
                    </div>
                </div>

                <!-- Contratos recientes y legal -->
                <div class="space-y-5">
                    <div class="alq-card-light p-5 dark:alq-card">
                        <h2 class="text-[15px] text-slate-900 dark:text-white">Contratos recientes</h2>
                        <div class="mt-4 space-y-3">
                            <template x-for="row in dashboard.contratos" :key="row.id_contrato">
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
                            <div x-show="!dashboard.contratos.length" class="text-[11px] text-slate-400">Todavía no hay contratos cargados.</div>
                        </div>
                    </div>

                    <?php smxAlqRenderLegalBox(); ?>

                    <div class="alq-card-light p-5 dark:alq-card">
                        <h2 class="text-[15px] text-slate-900 dark:text-white">Fuentes oficiales</h2>
                        <div class="mt-3 grid gap-2">
                            <template x-for="link in dashboard.legalLinks" :key="link.url">
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
    </template>

    <!-- ============================================================ -->
    <!-- TAB: PROPIEDADES -->
    <!-- ============================================================ -->
    <template x-if="activeTab === 'propiedades'">
        <div class="grid gap-5 xl:grid-cols-[420px_1fr]">
            <section class="alq-card-light p-5 dark:alq-card">
                <h2 class="text-[15px] text-slate-900 dark:text-white">Nueva propiedad</h2>
                <div class="mt-4 space-y-3">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Código</label><input x-model="propiedades.form.codigo" class="alq-input mt-1"></div>
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Nombre</label><input x-model="propiedades.form.nombre" class="alq-input mt-1"></div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Tipo</label><select x-model="propiedades.form.tipo_inmueble" class="alq-select mt-1"><option>departamento</option><option>casa</option><option>local</option><option>oficina</option><option>galpon</option></select></div>
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Ciudad</label><input x-model="propiedades.form.ciudad" class="alq-input mt-1"></div>
                    </div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Dirección</label><input x-model="propiedades.form.direccion" class="alq-input mt-1"></div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Matrícula</label><input x-model="propiedades.form.matricula" class="alq-input mt-1"></div>
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Padrón</label><input x-model="propiedades.form.padron" class="alq-input mt-1"></div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Canon mensual</label><input x-model="propiedades.form.canon_mensual" type="number" class="alq-input mt-1"></div>
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Depósito</label><input x-model="propiedades.form.deposito_garantia" type="number" class="alq-input mt-1"></div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-1">
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Moneda</label><select x-model="propiedades.form.moneda" class="alq-select mt-1"><option>PYG</option><option>USD</option></select></div>
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Vence día</label><input x-model="propiedades.form.dia_vencimiento" type="number" min="1" max="28" class="alq-input mt-1"></div>
                        <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Estado</label><select x-model="propiedades.form.estado" class="alq-select mt-1"><option>disponible</option><option>alquilado</option><option>mantenimiento</option></select></div>
                    </div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Observación</label><textarea x-model="propiedades.form.observacion" rows="3" class="alq-textarea mt-1"></textarea></div>
                    <div class="flex gap-2">
                        <button @click="propiedades.save()" class="alq-btn bg-teal-600 text-white">Guardar</button>
                        <button @click="propiedades.reset()" class="alq-btn border border-slate-300 dark:border-slate-700">Limpiar</button>
                    </div>
                </div>
            </section>

            <section class="alq-card-light p-5 dark:alq-card">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-[15px] text-slate-900 dark:text-white">Listado</h2>
                    <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="propiedades.rows.length + ' unidades'"></div>
                </div>
                <div class="grid gap-3 lg:grid-cols-2">
                    <template x-for="row in propiedades.rows" :key="row.id_propiedad">
                        <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                            <div class="text-slate-900 dark:text-white" x-text="row.codigo + ' · ' + row.nombre"></div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.tipo_inmueble + ' · ' + row.ciudad"></div>
                            <div class="mt-2 text-[13px] text-teal-600" x-text="money(row.canon_mensual) + '/mes'"></div>
                            <div class="mt-1 text-[11px]"><span class="alq-badge" :class="row.estado === 'disponible' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-700'" x-text="row.estado"></span></div>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </template>

    <!-- ============================================================ -->
    <!-- TAB: CONTRATOS -->
    <!-- ============================================================ -->
    <template x-if="activeTab === 'contratos'">
        <div class="grid gap-5 xl:grid-cols-2">
            <section class="alq-card-light p-5 dark:alq-card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[15px] text-slate-900 dark:text-white">Inquilino</h2>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400">Consentimiento WhatsApp obligatorio</span>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Tipo</label><select x-model="contratos.tenant.tipo_persona" class="alq-select mt-1"><option>fisica</option><option>juridica</option></select></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Nombre / Razón</label><input x-model="contratos.tenant.nombre_razon" class="alq-input mt-1"></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Documento</label><input x-model="contratos.tenant.documento" class="alq-input mt-1"></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">RUC</label><input x-model="contratos.tenant.ruc" class="alq-input mt-1"></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Teléfono</label><input x-model="contratos.tenant.telefono" class="alq-input mt-1"></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">WhatsApp</label><input x-model="contratos.tenant.whatsapp" class="alq-input mt-1"></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Email</label><input x-model="contratos.tenant.email" class="alq-input mt-1"></div>
                    <div class="flex items-end"><label class="inline-flex items-center gap-2"><input x-model="contratos.tenant.consentimiento_whatsapp" type="checkbox" class="h-4 w-4 rounded"><span>Acepta avisos por WhatsApp</span></label></div>
                    <div class="md:col-span-2"><label class="text-[11px] text-slate-500 dark:text-slate-400">Domicilio</label><input x-model="contratos.tenant.domicilio" class="alq-input mt-1"></div>
                </div>
                <div class="mt-3 flex gap-2">
                    <button @click="contratos.saveTenant()" class="alq-btn bg-teal-600 text-white">Guardar inquilino</button>
                    <button @click="contratos.resetTenant()" class="alq-btn border border-slate-300 dark:border-slate-700">Limpiar</button>
                </div>
            </section>

            <section class="alq-card-light p-5 dark:alq-card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[15px] text-slate-900 dark:text-white">Contrato</h2>
                    <button x-show="contratos.contract.id_contrato" @click="printContract(contratos.contract.id_contrato)" class="alq-btn border border-amber-500 text-amber-700 dark:text-amber-200">Imprimir contrato</button>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">N° contrato</label><input x-model="contratos.contract.numero_contrato" class="alq-input mt-1"></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Propiedad</label><select x-model="contratos.contract.id_propiedad" @change="contratos.syncFromProperty()" class="alq-select mt-1"><option value="">Seleccione</option><template x-for="p in contratos.propiedades" :key="p.id_propiedad"><option :value="p.id_propiedad" x-text="p.codigo + ' · ' + p.nombre"></option></template></select></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Inquilino</label><select x-model="contratos.contract.id_inquilino" class="alq-select mt-1"><option value="">Seleccione</option><template x-for="i in contratos.inquilinos" :key="i.id_inquilino"><option :value="i.id_inquilino" x-text="i.nombre_razon"></option></template></select></div>
                    <div><label class="text-[11px] text-slate-500 dark:text-slate-400">Destino</label><select x-model="contratos.contract.destino_inmueble" class="alq-select mt-1"><option>vivienda</option><option>comercial</option><option>mixto</option></select></div>
                </div>
                <div class="mt-3 flex gap-2">
                    <button @click="contratos.save()" class="alq-btn bg-teal-600 text-white">Guardar contrato</button>
                    <button @click="contratos.reset()" class="alq-btn border border-slate-300 dark:border-slate-700">Limpiar</button>
                </div>
            </section>
        </div>
        
        <section class="alq-card-light p-5 dark:alq-card">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Contratos activos</h2>
            <div class="mt-4 space-y-3">
                <template x-for="row in contratos.rows" :key="row.id_contrato">
                    <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div x-text="row.numero_contrato" class="text-slate-900 dark:text-white"></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.nombre_razon + ' · ' + row.propiedad"></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="row.fecha_inicio + ' a ' + row.fecha_fin"></div>
                            </div>
                            <span class="alq-badge bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200" x-text="row.estado"></span>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </template>

    <!-- ============================================================ -->
    <!-- TAB: FACTURACIÓN -->
    <!-- ============================================================ -->
    <template x-if="activeTab === 'facturacion'">
        <div class="space-y-5">
            <section class="alq-card-light p-5 dark:alq-card">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="text-[11px] text-slate-500 dark:text-slate-400">Periodo</label>
                        <input x-model="facturacion.periodo" type="month" class="alq-input mt-1 dark:[color-scheme:dark]">
                    </div>
                    <button @click="facturacion.generateMonth()" class="alq-btn bg-teal-600 text-white">Generar facturas del periodo</button>
                    <div class="text-[11px] text-slate-500 dark:text-slate-400">La FE se deja preparada con payload, lista para SIFEN.</div>
                </div>
            </section>

            <section class="alq-card-light p-5 dark:alq-card">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-[15px] text-slate-900 dark:text-white">Facturas</h2>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400" x-text="facturacion.rows.length + ' registros'"></span>
                </div>
                <div class="space-y-3">
                    <template x-for="row in facturacion.rows" :key="row.id_factura">
                        <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="text-slate-900 dark:text-white" x-text="row.nombre_razon + ' · ' + row.propiedad"></div>
                                    <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="'Contrato ' + row.numero_contrato + ' · Periodo ' + row.periodo"></div>
                                    <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400" x-text="'Emisión ' + row.fecha_emision + ' · Vence ' + row.fecha_vencimiento"></div>
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
                                <button @click="facturacion.prepareFe(row.id_factura)" class="alq-btn border border-teal-500 text-teal-700 dark:text-teal-200">Preparar FE</button>
                                <button @click="facturacion.markPaid(row.id_factura)" class="alq-btn border border-sky-500 text-sky-700 dark:text-sky-200">Marcar pagada</button>
                                <button x-show="row.fe_payload_json" @click="facturacion.showPayload(row.fe_payload_json)" class="alq-btn border border-amber-500 text-amber-700 dark:text-amber-200">Ver payload</button>
                            </div>
                        </div>
                    </template>
                    <div x-show="!facturacion.rows.length" class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-400 dark:border-slate-700">Sin facturas emitidas todavía.</div>
                </div>
            </section>
        </div>
    </template>

    <!-- ============================================================ -->
    <!-- TAB: GASTOS -->
    <!-- ============================================================ -->
    <template x-if="activeTab === 'gastos'">
        <div class="alq-card-light p-5 dark:alq-card">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Gastos y Expensas</h2>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">Cargar gastos asociados a propiedades para trasladar a inquilinos.</p>
            <div class="mt-4 text-slate-500 text-center py-8">Módulo de gastos en construcción...</div>
        </div>
    </template>

    <!-- ============================================================ -->
    <!-- TAB: AVISOS -->
    <!-- ============================================================ -->
    <template x-if="activeTab === 'avisos'">
        <div class="alq-card-light p-5 dark:alq-card">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Avisos y Notificaciones</h2>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">WhatsApp, email y sistema interno.</p>
            <div class="mt-4 text-slate-500 text-center py-8">Módulo de avisos en construcción...</div>
        </div>
    </template>

    <!-- ============================================================ -->
    <!-- TAB: IMÁGENES -->
    <!-- ============================================================ -->
    <template x-if="activeTab === 'imagenes'">
        <div class="alq-card-light p-5 dark:alq-card">
            <h2 class="text-[15px] text-slate-900 dark:text-white">Galería de Imágenes</h2>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">Cargar fotos de propiedades para publicación.</p>
            <div class="mt-4 text-slate-500 text-center py-8">Módulo de imágenes en construcción...</div>
        </div>
    </template>

</div>

<script>
function alqSpaManager() {
    return {
        activeTab: 'dashboard',
        tabTitle: 'Panel Operativo',
        tabSubtitle: 'Gestión de Alquileres',
        tabs: [
            { key: 'dashboard', label: 'Panel', icon: 'chart-line' },
            { key: 'propiedades', label: 'Propiedades', icon: 'building' },
            { key: 'contratos', label: 'Contratos', icon: 'file-signature' },
            { key: 'facturacion', label: 'Facturación', icon: 'file-invoice-dollar' },
            { key: 'gastos', label: 'Gastos', icon: 'receipt' },
            { key: 'avisos', label: 'Avisos', icon: 'bell' },
            { key: 'imagenes', label: 'Imágenes', icon: 'image' },
        ],
        
        // Datos Dashboard
        dashboard: {
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
        },

        // Módulo Propiedades
        propiedades: {
            rows: [],
            form: {
                codigo: '', nombre: '', tipo_inmueble: 'departamento', ciudad: '',
                direccion: '', matricula: '', padron: '', canon_mensual: '',
                deposito_garantia: '', moneda: 'PYG', dia_vencimiento: 10,
                estado: 'disponible', observacion: ''
            },
            save: function() { alert('Guardar propiedad (no implementado)'); },
            reset: function() {
                this.form = {
                    codigo: '', nombre: '', tipo_inmueble: 'departamento', ciudad: '',
                    direccion: '', matricula: '', padron: '', canon_mensual: '',
                    deposito_garantia: '', moneda: 'PYG', dia_vencimiento: 10,
                    estado: 'disponible', observacion: ''
                };
            }
        },

        // Módulo Contratos
        contratos: {
            rows: [],
            propiedades: [],
            inquilinos: [],
            tenant: {
                tipo_persona: 'fisica', nombre_razon: '', documento: '', ruc: '',
                telefono: '', whatsapp: '', email: '', domicilio: '',
                consentimiento_whatsapp: false
            },
            contract: {
                id_contrato: null, numero_contrato: '', id_propiedad: '', id_inquilino: '',
                destino_inmueble: 'vivienda', fecha_firma: '', fecha_inicio: '', fecha_fin: '',
                moneda: 'PYG', canon_mensual: '', expensas_mensuales: '', servicios_mensuales: '',
                deposito_garantia: '', dia_vencimiento: 10, mora_fija: ''
            },
            saveTenant: function() { alert('Guardar inquilino (no implementado)'); },
            resetTenant: function() {
                this.tenant = {
                    tipo_persona: 'fisica', nombre_razon: '', documento: '', ruc: '',
                    telefono: '', whatsapp: '', email: '', domicilio: '',
                    consentimiento_whatsapp: false
                };
            },
            save: function() { alert('Guardar contrato (no implementado)'); },
            reset: function() {
                this.contract = {
                    id_contrato: null, numero_contrato: '', id_propiedad: '', id_inquilino: '',
                    destino_inmueble: 'vivienda', fecha_firma: '', fecha_inicio: '', fecha_fin: '',
                    moneda: 'PYG', canon_mensual: '', expensas_mensuales: '', servicios_mensuales: '',
                    deposito_garantia: '', dia_vencimiento: 10, mora_fija: ''
                };
            },
            syncFromProperty: function() { /* sincronizar datos de propiedad seleccionada */ }
        },

        // Módulo Facturación
        facturacion: {
            rows: [],
            periodo: new Date().toISOString().substring(0, 7),
            generateMonth: function() { alert('Generar facturas (no implementado)'); },
            prepareFe: function(id_factura) { alert('Preparar FE (no implementado)'); },
            markPaid: function(id_factura) { alert('Marcar pagada (no implementado)'); },
            showPayload: function(payload) { alert('Payload: ' + JSON.stringify(JSON.parse(payload), null, 2)); }
        },

        // Métodos principales
        setTab: function(tabKey) {
            this.activeTab = tabKey;
            const tab = this.tabs.find(t => t.key === tabKey);
            if (tab) {
                this.tabTitle = {
                    dashboard: 'Panel Operativo',
                    propiedades: 'Propiedades y Unidades',
                    contratos: 'Contratos Privados',
                    facturacion: 'Facturación Mensual y FE',
                    gastos: 'Gastos y Expensas',
                    avisos: 'Avisos y Notificaciones',
                    imagenes: 'Galería de Imágenes'
                }[tabKey] || 'Alquileres';
                this.tabSubtitle = {
                    dashboard: 'Gestión de Alquileres',
                    propiedades: 'Inventario locativo de la inmobiliaria',
                    contratos: 'Inquilinos, contratos y documento imprimible',
                    facturacion: 'Generación periódica, estado de cobro y SIFEN',
                    gastos: 'Mantenimiento, servicios e impuestos',
                    avisos: 'Comunicación con inquilinos',
                    imagenes: 'Fotos para publicación y portales'
                }[tabKey] || '';
            }
            // Cargar datos si es necesario
            if (tabKey === 'dashboard') this.loadDashboard();
            else if (tabKey === 'propiedades') this.loadPropiedades();
            else if (tabKey === 'contratos') this.loadContratos();
            else if (tabKey === 'facturacion') this.loadFacturacion();
        },

        loadDashboard: async function() {
            try {
                const res = await fetch('/public/alquileres/api/dashboard.php', { credentials: 'same-origin', cache: 'no-store' });
                const data = await res.json();
                if (!data.ok) return;
                this.dashboard.cards[0].value = String(data.stats.propiedades || 0);
                this.dashboard.cards[1].value = String(data.stats.contratos_activos || 0);
                this.dashboard.cards[2].value = String(data.stats.facturas_pendientes || 0);
                this.dashboard.cards[3].value = this.money(data.stats.monto_pendiente || 0);
                this.dashboard.cards[4].value = this.money(data.stats.gastos_pendientes_traslado || 0);
                this.dashboard.vencimientos = data.vencimientos || [];
                this.dashboard.contratos = data.contratos || [];
                this.dashboard.legalLinks = [
                    { label: 'Código Civil - Locación', url: data.legal.civil_code },
                    { label: 'Ley 5638/2016 Vivienda', url: data.legal.vivienda },
                    { label: 'Ley 7593/2025 Datos Personales', url: data.legal.datos },
                    { label: 'DNIT e-Kuatia', url: data.legal.ekuatia },
                ];
            } catch (e) {
                console.error('Error cargando dashboard:', e);
            }
        },

        loadPropiedades: async function() {
            try {
                const res = await fetch('/public/alquileres/api/propiedades.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
                const data = await res.json();
                if (data.ok) this.propiedades.rows = data.rows || [];
            } catch (e) {
                console.error('Error cargando propiedades:', e);
            }
        },

        loadContratos: async function() {
            try {
                const res = await fetch('/public/alquileres/api/contratos.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
                const data = await res.json();
                if (data.ok) {
                    this.contratos.rows = data.rows || [];
                    this.contratos.propiedades = data.propiedades || [];
                    this.contratos.inquilinos = data.inquilinos || [];
                }
            } catch (e) {
                console.error('Error cargando contratos:', e);
            }
        },

        loadFacturacion: async function() {
            try {
                const res = await fetch('/public/alquileres/api/facturacion.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
                const data = await res.json();
                if (data.ok) this.facturacion.rows = data.rows || [];
            } catch (e) {
                console.error('Error cargando facturación:', e);
            }
        },

        init: async function() {
            this.loadDashboard();
        },

        money: function(value, currency = 'PYG') {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency, maximumFractionDigits: 0 }).format(Number(value || 0));
        }
    };
}

// Función global para imprimir contrato (desde template)
function printContract(id_contrato) {
    window.open('/public/alquileres/documento.php?id_contrato=' + id_contrato, '_blank');
}
</script>

<?php smxAlqRenderSpaFoot(); ?>
