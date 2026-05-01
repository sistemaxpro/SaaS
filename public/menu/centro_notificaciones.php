<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Centro de Notificaciones</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    .smart-option { transition: background .15s ease; }
    .smart-option:hover { background: rgba(59,130,246,.10); }
  </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800" x-data="notifCenter()" x-init="init()" :class="theme === 'dark' ? 'dark bg-slate-950 text-slate-100' : ''">
  <div class="max-w-7xl mx-auto p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">Centro de Notificaciones</h1>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm opacity-80">
          <span class="font-semibold" x-text="sessionUserName || ('Usuario #' + idLogin)"></span>
          <span x-show="sessionLogin" class="opacity-70" x-text="'@' + sessionLogin"></span>
          <span class="hidden sm:inline opacity-50">·</span>
          <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-500/15 text-indigo-400" x-text="sessionPrivilegeLabel"></span>
          <span x-show="sessionRole && String(sessionRole).trim() !== '' && String(sessionPrivAdmin).toUpperCase() === 'Y'" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-400" x-text="sessionRole"></span>
          <span class="hidden sm:inline opacity-50">·</span>
          <span x-text="(sessionEmpresaNombre || 'Empresa') + ' · #' + idEmpresa"></span>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button class="px-3 py-2 rounded-lg bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900" @click="loadAll()">Actualizar</button>
      </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-2 lg:hidden">
      <button type="button"
              @click="mobileTab = 'notifications'"
              class="rounded-xl border px-3 py-2 text-sm font-semibold transition"
              :class="mobileTab === 'notifications'
                ? 'border-indigo-500 bg-indigo-500/15 text-indigo-300'
                : 'border-slate-800 bg-slate-900 text-slate-400'">
        Mis notificaciones
      </button>
      <button type="button"
              @click="mobileTab = 'users'"
              class="rounded-xl border px-3 py-2 text-sm font-semibold transition"
              :class="mobileTab === 'users'
                ? 'border-indigo-500 bg-indigo-500/15 text-indigo-300'
                : 'border-slate-800 bg-slate-900 text-slate-400'">
        Usuarios del proyecto
      </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
      <aside x-show="mobileTab === 'users' || window.innerWidth >= 1024" class="lg:col-span-4 rounded-xl border" :class="theme==='dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
        <div class="p-3 border-b" :class="theme==='dark' ? 'border-slate-800' : 'border-slate-200'">
          <div class="font-semibold">Usuarios del Proyecto</div>
          <div class="grid grid-cols-2 gap-2 mt-2">
            <select x-model.number="selectedCompany" @change="selectedUser=null; resetUsers()" class="px-3 py-2 rounded-lg border bg-transparent text-sm" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-300'">
              <option :value="0">Todas las empresas</option>
              <template x-for="c in companies" :key="'c-' + c.id_empresa">
                <option :value="c.id_empresa" x-text="c.empresa_nombre"></option>
              </template>
            </select>
            <input x-model="searchQ" @input="onSearchInput()" type="text" placeholder="Buscar..." class="px-3 py-2 rounded-lg border bg-transparent text-sm" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-300'">
          </div>
        </div>

        <div class="max-h-[70vh] overflow-auto p-2" @scroll.passive="onUsersScroll($event)">
          <template x-for="u in users" :key="u.id_login + '-' + u.id_empresa">
            <button type="button" class="w-full text-left p-2 rounded-xl smart-option mb-1" :class="selectedUser && selectedUser.id_login===u.id_login && selectedUser.id_empresa===u.id_empresa ? (theme==='dark' ? 'bg-indigo-500/20' : 'bg-indigo-100') : ''" @click="pickUser(u)">
              <div class="flex items-center gap-2">
                <div class="relative w-10 h-10 flex-shrink-0">
                  <template x-if="u.avatar_url">
                    <img :src="u.avatar_url" class="w-10 h-10 rounded-full object-cover border" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-200'" alt="avatar">
                  </template>
                  <template x-if="!u.avatar_url">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold" :class="theme==='dark' ? 'bg-slate-700 text-slate-100' : 'bg-slate-200 text-slate-700'" x-text="u.avatar_initials"></div>
                  </template>
                  <template x-if="u.empresa_logo">
                    <img :src="u.empresa_logo" class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full object-cover border bg-white" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-200'" alt="logo empresa">
                  </template>
                </div>
                <div class="min-w-0 flex-1">
                  <div class="text-sm font-semibold truncate" x-text="u.usuario_nombre"></div>
                  <div class="text-xs opacity-70 truncate" x-text="'@' + u.usuario_login"></div>
                  <div class="text-[11px] opacity-70 truncate" x-text="u.empresa_nombre"></div>
                </div>
              </div>
            </button>
          </template>

          <div x-show="usersLoading && users.length===0" class="px-3 py-4 text-sm opacity-70">Cargando usuarios...</div>
          <div x-show="usersLoadingMore" class="px-3 py-3 text-xs opacity-70">Cargando más...</div>
          <div x-show="!usersLoading && users.length===0" class="px-3 py-4 text-sm opacity-70">Sin resultados</div>
        </div>
      </aside>

      <section x-show="mobileTab === 'notifications' || window.innerWidth >= 1024" class="lg:col-span-5 rounded-xl border" :class="theme==='dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
        <div class="px-4 py-3 border-b" :class="theme==='dark' ? 'border-slate-800' : 'border-slate-200'">
          <h2 class="font-semibold">Mis Notificaciones</h2>
        </div>
        <div class="divide-y max-h-[70vh] overflow-auto" :class="theme==='dark' ? 'divide-slate-800' : 'divide-slate-100'">
          <template x-for="(n, idx) in notifications" :key="(n.id || 'n') + '-' + idx">
            <div class="px-4 py-3">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <div class="font-semibold truncate" x-text="n.title || 'Notificación'"></div>
                  <div class="text-sm opacity-80 mt-1" x-text="n.message || ''"></div>
                  <div class="mt-2 flex items-center gap-2 text-xs opacity-80">
                    <span x-show="n.is_system" class="px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400">Sistema</span>
                    <template x-if="n.origin_empresa_logo">
                      <img :src="n.origin_empresa_logo" alt="empresa" class="w-4 h-4 rounded object-cover border" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-200'">
                    </template>
                    <span x-text="n.origin_empresa_nombre || ''"></span>
                    <span>·</span>
                    <template x-if="n.origin_usuario_avatar">
                      <img :src="n.origin_usuario_avatar" alt="usuario" class="w-5 h-5 rounded-full object-cover border" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-200'">
                    </template>
                    <template x-if="!n.origin_usuario_avatar">
                      <span class="w-5 h-5 rounded-full inline-flex items-center justify-center text-[10px] font-bold" :class="theme==='dark' ? 'bg-slate-700 text-slate-100' : 'bg-slate-200 text-slate-700'" x-text="n.origin_usuario_initials || 'U'"></span>
                    </template>
                    <span x-text="n.origin_usuario_nombre || 'Sistema'"></span>
                  </div>
                  <a x-show="n.url" :href="n.url" class="text-xs text-indigo-500 hover:underline mt-1 inline-block">Abrir</a>
                </div>
                <div class="text-xs opacity-60 whitespace-nowrap" x-text="n.time || ''"></div>
              </div>
            </div>
          </template>
          <div x-show="notifications.length===0" class="px-4 py-10 text-center opacity-70">Sin notificaciones para este usuario.</div>
        </div>
      </section>

      <section class="lg:col-span-3 rounded-xl border p-4" :class="theme==='dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold">Enviar Notificación</h2>
        </div>
        <div>
          <div class="p-3 rounded-lg mb-3" :class="theme==='dark' ? 'bg-slate-800' : 'bg-slate-50'" x-show="selectedUser">
            <div class="text-xs opacity-70">Destino</div>
            <div class="font-semibold" x-text="selectedUser ? selectedUser.usuario_nombre : ''"></div>
            <div class="text-xs opacity-70" x-text="selectedUser ? (selectedUser.empresa_nombre + ' · @' + selectedUser.usuario_login) : ''"></div>
          </div>

          <div class="grid grid-cols-1 gap-2">
            <input x-model="form.titulo" type="text" maxlength="150" placeholder="Título" class="px-3 py-2 rounded-lg border bg-transparent" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-300'">
            <textarea x-model="form.mensaje" rows="5" placeholder="Mensaje" class="px-3 py-2 rounded-lg border bg-transparent" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-300'"></textarea>
            <div class="grid grid-cols-2 gap-2">
              <select x-model="form.tipo" class="px-3 py-2 rounded-lg border bg-transparent" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-300'">
                <option value="info">Info</option>
                <option value="success">Success</option>
                <option value="warning">Warning</option>
                <option value="error">Error</option>
              </select>
              <input x-model="form.url" type="text" maxlength="255" placeholder="URL opcional" class="px-3 py-2 rounded-lg border bg-transparent" :class="theme==='dark' ? 'border-slate-700' : 'border-slate-300'">
            </div>
          </div>

          <button class="mt-3 w-full px-3 py-2 rounded-lg bg-indigo-600 text-white disabled:opacity-50" :disabled="sending || !canSend" @click="sendNotification()" x-text="sending ? 'Enviando...' : 'Enviar Notificación'"></button>
        </div>
      </section>
    </div>
  </div>

<script>
function notifCenter() {
  return {
    idEmpresa: <?= (int)$idEmpresa ?>,
    idLogin: <?= (int)$idLogin ?>,
    theme: 'dark',
    isAdmin: false,
    sessionUserName: '',
    sessionLogin: '',
    sessionRole: '',
    sessionPrivAdmin: 'N',
    sessionEmpresaNombre: '',
    notifications: [],
    users: [],
    companies: [],
    selectedUser: null,
    mobileTab: 'notifications',
    selectedCompany: 0,
    searchQ: '',
    searchTimer: null,
    sending: false,
    usersOffset: 0,
    usersLimit: 60,
    usersHasMore: true,
    usersLoading: false,
    usersLoadingMore: false,
    form: {
      titulo: 'Notificación',
      mensaje: '',
      tipo: 'info',
      url: ''
    },

    get canSend() {
      return this.selectedUser && String(this.form.mensaje || '').trim() !== '';
    },

    get sessionPrivilegeLabel() {
      if (String(this.sessionPrivAdmin || '').toUpperCase() === 'Y') return 'Administrador';
      const role = String(this.sessionRole || '').trim();
      return role !== '' ? role : 'Usuario';
    },

    async init() {
      await this.loadMeta();
      await this.loadNotifications();
      await this.loadCompanies();
      await this.resetUsers();
      setInterval(() => this.loadNotifications(), 10000);
    },

    async loadMeta() {
      try {
        const res = await fetch(`/public/menu/api/centro_notificaciones.php?action=meta&id_empresa=${this.idEmpresa}&_ts=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        this.isAdmin = !!(data && data.success && data.meta && data.meta.is_admin);
        const meta = (data && data.success && data.meta) ? data.meta : {};
        this.sessionUserName = String(meta.usuario_nombre || '');
        this.sessionLogin = String(meta.usuario_login || '');
        this.sessionRole = String(meta.role || '');
        this.sessionPrivAdmin = String(meta.priv_admin || 'N');
        this.sessionEmpresaNombre = String(meta.empresa_nombre || '');
      } catch (_) {
        this.isAdmin = false;
        this.sessionUserName = '';
        this.sessionLogin = '';
        this.sessionRole = '';
        this.sessionPrivAdmin = 'N';
        this.sessionEmpresaNombre = '';
      }
    },

    async loadCompanies() {
      try {
        const res = await fetch(`/public/menu/api/centro_notificaciones.php?action=companies&id_empresa=${this.idEmpresa}&_ts=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        this.companies = (data && data.success && Array.isArray(data.items)) ? data.items : [];
      } catch (_) {
        this.companies = [];
      }
    },

    async loadNotifications() {
      try {
        const res = await fetch(`/public/menu/api/notificaciones.php?id_empresa=${this.idEmpresa}&_ts=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        this.notifications = (data && data.success && Array.isArray(data.notifications)) ? data.notifications : [];
      } catch (_) {
        this.notifications = [];
      }
    },

    async resetUsers() {
      this.users = [];
      this.usersOffset = 0;
      this.usersHasMore = true;
      await this.loadUsers(false);
    },

    async loadUsers(append = false) {
      if (!this.usersHasMore && append) return;
      if (append) this.usersLoadingMore = true; else this.usersLoading = true;
      try {
        const q = encodeURIComponent(this.searchQ || '');
        const empresaFilter = Number(this.selectedCompany || 0);
        const url = `/public/menu/api/centro_notificaciones.php?action=users&id_empresa=${this.idEmpresa}&id_empresa_filter=${empresaFilter}&q=${q}&offset=${this.usersOffset}&limit=${this.usersLimit}&_ts=${Date.now()}`;
        const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        const batch = (data && data.success && Array.isArray(data.items)) ? data.items : [];
        if (append) {
          this.users = this.users.concat(batch);
        } else {
          this.users = batch;
        }
        const paging = (data && data.paging) ? data.paging : null;
        this.usersHasMore = !!(paging && paging.has_more);
        this.usersOffset = (paging && Number.isFinite(Number(paging.next_offset))) ? Number(paging.next_offset) : (this.usersOffset + batch.length);
      } catch (_) {
        if (!append) this.users = [];
        this.usersHasMore = false;
      } finally {
        this.usersLoading = false;
        this.usersLoadingMore = false;
      }
    },

    onUsersScroll(event) {
      const el = event && event.target ? event.target : null;
      if (!el || this.usersLoadingMore || this.usersLoading || !this.usersHasMore) return;
      const nearBottom = (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 80);
      if (!nearBottom) return;
      this.loadUsers(true);
    },

    onSearchInput() {
      if (this.searchTimer) clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => {
        this.resetUsers();
      }, 260);
    },

    pickUser(u) {
      this.selectedUser = u || null;
    },

    async sendNotification() {
      if (!this.canSend || this.sending) return;
      this.sending = true;
      try {
        const res = await fetch(`/public/menu/api/centro_notificaciones.php?action=send&id_empresa=${this.idEmpresa}`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            id_dest_login: Number(this.selectedUser.id_login || 0),
            id_dest_empresa: Number(this.selectedUser.id_empresa || 0),
            titulo: this.form.titulo || 'Notificación',
            mensaje: this.form.mensaje || '',
            tipo: this.form.tipo || 'info',
            url: this.form.url || ''
          })
        });
        const data = await res.json();
        if (!data || !data.success) throw new Error((data && (data.error || data.message)) || 'No se pudo enviar');
        this.form.mensaje = '';
        this.form.url = '';
        alert('Notificación enviada correctamente');
      } catch (e) {
        alert(e.message || 'No se pudo enviar la notificación');
      } finally {
        this.sending = false;
      }
    },

    async loadAll() {
      await this.loadMeta();
      await this.loadNotifications();
      await this.loadCompanies();
      await this.resetUsers();
    }
  };
}
</script>
</body>
</html>
