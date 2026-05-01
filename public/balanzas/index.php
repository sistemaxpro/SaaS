<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_balanza');
$permisos = Permission::getAppPermissions('app_grid_balanza');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Balanza Electrónica</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .field-ui {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            border-width: 1px;
            transition: color 0.15s ease, background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            outline: none;
            background-color: rgb(255 255 255);
            border-color: rgb(203 213 225);
            color: rgb(15 23 42);
        }
        .dark .field-ui {
            background-color: rgb(30 41 59);
            border-color: rgb(71 85 105);
            color: rgb(241 245 249);
        }
        .field-ui::placeholder {
            color: rgb(100 116 139);
        }
        .dark .field-ui::placeholder {
            color: rgb(148 163 184);
        }
        .field-ui:focus {
            border-color: rgb(59 130 246);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .dark .field-ui option {
            background-color: rgb(30 41 59);
            color: rgb(241 245 249);
        }
    </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 min-h-screen">
<div x-data="balanzasApp()" x-init="init()" class="max-w-7xl mx-auto p-4 md:p-6">
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <div class="p-4 md:p-5 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                        class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 hover:bg-red-600/20">
                    <span class="text-xl leading-none">&times;</span>
                </button>
                <div>
                    <h1 class="text-xl font-bold text-slate-800 dark:text-white">Gestión de Balanza Electrónica</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-300">Configurar formatos de códigos de balanza y probar lecturas</p>
                </div>
            </div>
            <button x-show="permisos.priv_insert === 'Y'" @click="nueva()"
                    class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">Nueva configuración</button>
        </div>

        <div class="p-4 md:p-5">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2">
                    <div class="overflow-x-auto border border-slate-200 dark:border-slate-700 rounded-xl">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-700/40 text-slate-600 dark:text-slate-300">
                            <tr>
                                <th class="px-3 py-2 text-left">Modelo</th>
                                <th class="px-3 py-2 text-left">Prefijo</th>
                                <th class="px-3 py-2 text-left">Modo</th>
                                <th class="px-3 py-2 text-left">Activo</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700 text-slate-700 dark:text-slate-200">
                            <template x-for="b in balanzas" :key="b.id_balanza">
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2" x-text="b.nombre_modelo"></td>
                                    <td class="px-3 py-2 font-mono" x-text="b.prefijo"></td>
                                    <td class="px-3 py-2" x-text="b.modo"></td>
                                    <td class="px-3 py-2">
                                        <span class="px-2 py-0.5 rounded-full text-xs"
                                              :class="Number(b.activo)===1 ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'"
                                              x-text="Number(b.activo)===1 ? 'Sí' : 'No'"></span>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button @click="editar(b)" class="px-2 py-1 text-blue-600 dark:text-blue-400">Editar</button>
                                        <button x-show="permisos.priv_delete === 'Y'" @click="eliminar(b)" class="px-2 py-1 text-red-600 dark:text-red-400">Eliminar</button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="balanzas.length===0">
                                <td colspan="5" class="px-3 py-6 text-center text-slate-500 dark:text-slate-400">Sin configuraciones de balanza</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="border border-slate-200 dark:border-slate-700 rounded-xl p-3">
                    <h3 class="font-semibold text-slate-800 dark:text-white mb-3" x-text="form.id_balanza ? 'Editar' : 'Nueva configuración'"></h3>
                    <div class="space-y-2">
                        <input x-model="form.nombre_modelo" class="field-ui" placeholder="Nombre del modelo">
                        <input x-model="form.prefijo" class="field-ui font-mono" placeholder="Prefijo (ej: 20)">
                        <div class="grid grid-cols-2 gap-2">
                            <input x-model.number="form.longitud_codigo" type="number" class="field-ui" placeholder="Long.">
                            <select x-model="form.modo" class="field-ui">
                                <option value="PESO">PESO</option>
                                <option value="PRECIO">PRECIO</option>
                                <option value="CANTIDAD">CANTIDAD</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <input x-model.number="form.pos_inicio_producto" type="number" class="field-ui" placeholder="Pos prod.">
                            <input x-model.number="form.largo_producto" type="number" class="field-ui" placeholder="Largo prod.">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <input x-model.number="form.pos_inicio_valor" type="number" class="field-ui" placeholder="Pos valor">
                            <input x-model.number="form.largo_valor" type="number" class="field-ui" placeholder="Largo valor">
                        </div>
                        <input x-model.number="form.divisor_valor" type="number" step="0.001" class="field-ui" placeholder="Divisor valor">
                        <input x-model="form.observacion" class="field-ui" placeholder="Observación">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input x-model="form.activo" type="checkbox" class="rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500 dark:bg-slate-700"> Activo
                        </label>
                        <div class="flex gap-2 pt-1">
                            <button x-show="permisos.priv_insert === 'Y' || permisos.priv_update === 'Y'" @click="guardar()" class="flex-1 px-3 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-semibold">Guardar</button>
                            <button @click="nueva()" class="px-3 py-2 rounded-lg bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-100">Limpiar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 border border-slate-200 dark:border-slate-700 rounded-xl p-3">
                <h3 class="font-semibold text-slate-800 dark:text-white mb-2">Probar lectura de código de balanza</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                    <select x-model="test.id_balanza" class="field-ui">
                        <option value="">Auto detectar</option>
                        <template x-for="b in balanzas" :key="'tb_'+b.id_balanza">
                            <option :value="b.id_balanza" x-text="b.nombre_modelo + ' (' + b.prefijo + ')'"></option>
                        </template>
                    </select>
                    <input x-model="test.codigo" class="field-ui md:col-span-2 font-mono" placeholder="Código de balanza (ej: 2000037000958)">
                    <button @click="probar()" class="px-3 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-semibold">Probar</button>
                </div>
                <pre x-show="test.resultado" class="mt-3 bg-slate-900 text-slate-100 rounded-lg p-3 text-xs overflow-auto" x-text="test.resultado"></pre>
            </div>
        </div>
    </div>

    <!-- Modal de mensajes -->
    <div x-show="modal.open" x-cloak x-transition.opacity class="fixed inset-0 z-[120] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
        <div @click.away="closeModal()" class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-700/60 bg-slate-900/95 shadow-2xl">
            <div class="p-5 border-b border-slate-700/70">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center text-lg"
                         :class="{
                            'bg-emerald-500/20 text-emerald-300': modal.type === 'success',
                            'bg-rose-500/20 text-rose-300': modal.type === 'error',
                            'bg-amber-500/20 text-amber-300': modal.type === 'warning',
                            'bg-blue-500/20 text-blue-300': modal.type === 'info'
                         }">
                        <span x-text="modal.type === 'success' ? '✓' : (modal.type === 'error' ? '!' : (modal.type === 'warning' ? '⚠' : 'i'))"></span>
                    </div>
                    <h3 class="text-base font-semibold text-white" x-text="modal.title"></h3>
                </div>
            </div>
            <div class="p-5">
                <p class="text-sm text-slate-200 whitespace-pre-line" x-text="modal.message"></p>
            </div>
            <div class="px-5 pb-5">
                <button @click="closeModal()" class="w-full px-4 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-white font-semibold transition-colors">
                    Entendido
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación -->
    <div x-show="confirm.open" x-cloak x-transition.opacity class="fixed inset-0 z-[130] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
        <div @click.away="resolveConfirm(false)" class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-700/60 bg-slate-900/95 shadow-2xl">
            <div class="p-5 border-b border-slate-700/70">
                <h3 class="text-base font-semibold text-white" x-text="confirm.title"></h3>
            </div>
            <div class="p-5">
                <p class="text-sm text-slate-200 whitespace-pre-line" x-text="confirm.message"></p>
            </div>
            <div class="px-5 pb-5 grid grid-cols-2 gap-2">
                <button @click="resolveConfirm(false)" class="px-4 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-white font-semibold transition-colors">Cancelar</button>
                <button @click="resolveConfirm(true)" class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold transition-colors">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<script>
function balanzasApp() {
    return {
        permisos: <?= json_encode($permisos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        balanzas: [],
        form: {},
        test: { id_balanza: '', codigo: '', resultado: '' },
        modal: { open: false, title: '', message: '', type: 'info' },
        confirm: { open: false, title: '', message: '', resolver: null },

        async init() {
            this.nueva();
            await this.cargar();
        },
        notify(type, title, message) {
            this.modal = { open: true, type: type || 'info', title: title || 'Aviso', message: message || '' };
        },
        closeModal() {
            this.modal.open = false;
        },
        askConfirm(title, message) {
            return new Promise((resolve) => {
                this.confirm = { open: true, title: title || 'Confirmar', message: message || '', resolver: resolve };
            });
        },
        resolveConfirm(result) {
            if (typeof this.confirm.resolver === 'function') {
                this.confirm.resolver(!!result);
            }
            this.confirm = { open: false, title: '', message: '', resolver: null };
        },
        nueva() {
            this.form = {
                id_balanza: null, nombre_modelo: '', prefijo: '', longitud_codigo: 13,
                pos_inicio_producto: 3, largo_producto: 5, pos_inicio_valor: 8, largo_valor: 5,
                divisor_valor: 1000, modo: 'PESO', activo: true, observacion: ''
            };
        },
        async cargar() {
            try {
                const res = await fetch('api/balanzas.php?action=list&include_inactive=1');
                const data = await res.json();
                if (!data.ok) {
                    this.notify('error', 'Error al cargar', data.error || 'No se pudo cargar la lista de balanzas.');
                    return;
                }
                this.balanzas = data.data || [];
            } catch (e) {
                this.notify('error', 'Error de conexión', 'No se pudo conectar al servicio de balanzas.');
            }
        },
        editar(b) {
            this.form = {
                id_balanza: Number(b.id_balanza), nombre_modelo: b.nombre_modelo || '', prefijo: b.prefijo || '',
                longitud_codigo: Number(b.longitud_codigo || 13), pos_inicio_producto: Number(b.pos_inicio_producto || 3),
                largo_producto: Number(b.largo_producto || 5), pos_inicio_valor: Number(b.pos_inicio_valor || 8),
                largo_valor: Number(b.largo_valor || 5), divisor_valor: Number(b.divisor_valor || 1000),
                modo: b.modo || 'PESO', activo: Number(b.activo) === 1, observacion: b.observacion || ''
            };
        },
        async guardar() {
            try {
                const res = await fetch('api/balanzas.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.form)
                });
                const data = await res.json();
                if (!data.ok) {
                    this.notify('error', 'No se pudo guardar', data.error || 'No se pudo guardar la configuración.');
                    return;
                }
                await this.cargar();
                this.nueva();
                this.notify('success', 'Configuración guardada', 'La configuración de balanza se guardó correctamente.');
            } catch (e) {
                this.notify('error', 'Error de conexión', 'No se pudo conectar al servicio de balanzas.');
            }
        },
        async eliminar(b) {
            const ok = await this.askConfirm(
                'Eliminar configuración',
                `¿Eliminar configuración "${b.nombre_modelo}"?`
            );
            if (!ok) return;
            try {
                const res = await fetch('api/balanzas.php?action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_balanza: b.id_balanza })
                });
                const data = await res.json();
                if (!data.ok) {
                    this.notify('error', 'No se pudo eliminar', data.error || 'No se pudo eliminar la configuración.');
                    return;
                }
                await this.cargar();
                this.notify('success', 'Configuración eliminada', 'La configuración de balanza fue eliminada.');
            } catch (e) {
                this.notify('error', 'Error de conexión', 'No se pudo conectar al servicio de balanzas.');
            }
        },
        async probar() {
            if (!this.test.codigo) {
                this.notify('warning', 'Código requerido', 'Debe ingresar un código de balanza para realizar la prueba.');
                return;
            }
            const params = new URLSearchParams({
                action: 'test',
                codigo: this.test.codigo,
                id_balanza: String(this.test.id_balanza || '')
            });
            try {
                const res = await fetch('api/balanzas.php?' + params.toString());
                const data = await res.json();
                if (!data.ok) {
                    this.notify('error', 'Error en prueba', data.error || 'No se pudo interpretar el código.');
                    return;
                }
                const result = data.result || data;
                this.test.resultado = JSON.stringify(result, null, 2);

                if (result && result.es_balanza) {
                    const detalle = [
                        `Balanza: ${result.balanza || 'N/D'}`,
                        `Empresa/DB: ${result._ctx_id_empresa ?? 'N/D'} / ${result._ctx_db || 'N/D'}`,
                        `Código leído: ${result.codigo || this.test.codigo || 'N/D'}`,
                        `Prefijo: ${result.prefijo || 'N/D'}`,
                        `Código producto: ${result.codigo_producto || result.raw_producto || 'N/D'}`,
                        `Producto ID interno: ${result.idproducto ?? 'N/D'}`,
                        `Descripción: ${result.descripcion || 'N/D'}`,
                        `Precio ref.: ${result.precio_referencia ?? 'N/D'}`,
                        `Modo: ${result.modo || 'N/D'}`,
                        `Valor detectado: ${result.valor ?? 'N/D'}`,
                        `Raw producto: ${result.raw_producto || 'N/D'}`,
                        `Raw valor: ${result.raw_valor || 'N/D'}`
                    ].join('\n');
                    this.notify('success', 'Lectura exitosa', detalle);
                } else {
                    this.notify(
                        'warning',
                        'Sin coincidencia de balanza',
                        (result && result.error) ? result.error : 'El código no coincide con una configuración de balanza activa.'
                    );
                }
            } catch (e) {
                this.notify('error', 'Error de conexión', 'No se pudo conectar al servicio de balanzas.');
            }
        }
    };
}
</script>
</body>
</html>
