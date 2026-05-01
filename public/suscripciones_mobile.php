<?php
/**
 * Gestion de Suscripciones SaaS (Movil)
 * Exclusivo para super admin (empresa 169) y dispositivos moviles.
 */

require_once __DIR__ . '/../config/bootstrap.php';

Session::requireLogin('/public/login.php');

$idEmpresa = Session::getIdEmpresa();
if ((int)$idEmpresa !== 169) {
    header('Location: /public/mi_suscripcion.php');
    exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = (bool)preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile/i', $ua);
if (!$isMobile && !isset($_GET['force_mobile'])) {
    header('Location: /public/suscripciones.php');
    exit;
}

$usrName = $_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Suscripciones SaaS - Movil</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        html, body { height: 100%; }
        body { -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="bg-slate-100 text-slate-900" x-data="suscripcionesMobileApp()" x-init="init()">
    <div class="min-h-screen pb-24">
        <header class="sticky top-0 z-20 bg-slate-900 text-white border-b border-slate-800">
            <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="/public/menu/menu.php" class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center active:scale-95">
                        <i class="fas fa-arrow-left text-sm"></i>
                    </a>
                    <div>
                        <p class="text-sm font-semibold leading-tight">Suscripciones SaaS</p>
                        <p class="text-[11px] text-slate-300">Version movil</p>
                    </div>
                </div>
                <button @click="refresh()" class="w-9 h-9 rounded-xl bg-cyan-500/20 text-cyan-300 flex items-center justify-center active:scale-95">
                    <i class="fas fa-rotate"></i>
                </button>
            </div>
            <div class="px-4 pb-3">
                <div class="flex gap-2">
                    <input x-model.trim="filtroQ" @input.debounce.300ms="aplicarFiltroLocal()"
                           placeholder="Empresa, RUC, factura..."
                           class="flex-1 h-10 px-3 rounded-xl bg-white text-slate-800 text-sm border border-slate-200 focus:outline-none focus:ring-2 focus:ring-cyan-400">
                    <select x-model="filtroEstado" @change="reload()"
                            class="h-10 px-2 rounded-xl bg-white text-slate-800 text-sm border border-slate-200">
                        <option value="">Estado</option>
                        <option value="activa">Activa</option>
                        <option value="gracia">Gracia</option>
                        <option value="vencida">Vencida</option>
                        <option value="cancelada">Cancelada</option>
                    </select>
                    <select x-model="filtroPago" @change="reload()"
                            class="h-10 px-2 rounded-xl bg-white text-slate-800 text-sm border border-slate-200">
                        <option value="">Pago</option>
                        <option value="pendiente">Pendiente</option>
                        <option value="pagado">Pagado</option>
                        <option value="atrasado">Atrasado</option>
                        <option value="parcial">Parcial</option>
                    </select>
                </div>
            </div>
        </header>

        <main class="px-4 py-4 space-y-3">
            <div class="text-[11px] text-slate-500">
                <span x-text="usuarioLabel"></span>
                <span class="mx-1">•</span>
                <span x-text="`${filtradas.length} registros`"></span>
            </div>

            <template x-if="loading && suscripciones.length === 0">
                <div class="py-16 text-center text-slate-500">
                    <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                    <p class="text-sm">Cargando suscripciones...</p>
                </div>
            </template>

            <template x-for="s in filtradas" :key="s.id_suscripcion">
                <article class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 active:scale-[0.99]">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-sm leading-5 truncate" x-text="s.nombre_empresa || `Empresa ${s.id_empresa}`"></h3>
                            <p class="text-[11px] text-slate-500" x-text="(s.ruc || 'RUC s/d') + ' • #' + s.id_suscripcion"></p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-sm">₲ <span x-text="formatNumber(s.total)"></span></p>
                            <p class="text-[11px] text-slate-500" x-text="s.nro_factura || 'Sin factura'"></p>
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span class="px-2 py-1 rounded-full text-[11px] font-medium" :class="estadoClass(s.estado)" x-text="capitalizar(s.estado || 's/d')"></span>
                        <span class="px-2 py-1 rounded-full text-[11px] font-medium" :class="pagoClass(s.estado_pago)" x-text="capitalizar(s.estado_pago || 's/d')"></span>
                        <span x-show="Number(s.es_sponsor || 0) === 1" class="px-2 py-1 rounded-full text-[11px] font-medium bg-amber-100 text-amber-700">
                            Sponsor
                        </span>
                    </div>

                    <div class="mt-3 text-[11px] text-slate-600 grid grid-cols-2 gap-1">
                        <p><span class="text-slate-400">Inicio:</span> <span x-text="formatDate(s.periodo_inicio)"></span></p>
                        <p><span class="text-slate-400">Fin:</span> <span x-text="formatDate(s.periodo_fin)"></span></p>
                        <p><span class="text-slate-400">Apps:</span> <span x-text="Number(s.cantidad_apps || 0)"></span></p>
                        <p><span class="text-slate-400">Pago:</span> <span x-text="formatDate(s.fecha_pago) || '-'"></span></p>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <button @click="abrirDetalle(s)"
                                class="flex-1 h-9 rounded-xl bg-slate-900 text-white text-xs font-medium">
                            Ver detalle
                        </button>
                        <button x-show="(s.estado_pago || '') !== 'pagado'" @click="marcarPagado(s)"
                                class="h-9 px-3 rounded-xl bg-emerald-600 text-white text-xs font-medium">
                            Pagado
                        </button>
                    </div>
                </article>
            </template>

            <template x-if="!loading && filtradas.length === 0">
                <div class="py-16 text-center text-slate-400">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p class="text-sm">Sin resultados</p>
                </div>
            </template>

            <button x-show="!loading && page < totalPages" @click="cargarMas()"
                    class="w-full h-10 rounded-xl border border-slate-300 bg-white text-slate-700 text-sm font-medium">
                Cargar mas
            </button>
        </main>
    </div>

    <div x-show="toast.show" x-transition x-cloak
         class="fixed bottom-5 left-1/2 -translate-x-1/2 px-4 py-2 rounded-xl text-sm text-white shadow-lg z-50"
         :class="toast.type === 'error' ? 'bg-rose-600' : 'bg-slate-900'">
        <span x-text="toast.message"></span>
    </div>

    <div x-show="showDetalle" x-cloak class="fixed inset-0 z-40">
        <div class="absolute inset-0 bg-black/55" @click="cerrarDetalle()"></div>
        <section class="absolute inset-x-0 bottom-0 bg-white rounded-t-3xl p-4 max-h-[85vh] overflow-y-auto"
                 x-transition:enter="transition duration-200"
                 x-transition:enter-start="translate-y-full"
                 x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition duration-150"
                 x-transition:leave-start="translate-y-0"
                 x-transition:leave-end="translate-y-full">
            <div class="w-10 h-1 bg-slate-300 rounded-full mx-auto mb-3"></div>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold" x-text="actual?.nombre_empresa || ''"></h3>
                    <p class="text-[11px] text-slate-500">Suscripcion #<span x-text="actual?.id_suscripcion"></span></p>
                </div>
                <button @click="cerrarDetalle()" class="w-8 h-8 rounded-lg bg-slate-100 text-slate-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
                <div class="bg-slate-50 rounded-xl p-2">
                    <p class="text-slate-400">Total</p>
                    <p class="font-semibold">₲ <span x-text="formatNumber(actual?.total || 0)"></span></p>
                </div>
                <div class="bg-slate-50 rounded-xl p-2">
                    <p class="text-slate-400">Factura</p>
                    <p class="font-semibold" x-text="actual?.nro_factura || '-'"></p>
                </div>
                <div class="bg-slate-50 rounded-xl p-2">
                    <p class="text-slate-400">Periodo</p>
                    <p class="font-semibold" x-text="`${formatDate(actual?.periodo_inicio)} - ${formatDate(actual?.periodo_fin)}`"></p>
                </div>
                <div class="bg-slate-50 rounded-xl p-2">
                    <p class="text-slate-400">Vencimiento</p>
                    <p class="font-semibold" x-text="formatDate(actual?.fecha_vencimiento) || '-'"></p>
                </div>
            </div>

            <div class="mt-4">
                <label class="text-[11px] text-slate-500">Editar periodo</label>
                <div class="mt-1 grid grid-cols-2 gap-2">
                    <input type="date" x-model="periodoInicio" class="h-10 px-2 rounded-xl border border-slate-200 text-sm">
                    <input type="date" x-model="periodoFin" class="h-10 px-2 rounded-xl border border-slate-200 text-sm">
                </div>
                <div class="mt-2">
                    <input type="date" x-model="periodoVencimiento" class="w-full h-10 px-2 rounded-xl border border-slate-200 text-sm" placeholder="Vencimiento">
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <button @click="guardarPeriodo()" class="h-10 rounded-xl bg-cyan-600 text-white text-xs font-medium">
                    Guardar periodo
                </button>
                <button @click="toggleSponsor(actual)" class="h-10 rounded-xl text-xs font-medium"
                        :class="Number(actual?.es_sponsor || 0) === 1 ? 'bg-amber-500 text-white' : 'bg-slate-900 text-white'">
                    <span x-text="Number(actual?.es_sponsor || 0) === 1 ? 'Quitar sponsor' : 'Habilitar sponsor'"></span>
                </button>
                <button x-show="(actual?.estado_pago || '') !== 'pagado'" @click="marcarPagado(actual)"
                        class="h-10 rounded-xl bg-emerald-600 text-white text-xs font-medium col-span-2">
                    Marcar como pagado
                </button>
            </div>
        </section>
    </div>

    <script>
        function suscripcionesMobileApp() {
            return {
                usuarioLabel: <?= json_encode($usrName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                suscripciones: [],
                filtradas: [],
                page: 1,
                perPage: 20,
                totalPages: 1,
                loading: false,
                filtroEstado: '',
                filtroPago: '',
                filtroQ: '',
                showDetalle: false,
                actual: null,
                periodoInicio: '',
                periodoFin: '',
                periodoVencimiento: '',
                toast: { show: false, message: '', type: 'ok' },

                init() {
                    this.reload();
                },

                notify(message, type = 'ok') {
                    this.toast = { show: true, message, type };
                    setTimeout(() => this.toast.show = false, 2600);
                },

                formatNumber(v) {
                    const n = Number(v || 0);
                    return new Intl.NumberFormat('es-PY').format(n);
                },

                formatDate(v) {
                    if (!v) return '';
                    const s = String(v).slice(0, 10);
                    if (!s || s === '0000-00-00') return '';
                    const [y, m, d] = s.split('-');
                    if (!y || !m || !d) return s;
                    return `${d}/${m}/${y}`;
                },

                normalizarFecha(v) {
                    return v ? String(v).slice(0, 10) : '';
                },

                capitalizar(v) {
                    const s = String(v || '');
                    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
                },

                estadoClass(estado) {
                    const s = String(estado || '').toLowerCase();
                    if (s === 'activa') return 'bg-emerald-100 text-emerald-700';
                    if (s === 'gracia') return 'bg-amber-100 text-amber-700';
                    if (s === 'vencida') return 'bg-rose-100 text-rose-700';
                    return 'bg-slate-200 text-slate-700';
                },

                pagoClass(estadoPago) {
                    const s = String(estadoPago || '').toLowerCase();
                    if (s === 'pagado') return 'bg-emerald-100 text-emerald-700';
                    if (s === 'pendiente') return 'bg-yellow-100 text-yellow-700';
                    if (s === 'atrasado') return 'bg-rose-100 text-rose-700';
                    if (s === 'parcial') return 'bg-indigo-100 text-indigo-700';
                    return 'bg-slate-200 text-slate-700';
                },

                aplicarFiltroLocal() {
                    const q = this.filtroQ.toLowerCase().trim();
                    if (!q) {
                        this.filtradas = [...this.suscripciones];
                        return;
                    }
                    this.filtradas = this.suscripciones.filter((s) => {
                        return String(s.nombre_empresa || '').toLowerCase().includes(q)
                            || String(s.ruc || '').toLowerCase().includes(q)
                            || String(s.nro_factura || '').toLowerCase().includes(q)
                            || String(s.id_suscripcion || '').includes(q);
                    });
                },

                async cargar(reset = false) {
                    if (reset) {
                        this.page = 1;
                        this.suscripciones = [];
                    }
                    this.loading = true;
                    try {
                        const params = new URLSearchParams({
                            action: 'suscripciones_todas',
                            page: String(this.page),
                            per_page: String(this.perPage),
                            estado: this.filtroEstado || '',
                            estado_pago: this.filtroPago || ''
                        });
                        const res = await fetch(`/public/api/v1/suscripciones.php?${params.toString()}`);
                        const data = await res.json();
                        if (!data.success) {
                            this.notify(data.error || 'No se pudieron cargar suscripciones', 'error');
                            return;
                        }
                        const rows = Array.isArray(data.data) ? data.data : [];
                        this.totalPages = Number(data.pagination?.total_pages || 1);
                        this.suscripciones = reset ? rows : [...this.suscripciones, ...rows];
                        this.aplicarFiltroLocal();
                    } catch (e) {
                        this.notify('Error de conexion', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                reload() {
                    this.cargar(true);
                },

                refresh() {
                    this.reload();
                },

                cargarMas() {
                    if (this.loading || this.page >= this.totalPages) return;
                    this.page += 1;
                    this.cargar(false);
                },

                abrirDetalle(s) {
                    this.actual = { ...s, es_sponsor: Number(s.es_sponsor || 0) };
                    this.periodoInicio = this.normalizarFecha(s.periodo_inicio);
                    this.periodoFin = this.normalizarFecha(s.periodo_fin);
                    this.periodoVencimiento = this.normalizarFecha(s.fecha_vencimiento);
                    this.showDetalle = true;
                },

                cerrarDetalle() {
                    this.showDetalle = false;
                    this.actual = null;
                },

                actualizarLocal(idSuscripcion, patch) {
                    this.suscripciones = this.suscripciones.map((s) =>
                        Number(s.id_suscripcion) === Number(idSuscripcion) ? { ...s, ...patch } : s
                    );
                    this.aplicarFiltroLocal();
                    if (this.actual && Number(this.actual.id_suscripcion) === Number(idSuscripcion)) {
                        this.actual = { ...this.actual, ...patch };
                    }
                },

                async marcarPagado(s) {
                    if (!s?.id_suscripcion) return;
                    if (!confirm('Marcar esta suscripcion como pagada?')) return;
                    try {
                        const res = await fetch('/public/api/v1/suscripciones.php?action=marcar_pagado', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id_suscripcion: s.id_suscripcion })
                        });
                        const data = await res.json();
                        if (!data.success) {
                            this.notify(data.error || 'No se pudo marcar como pagado', 'error');
                            return;
                        }
                        this.actualizarLocal(s.id_suscripcion, { estado_pago: 'pagado', fecha_pago: new Date().toISOString().slice(0, 10) });
                        this.notify(data.message || 'Marcado como pagado');
                    } catch (e) {
                        this.notify('Error de conexion', 'error');
                    }
                },

                async toggleSponsor(s) {
                    if (!s?.id_suscripcion) return;
                    const esSponsor = Number(s.es_sponsor || 0) === 1;
                    if (!confirm(esSponsor ? 'Quitar modo sponsor?' : 'Habilitar modo sponsor?')) return;
                    try {
                        const res = await fetch('/public/api/v1/suscripciones.php?action=toggle_sponsor', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id_suscripcion: s.id_suscripcion,
                                sponsor: esSponsor ? 0 : 1
                            })
                        });
                        const data = await res.json();
                        if (!data.success) {
                            this.notify(data.error || 'No se pudo actualizar sponsor', 'error');
                            return;
                        }
                        const patch = data.data || { es_sponsor: esSponsor ? 0 : 1 };
                        this.actualizarLocal(s.id_suscripcion, patch);
                        this.notify(data.message || 'Sponsor actualizado');
                    } catch (e) {
                        this.notify('Error de conexion', 'error');
                    }
                },

                async guardarPeriodo() {
                    if (!this.actual?.id_suscripcion) return;
                    const ini = this.normalizarFecha(this.periodoInicio);
                    const fin = this.normalizarFecha(this.periodoFin);
                    const venc = this.normalizarFecha(this.periodoVencimiento);
                    if (!ini || !fin) {
                        this.notify('Complete inicio y fin', 'error');
                        return;
                    }
                    if (ini > fin) {
                        this.notify('Inicio no puede ser mayor a fin', 'error');
                        return;
                    }
                    if (venc && venc < fin) {
                        this.notify('Vencimiento no puede ser menor al fin', 'error');
                        return;
                    }
                    try {
                        const res = await fetch('/public/api/v1/suscripciones.php?action=actualizar_periodo_suscripcion', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id_suscripcion: this.actual.id_suscripcion,
                                periodo_inicio: ini,
                                periodo_fin: fin,
                                fecha_vencimiento: venc || ''
                            })
                        });
                        const data = await res.json();
                        if (!data.success) {
                            this.notify(data.error || 'No se pudo guardar periodo', 'error');
                            return;
                        }
                        this.actualizarLocal(this.actual.id_suscripcion, {
                            periodo_inicio: ini,
                            periodo_fin: fin,
                            fecha_vencimiento: venc || null
                        });
                        this.notify(data.message || 'Periodo actualizado');
                    } catch (e) {
                        this.notify('Error de conexion', 'error');
                    }
                }
            };
        }
    </script>
</body>
</html>
