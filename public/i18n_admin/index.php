<?php

require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::get('id_empresa');
$isAdmin = Session::isAdmin() || !empty($_SESSION['usr_ti']);
if ($idEmpresa !== 169 || !$isAdmin) {
    http_response_code(403);
    echo 'Acceso restringido';
    exit;
}

SmxI18n::ensureDbOverridesTable();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(SmxI18n::getLocale()) ?>" x-data="i18nAdminApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>I18N Studio - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto px-4 py-5">
    <div class="flex items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold">I18N Studio</h1>
            <p class="text-slate-400 text-sm">Overrides programaticos de traduccion para todo el sistema</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold">Volver</button>
            <button @click="reloadAll()" class="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold">Actualizar</button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 space-y-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <select x-model="filters.locale" @change="applyLocaleSelection()" class="px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-sm">
                        <template x-for="locale in locales" :key="locale.code">
                            <option :value="locale.code" x-text="locale.label"></option>
                        </template>
                    </select>
                    <input x-model="filters.search" @input.debounce.250ms="loadOverrides(); loadCatalog()" type="text" placeholder="Buscar clave o valor..."
                           class="md:col-span-2 px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-sm">
                    <div class="px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-sm text-slate-300" x-text="overrides.length + ' overrides'"></div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="font-semibold">Overrides guardados</h2>
                    <span class="text-xs text-slate-400">Se aplican en runtime</span>
                </div>
                <div class="overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-950 text-slate-400">
                        <tr>
                            <th class="text-left px-3 py-2">Clave</th>
                            <th class="text-left px-3 py-2">Valor</th>
                            <th class="text-left px-3 py-2">Locale</th>
                            <th class="text-left px-3 py-2">Estado</th>
                            <th class="text-right px-3 py-2">Accion</th>
                        </tr>
                        </thead>
                        <tbody>
                        <template x-for="row in overrides" :key="row.id">
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 font-mono text-xs text-cyan-300" x-text="row.message_key"></td>
                                <td class="px-3 py-2">
                                    <div class="max-w-xl truncate" :title="row.override_value" x-text="row.override_value"></div>
                                    <div x-show="row.notes" class="text-xs text-slate-500 mt-1" x-text="row.notes"></div>
                                </td>
                                <td class="px-3 py-2 uppercase" x-text="row.locale"></td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-1 rounded text-xs font-semibold" :class="Number(row.active) === 1 ? 'bg-emerald-900/50 text-emerald-300' : 'bg-slate-800 text-slate-400'" x-text="Number(row.active) === 1 ? 'Activo' : 'Inactivo'"></span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex gap-2">
                                        <button @click="editOverride(row)" class="px-2 py-1 rounded bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold">Editar</button>
                                        <button @click="deleteOverride(row)" class="px-2 py-1 rounded bg-red-700 hover:bg-red-600 text-white text-xs font-semibold">Desactivar</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="!overrides.length">
                            <td colspan="5" class="px-3 py-8 text-center text-slate-500">Sin overrides cargados</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="font-semibold">Catalogo base de claves</h2>
                    <span class="text-xs text-slate-400" x-text="catalog.length + ' claves'"></span>
                </div>
                <div class="max-h-[420px] overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-950 text-slate-400">
                        <tr>
                            <th class="text-left px-3 py-2">Clave</th>
                            <th class="text-left px-3 py-2">Base</th>
                            <th class="text-right px-3 py-2">Accion</th>
                        </tr>
                        </thead>
                        <tbody>
                        <template x-for="row in catalog" :key="row.message_key">
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-2 font-mono text-xs text-amber-300" x-text="row.message_key"></td>
                                <td class="px-3 py-2" x-text="row.base_value"></td>
                                <td class="px-3 py-2 text-right">
                                    <button @click="useCatalogKey(row)" class="px-2 py-1 rounded bg-cyan-700 hover:bg-cyan-600 text-white text-xs font-semibold">Usar</button>
                                </td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <h2 class="font-semibold mb-3">Editor override</h2>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Locale</label>
                        <select x-model="form.locale" class="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-sm">
                            <template x-for="locale in locales" :key="locale.code">
                                <option :value="locale.code" x-text="locale.label"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Clave</label>
                        <input x-model="form.message_key" type="text" placeholder="menu_apps.mi_caja"
                               class="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Valor override</label>
                        <textarea x-model="form.override_value" rows="4" placeholder="My POS"
                                  class="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Notas</label>
                        <input x-model="form.notes" type="text" placeholder="Terminologia comercial aprobada"
                               class="w-full px-3 py-2 rounded-xl bg-slate-950 border border-slate-700 text-sm">
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                        <input x-model="form.active" type="checkbox" class="rounded border-slate-600 bg-slate-950">
                        Activo
                    </label>
                    <div x-show="message" class="px-3 py-2 rounded-lg bg-emerald-900/40 border border-emerald-700 text-emerald-200 text-xs" x-text="message"></div>
                    <div x-show="error" class="px-3 py-2 rounded-lg bg-red-900/40 border border-red-700 text-red-200 text-xs" x-text="error"></div>
                    <div class="flex gap-2">
                        <button @click="resetForm()" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold">Nuevo</button>
                        <button @click="saveOverride()" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">Guardar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function i18nAdminApp() {
    return {
        locales: [
            { code: 'es', label: 'Espanol' },
            { code: 'en', label: 'English' },
            { code: 'pt', label: 'Portugues' }
        ],
        filters: { locale: 'en', search: '' },
        overrides: [],
        catalog: [],
        form: { id: null, locale: 'en', message_key: '', override_value: '', notes: '', active: true },
        message: '',
        error: '',
        async init() {
            this.filters.locale = (window.SmxI18n?.getLocale?.() || 'en').toLowerCase();
            this.form.locale = this.filters.locale;
            await this.reloadAll();
        },
        async applyLocaleSelection() {
            this.form.locale = this.filters.locale;
            try {
                if (window.SmxI18n?.setLocale) {
                    await window.SmxI18n.setLocale(this.filters.locale);
                }
                if (window.parent && window.parent !== window && window.parent.SmxI18n?.setLocale) {
                    await window.parent.SmxI18n.setLocale(this.filters.locale, { sync: false });
                }
            } catch (error) {}
            await this.reloadAll();
        },
        async reloadAll() {
            await Promise.all([this.loadOverrides(), this.loadCatalog()]);
        },
        refreshShell() {
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.location.reload();
                    return;
                }
            } catch (error) {}
            window.location.reload();
        },
        async api(action, payload = {}, method = 'POST') {
            const url = new URL('/public/i18n_admin/api.php', window.location.origin);
            url.searchParams.set('action', action);
            if (method === 'GET') {
                Object.entries(payload || {}).forEach(([key, value]) => url.searchParams.set(key, value));
                const res = await fetch(url, { credentials: 'same-origin' });
                return res.json();
            }
            const res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {})
            });
            return res.json();
        },
        async loadOverrides() {
            const data = await this.api('list', { locale: this.filters.locale, search: this.filters.search }, 'GET');
            this.overrides = data.success ? (data.data || []) : [];
        },
        async loadCatalog() {
            const data = await this.api('catalog', { locale: this.filters.locale, search: this.filters.search }, 'GET');
            this.catalog = data.success ? (data.data || []) : [];
        },
        resetForm() {
            this.form = { id: null, locale: this.filters.locale, message_key: '', override_value: '', notes: '', active: true };
            this.message = '';
            this.error = '';
        },
        editOverride(row) {
            this.form = {
                id: row.id,
                locale: row.locale,
                message_key: row.message_key,
                override_value: row.override_value,
                notes: row.notes || '',
                active: Number(row.active) === 1
            };
            this.message = '';
            this.error = '';
        },
        useCatalogKey(row) {
            this.form.locale = this.filters.locale;
            this.form.message_key = row.message_key;
            if (!this.form.override_value) this.form.override_value = row.base_value || '';
        },
        async saveOverride() {
            this.message = '';
            this.error = '';
            const payload = {
                id: this.form.id,
                locale: this.form.locale,
                message_key: this.form.message_key,
                override_value: this.form.override_value,
                notes: this.form.notes,
                active: this.form.active ? 1 : 0
            };
            const data = await this.api('save', payload);
            if (!data.success) {
                this.error = data.error || 'No se pudo guardar';
                return;
            }
            this.message = data.message || 'Guardado';
            this.filters.locale = this.form.locale;
            await this.reloadAll();
            const currentLocale = (window.SmxI18n?.getLocale?.() || '').toLowerCase();
            if (currentLocale === String(this.form.locale || '').toLowerCase()) {
                setTimeout(() => this.refreshShell(), 350);
            }
            this.resetForm();
        },
        async deleteOverride(row) {
            if (!confirm(`Desactivar override ${row.message_key}?`)) return;
            const data = await this.api('delete', { id: row.id });
            if (!data.success) {
                this.error = data.error || 'No se pudo desactivar';
                return;
            }
            this.message = data.message || 'Desactivado';
            await this.reloadAll();
        }
    };
}
</script>
</body>
</html>
