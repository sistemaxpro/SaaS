<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

$idEmpresa = (int)Session::getIdEmpresa();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
$allowed = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Config <?= htmlspecialchars('SistemaX Assist', ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="supportDesktopConfig()" x-init="init()">
<div class="max-w-5xl mx-auto p-4 md:p-6">
    <div class="flex items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold">Config SistemaX Assist</h1>
            <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · #<?= (int)$idEmpresa ?> · Usuario <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Cerrar</button>
    </div>

    <?php if (!$allowed): ?>
        <div class="rounded-2xl border border-red-800 bg-red-950/40 p-5 text-red-200">Acceso reservado a soporte técnico de empresas <?= htmlspecialchars(implode(', ', SISTEMAX_SUPPORT_COMPANIES), ENT_QUOTES, 'UTF-8') ?>.</div>
    <?php else: ?>
    <div class="space-y-4">
        <div x-show="message" class="rounded-2xl border border-emerald-700/40 bg-emerald-900/20 px-4 py-3 text-emerald-200 text-sm" x-text="message"></div>
        <div x-show="error" class="rounded-2xl border border-rose-700/40 bg-rose-900/20 px-4 py-3 text-rose-200 text-sm" x-text="error"></div>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5 space-y-4">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Documentación</label>
                <input x-model="form.support_remote_docs_url" type="url" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="/public/soporte/index.php">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Host / Label</label>
                <input x-model="form.support_remote_host_label" type="text" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="Assist interno / entorno soporte">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Portal de instalación</label>
                <input x-model="form.support_remote_server_url" type="text" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="/public/soporte/index.php">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Consola remota / URL de acceso</label>
                <input x-model="form.support_remote_access_url" type="text" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="/public/soporte/assist_admin.php">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Usuario operativo</label>
                <input x-model="form.support_dwservice_username" type="text" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="usuario o alias interno de soporte">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Clave temporal / referencia de instalación</label>
                <input x-model="form.support_dwservice_install_password" type="text" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="opcional para registrar la sesión">
            </div>
            <div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 text-sm text-slate-300">
                <div class="font-semibold text-slate-100">Uso recomendado</div>
                <div class="mt-2">1. El soporte queda orientado a `SistemaX Assist` nativo.</div>
                <div>2. Cargá portal, consola y usuario operativo.</div>
                <div>3. Después abrí `SistemaX Assist` y `Mesa Soporte` para validar el flujo real.</div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">URL Windows</label>
                    <input x-model="form.support_desktop_windows_url" type="url" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="/public/soporte/downloads/sistemax-support-desktop-windows-0.1.0.exe">
                    <div class="mt-2 flex items-center gap-2">
                        <button @click="testUrl('support_desktop_windows_url')" type="button" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs">Probar</button>
                        <span class="text-xs" :class="testClass('support_desktop_windows_url')" x-text="testLabel('support_desktop_windows_url')"></span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">URL macOS</label>
                    <input x-model="form.support_desktop_macos_url" type="url" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="/public/soporte/downloads/sistemax-support-desktop-macos-0.1.0.tar.gz">
                    <div class="mt-2 flex items-center gap-2">
                        <button @click="testUrl('support_desktop_macos_url')" type="button" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs">Probar</button>
                        <span class="text-xs" :class="testClass('support_desktop_macos_url')" x-text="testLabel('support_desktop_macos_url')"></span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">URL Linux</label>
                    <input x-model="form.support_desktop_linux_url" type="url" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm" placeholder="/public/soporte/downloads/sistemax-support-desktop-linux-0.1.0.tar.gz">
                    <div class="mt-2 flex items-center gap-2">
                        <button @click="testUrl('support_desktop_linux_url')" type="button" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs">Probar</button>
                        <span class="text-xs" :class="testClass('support_desktop_linux_url')" x-text="testLabel('support_desktop_linux_url')"></span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button @click="save()" :disabled="saving" class="px-4 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-sm font-semibold">
                    <span x-text="saving ? 'Guardando...' : 'Guardar configuración'"></span>
                </button>
                <button @click="testAll()" :disabled="testingAll" class="px-4 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 disabled:opacity-50 text-sm">
                    <span x-text="testingAll ? 'Probando...' : 'Probar todas las URLs'"></span>
                </button>
                <a href="/public/apps-moviles.php#soporte-desktop" target="_blank" rel="noopener" class="px-4 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Ver Centro de Apps</a>
            </div>
        </section>
    </div>
    <?php endif; ?>
</div>
<script>
function supportDesktopConfig() {
    return {
        form: {
            support_remote_docs_url: '',
            support_remote_host_label: '',
            support_remote_server_url: '',
            support_remote_access_url: '',
            support_dwservice_username: '',
            support_dwservice_install_password: '',
            support_desktop_windows_url: '',
            support_desktop_macos_url: '',
            support_desktop_linux_url: '',
        },
        saving: false,
        testingAll: false,
        message: '',
        error: '',
        tests: {},

        async init() {
            try {
                const res = await fetch('/public/soporte/api.php?action=admin_get_settings', { credentials: 'same-origin' });
                const data = await res.json();
                if (!data?.ok) throw new Error(data?.error || 'No se pudo cargar configuración');
                this.form = { ...this.form, ...(data.settings || {}) };
            } catch (e) {
                this.error = e.message || 'No se pudo cargar configuración';
            }
        },

        async save() {
            this.saving = true;
            this.message = '';
            this.error = '';
            try {
                const res = await fetch('/public/soporte/api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'admin_save_settings', settings: this.form })
                });
                const data = await res.json();
                if (!data?.ok) throw new Error(data?.error || 'No se pudo guardar');
                this.message = data.message || 'Configuración guardada.';
            } catch (e) {
                this.error = e.message || 'No se pudo guardar';
            }
            this.saving = false;
        },

        testLabel(key) {
            const t = this.tests[key];
            if (!t) return '';
            if (t.loading) return 'probando...';
            if (t.ok) return 'HTTP ' + t.http_code;
            if (t.http_code) return 'HTTP ' + t.http_code;
            return t.error || 'error';
        },

        testClass(key) {
            const t = this.tests[key];
            if (!t) return 'text-slate-500';
            if (t.loading) return 'text-amber-300';
            return t.ok ? 'text-emerald-300' : 'text-rose-300';
        },

        async testUrl(key) {
            const url = (this.form[key] || '').trim();
            if (!url) {
                this.tests[key] = { ok: false, error: 'sin URL' };
                return;
            }
            this.tests[key] = { loading: true };
            try {
                const res = await fetch('/public/soporte/api.php?action=admin_test_url&url=' + encodeURIComponent(url), { credentials: 'same-origin' });
                const data = await res.json();
                this.tests[key] = data || { ok: false, error: 'sin respuesta' };
            } catch (e) {
                this.tests[key] = { ok: false, error: e.message || 'error' };
            }
        },

        async testAll() {
            this.testingAll = true;
            await this.testUrl('support_desktop_windows_url');
            await this.testUrl('support_desktop_macos_url');
            await this.testUrl('support_desktop_linux_url');
            this.testingAll = false;
        }
    };
}
</script>
</body>
</html>
