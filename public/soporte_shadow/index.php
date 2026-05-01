<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
$canSupport = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true) && Session::isAdmin();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Modo Soporte Auditado</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="shadowApp()" x-init="init()">
<div class="max-w-6xl mx-auto p-4 md:p-6">
  <div class="flex items-center justify-between gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-bold">Modo Soporte Auditado</h1>
      <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · #<?= (int)$idEmpresa ?> · Usuario <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Cerrar</button>
  </div>

  <?php if (!$canSupport): ?>
    <div class="rounded-2xl border border-red-800 bg-red-950/40 p-5 text-red-200">Acceso restringido a administradores de soporte de empresas <?= htmlspecialchars(implode(', ', SISTEMAX_SUPPORT_COMPANIES), ENT_QUOTES, 'UTF-8') ?>.</div>
  <?php else: ?>
  <div class="grid grid-cols-1 xl:grid-cols-[380px_1fr] gap-4">
    <aside class="space-y-4">
      <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
        <h2 class="font-semibold mb-3">Entrar Como</h2>
        <div class="space-y-3">
          <div>
            <label class="block text-xs text-slate-400 mb-1">Empresa</label>
            <input x-model="companySearch" @input.debounce.250ms="searchCompanies()" type="text" placeholder="Buscar empresa..." class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm">
            <div x-show="companyOptions.length" class="mt-2 max-h-56 overflow-auto rounded-xl border border-slate-800 bg-slate-950">
              <template x-for="item in companyOptions" :key="'co-' + item.id_empresa">
                <button type="button" @click="pickCompany(item)" class="w-full text-left px-3 py-2 hover:bg-slate-800 text-sm border-b border-slate-800 last:border-b-0">
                  <div class="flex items-center justify-between gap-2">
                    <span x-text="item.empresa"></span>
                    <span class="text-[10px] px-2 py-0.5 rounded-full" :class="String(item.active||'Y').toUpperCase()==='Y' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'" x-text="String(item.active||'Y').toUpperCase()==='Y' ? 'Activa' : 'Inactiva'"></span>
                  </div>
                </button>
              </template>
            </div>
          </div>
          <div x-show="selectedCompany.id_empresa" class="rounded-xl bg-sky-500/10 border border-sky-500/20 p-3 text-sm">
            <div class="flex items-center justify-between gap-2">
              <div class="font-semibold" x-text="selectedCompany.empresa"></div>
              <span class="text-[10px] px-2 py-0.5 rounded-full" :class="String(selectedCompany.active||'Y').toUpperCase()==='Y' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'" x-text="String(selectedCompany.active||'Y').toUpperCase()==='Y' ? 'Activa' : 'Inactiva'"></span>
            </div>
            <div class="text-xs text-slate-400" x-text="(selectedCompany.empresa || 'Empresa') + ' · #' + selectedCompany.id_empresa"></div>
          </div>
          <div>
            <label class="block text-xs text-slate-400 mb-1">Usuario</label>
            <input x-model="userSearch" @input.debounce.250ms="searchUsers()" type="text" :disabled="!selectedCompany.id_empresa" placeholder="Buscar usuario..." class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm disabled:opacity-50">
            <div x-show="userOptions.length" class="mt-2 max-h-64 overflow-auto rounded-xl border border-slate-800 bg-slate-950">
              <template x-for="item in userOptions" :key="'us-' + item.id_login">
                <button type="button" @click="pickUser(item)" class="w-full text-left px-3 py-2 hover:bg-slate-800 text-sm border-b border-slate-800 last:border-b-0">
                  <div class="flex items-center justify-between gap-2">
                    <div class="font-medium" x-text="item.name || item.login"></div>
                    <div class="flex items-center gap-1">
                      <span x-show="String(item.priv_admin||'N').toUpperCase()==='Y'" class="text-[10px] px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300">Admin</span>
                      <span class="text-[10px] px-2 py-0.5 rounded-full" :class="String(item.active||'Y').toUpperCase()==='Y' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'" x-text="String(item.active||'Y').toUpperCase()==='Y' ? 'Activo' : 'Inactivo'"></span>
                    </div>
                  </div>
                  <div class="text-xs text-slate-400" x-text="'@' + item.login + (item.role ? ' · ' + item.role : '')"></div>
                </button>
              </template>
            </div>
          </div>
          <div x-show="selectedUser.id_login" class="rounded-xl bg-emerald-500/10 border border-emerald-500/20 p-3 text-sm">
            <div class="flex items-center justify-between gap-2">
              <div class="font-semibold" x-text="selectedUser.name || selectedUser.login"></div>
              <div class="flex items-center gap-1">
                <span x-show="String(selectedUser.priv_admin||'N').toUpperCase()==='Y'" class="text-[10px] px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300">Admin</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full" :class="String(selectedUser.active||'Y').toUpperCase()==='Y' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'" x-text="String(selectedUser.active||'Y').toUpperCase()==='Y' ? 'Activo' : 'Inactivo'"></span>
              </div>
            </div>
            <div class="text-xs text-slate-400" x-text="'@' + selectedUser.login"></div>
          </div>
          <button @click="startShadow()" :disabled="starting || !selectedCompany.id_empresa || !selectedUser.id_login" class="w-full px-4 py-3 rounded-xl bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-slate-950 font-semibold text-sm">
            <span x-text="starting ? 'Ingresando...' : 'Entrar con contexto del cliente'"></span>
          </button>
        </div>
      </div>
    </aside>

    <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
      <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="font-semibold">Estado</h2>
        <button x-show="shadowActive" @click="stopShadow()" :disabled="stopping" class="px-3 py-2 rounded-xl bg-red-600 hover:bg-red-700 disabled:opacity-50 text-sm">
          <span x-text="stopping ? 'Restaurando...' : 'Restaurar sesión original'"></span>
        </button>
      </div>

      <div x-show="!shadowActive" class="rounded-2xl border border-slate-800 bg-slate-950 p-5 text-slate-400">
        No hay una sesión suplantada activa. Elegí empresa y usuario para entrar en modo soporte auditado.
      </div>

      <div x-show="shadowActive" class="space-y-4">
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5">
          <div class="text-sm text-amber-200">Sesión auditada activa</div>
          <div class="mt-1 text-xl font-bold text-white" x-text="shadow.target_company_name + ' · ' + shadow.target_name"></div>
          <div class="text-sm text-slate-300" x-text="'@' + shadow.target_login + ' · iniciado por ' + shadow.support_name"></div>
          <div class="text-xs text-slate-400 mt-2" x-text="'Inicio: ' + (shadow.started_at || '-')"></div>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-950 p-5 text-sm text-slate-300 space-y-2">
          <div>Este modo no es anónimo. Queda rastro en auditoría.</div>
          <div>Se recomienda usarlo para verificar pantallas, flujo y permisos.</div>
          <div>Las operaciones sensibles siguen ejecutándose como ese usuario. Usalo con criterio.</div>
        </div>
      </div>
    </section>
  </div>
  <?php endif; ?>
</div>

<script>
function shadowApp() {
  return {
    companySearch: '',
    companyOptions: [],
    selectedCompany: {},
    userSearch: '',
    userOptions: [],
    selectedUser: {},
    shadowActive: false,
    shadow: {},
    starting: false,
    stopping: false,

    async init() {
      await this.loadMeta();
      if (!this.shadowActive) {
        this.searchCompanies();
      }
    },

    async loadMeta() {
      const res = await fetch('api.php?action=meta', { credentials: 'same-origin' });
      const data = await res.json();
      this.shadowActive = !!data?.shadow_active;
      this.shadow = data?.shadow || {};
    },

    async searchCompanies() {
      const res = await fetch('api.php?action=companies&q=' + encodeURIComponent(this.companySearch || ''), { credentials: 'same-origin' });
      const data = await res.json();
      this.companyOptions = data?.items || [];
    },

    pickCompany(item) {
      this.selectedCompany = item || {};
      this.selectedUser = {};
      this.userSearch = '';
      this.userOptions = [];
    },

    async searchUsers() {
      if (!this.selectedCompany.id_empresa) {
        this.userOptions = [];
        return;
      }
      const res = await fetch(`api.php?action=users&id_empresa=${encodeURIComponent(this.selectedCompany.id_empresa)}&q=${encodeURIComponent(this.userSearch || '')}`, { credentials: 'same-origin' });
      const data = await res.json();
      this.userOptions = data?.items || [];
    },

    pickUser(item) {
      this.selectedUser = item || {};
    },

    async startShadow() {
      if (!this.selectedCompany.id_empresa || !this.selectedUser.id_login || this.starting) return;
      this.starting = true;
      try {
        const res = await fetch('api.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'start',
            target_company_id: Number(this.selectedCompany.id_empresa),
            target_login_id: Number(this.selectedUser.id_login)
          })
        });
        const data = await res.json();
        if (!data?.ok) throw new Error(data?.error || 'No se pudo iniciar modo soporte');
        window.location.href = data.redirect || '/public/menu/menu.php';
      } catch (e) {
        alert(e.message || 'No se pudo iniciar modo soporte');
      }
      this.starting = false;
    },

    async stopShadow() {
      if (this.stopping) return;
      this.stopping = true;
      try {
        const res = await fetch('api.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'stop' })
        });
        const data = await res.json();
        if (!data?.ok) throw new Error(data?.error || 'No se pudo restaurar la sesión');
        window.location.href = data.redirect || '/public/soporte_shadow/index.php';
      } catch (e) {
        alert(e.message || 'No se pudo restaurar la sesión');
      }
      this.stopping = false;
    }
  };
}
</script>
</body>
</html>
