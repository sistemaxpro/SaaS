<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('configuracion');
$permisos = Permission::getAppPermissions('configuracion');
$id_empresa = (int)(Session::getIdEmpresa() ?: ($_SESSION['id_empresa'] ?? 0));
$empresaNombre = (string)($_SESSION['empresa_nombre'] ?? 'Empresa');
?>
<!doctype html>
<html lang="es" x-data="sucursalesApp()" x-init="init()">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sucursales</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script>
    tailwind.config = { darkMode: 'class' };
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    }
  </script>
  <style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-gray-50 dark:bg-slate-900 min-h-screen text-gray-900 dark:text-gray-100">
  <div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">
    <div class="flex items-center justify-between gap-3 mb-5">
      <div class="flex items-center gap-3">
        <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){}window.location.href='/public/menu/menu.php';"
          class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 text-red-600 dark:text-red-400 hover:bg-red-600/20">
          <i class="fa-solid fa-arrow-left"></i>
        </button>
        <div>
          <h1 class="text-xl sm:text-2xl font-bold"><i class="fa-solid fa-building mr-2 text-blue-500"></i>Sucursales</h1>
          <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($empresaNombre) ?></p>
        </div>
      </div>
    </div>

    <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-3 sm:p-4 mb-4">
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex-1 min-w-[220px] relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
          <input x-model="search" @input.debounce.300ms="page=1;load()" placeholder="Buscar sucursal, ciudad, dirección..."
                 class="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
        </div>
        <select x-model="estado" @change="page=1;load()" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
          <option value="">Todos</option>
          <option value="1">Activas</option>
          <option value="0">Inactivas</option>
        </select>
        <button x-show="permisos.priv_insert==='Y'" @click="nuevo()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold">
          <i class="fas fa-plus mr-1"></i>Nueva
        </button>
      </div>
    </div>

    <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden">
      <div x-show="loading" class="p-8 text-center text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando...</div>
      <div class="overflow-x-auto" x-show="!loading" x-cloak>
        <table class="min-w-full">
          <thead class="bg-gray-50 dark:bg-slate-700/50 text-xs uppercase text-gray-500 dark:text-gray-300">
            <tr>
              <th class="px-4 py-3 text-left">ID</th>
              <th class="px-4 py-3 text-left">Sucursal</th>
              <th class="px-4 py-3 text-left">Ciudad</th>
              <th class="px-4 py-3 text-left">Dirección</th>
              <th class="px-4 py-3 text-left">Teléfono</th>
              <th class="px-4 py-3 text-center">Estado</th>
              <th class="px-4 py-3 text-center">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
            <template x-for="s in rows" :key="s.id_sucursal">
              <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/30">
                <td class="px-4 py-3" x-text="s.id_sucursal"></td>
                <td class="px-4 py-3 font-semibold" x-text="s.sucursal"></td>
                <td class="px-4 py-3" x-text="s.ciudad || '-' "></td>
                <td class="px-4 py-3" x-text="s.direccion || '-' "></td>
                <td class="px-4 py-3" x-text="s.telefono || '-' "></td>
                <td class="px-4 py-3 text-center">
                  <span class="px-2 py-1 text-xs rounded-full" :class="Number(s.activo)===1 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'" x-text="Number(s.activo)===1 ? 'Activa' : 'Inactiva'"></span>
                </td>
                <td class="px-4 py-3 text-center">
                  <div class="inline-flex items-center gap-1">
                    <button x-show="permisos.priv_update==='Y'" @click="editar(s)" class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600"><i class="fas fa-edit"></i></button>
                    <button x-show="permisos.priv_delete==='Y'" @click="eliminar(s)" class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600"><i class="fas fa-trash"></i></button>
                  </div>
                </td>
              </tr>
            </template>
            <tr x-show="rows.length===0"><td colspan="7" class="p-8 text-center text-gray-500">Sin sucursales</td></tr>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3 bg-gray-50 dark:bg-slate-700/40 border-t border-gray-200 dark:border-slate-700 flex items-center justify-between text-sm">
        <div>Total: <strong x-text="total"></strong></div>
        <div class="inline-flex items-center gap-2">
          <button @click="prevPage()" :disabled="page<=1" class="px-3 py-1 rounded border border-gray-300 dark:border-slate-600 disabled:opacity-40"><i class="fas fa-chevron-left"></i></button>
          <span>Pág. <span x-text="page"></span> / <span x-text="pages"></span></span>
          <button @click="nextPage()" :disabled="page>=pages" class="px-3 py-1 rounded border border-gray-300 dark:border-slate-600 disabled:opacity-40"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <div x-show="showForm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/60" @click="showForm=false"></div>
    <div class="relative w-full max-w-2xl bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
      <div class="px-4 py-3 bg-blue-600 text-white font-semibold flex items-center justify-between">
        <span x-text="form.id_sucursal ? 'Editar sucursal' : 'Nueva sucursal'"></span>
        <button @click="showForm=false" class="w-8 h-8 rounded hover:bg-white/20"><i class="fas fa-times"></i></button>
      </div>
      <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label class="text-xs text-gray-500">Nombre</label>
          <input x-model="form.sucursal" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
        </div>
        <div>
          <label class="text-xs text-gray-500">País</label>
          <input x-model="form.pais" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
        </div>
        <div>
          <label class="text-xs text-gray-500">Ciudad</label>
          <input x-model="form.ciudad" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
        </div>
        <div class="sm:col-span-2">
          <label class="text-xs text-gray-500">Dirección</label>
          <input x-model="form.direccion" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
        </div>
        <div>
          <label class="text-xs text-gray-500">Teléfono</label>
          <input x-model="form.telefono" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
        </div>
        <div>
          <label class="text-xs text-gray-500">Estado</label>
          <select x-model="form.activo" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700">
            <option :value="1">Activa</option>
            <option :value="0">Inactiva</option>
          </select>
        </div>
      </div>
      <div class="px-4 py-3 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-2">
        <button @click="showForm=false" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600">Cancelar</button>
        <button @click="guardar()" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white" :disabled="saving">
          <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i> Guardar
        </button>
      </div>
    </div>
  </div>

  <script>
  function sucursalesApp(){
    return {
      API: '/public/sucursales/api',
      idEmpresa: <?= (int)$id_empresa ?>,
      permisos: <?= json_encode($permisos) ?>,
      isDark: document.documentElement.classList.contains('dark'),
      loading: false,
      saving: false,
      rows: [],
      search: '',
      estado: '',
      page: 1,
      perPage: 24,
      total: 0,
      pages: 1,
      showForm: false,
      form: { id_sucursal:null, sucursal:'', pais:'', ciudad:'', direccion:'', telefono:'', activo:1 },
      init(){ this.load(); },
      toggleTheme(){
        this.isDark = true;
        document.documentElement.classList.add('dark');
        localStorage.theme = 'dark';
      },
      resetForm(){ this.form = { id_sucursal:null, sucursal:'', pais:'', ciudad:'', direccion:'', telefono:'', activo:1 }; },
      nuevo(){ this.resetForm(); this.showForm = true; },
      editar(s){ this.form = { ...s, activo:Number(s.activo)===1?1:0 }; this.showForm = true; },
      async load(){
        this.loading = true;
        try {
          const q = new URLSearchParams({ id_empresa:String(this.idEmpresa), page:String(this.page), per_page:String(this.perPage), search:this.search || '', estado:this.estado || '' });
          const res = await fetch(`${this.API}/list.php?${q.toString()}`);
          const data = await res.json();
          if (data.ok) {
            this.rows = Array.isArray(data.data) ? data.data : [];
            this.total = Number(data.total || 0);
            this.pages = Number(data.pages || 1);
          } else {
            alert(data.error || 'Error al cargar');
          }
        } catch (e) {
          alert('Error de conexión');
        }
        this.loading = false;
      },
      async guardar(){
        if (!String(this.form.sucursal || '').trim()) { alert('Nombre obligatorio'); return; }
        this.saving = true;
        try {
          const action = this.form.id_sucursal ? 'update' : 'create';
          const res = await fetch(`${this.API}/guardar.php`, {
            method: 'POST', headers: { 'Content-Type':'application/json' },
            body: JSON.stringify({ action, id_empresa: this.idEmpresa, sucursal: this.form })
          });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error || 'No se pudo guardar');
          this.showForm = false;
          await this.load();
        } catch (e) {
          alert(e.message || 'Error al guardar');
        }
        this.saving = false;
      },
      async eliminar(s){
        if (!confirm(`¿Eliminar/desactivar sucursal ${s.sucursal}?`)) return;
        try {
          const res = await fetch(`${this.API}/eliminar.php`, {
            method: 'POST', headers: { 'Content-Type':'application/json' },
            body: JSON.stringify({ id_empresa:this.idEmpresa, id_sucursal:s.id_sucursal })
          });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error || 'No se pudo eliminar');
          await this.load();
        } catch (e) {
          alert(e.message || 'Error al eliminar');
        }
      },
      prevPage(){ if (this.page > 1) { this.page--; this.load(); } },
      nextPage(){ if (this.page < this.pages) { this.page++; this.load(); } },
    }
  }
  </script>
</body>
</html>
