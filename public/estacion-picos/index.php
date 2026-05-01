<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_estacion');

$pdo = Database::getSessionEmpresaConnection();
$idEmpresa = (int)(Session::getIdEmpresa() ?: 0);

try {
    $surtidorCols = $pdo->query("SHOW COLUMNS FROM estacion_surtidores")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $tanqueCols = $pdo->query("SHOW COLUMNS FROM estacion_tanques")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $combustibleCols = $pdo->query("SHOW COLUMNS FROM estacion_combustibles")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $surtidorIdCol = in_array('id', $surtidorCols, true) ? 'id' : (in_array('id_surtidor', $surtidorCols, true) ? 'id_surtidor' : 'id');
    $surtidorNameCol = in_array('nombre', $surtidorCols, true) ? 'nombre' : $surtidorIdCol;

    $tanqueIdCol = in_array('id', $tanqueCols, true) ? 'id' : (in_array('id_tanque', $tanqueCols, true) ? 'id_tanque' : 'id');
    $tanqueNameCol = in_array('nombre', $tanqueCols, true) ? 'nombre' : $tanqueIdCol;

    $combustibleIdCol = in_array('id', $combustibleCols, true) ? 'id' : (in_array('id_combustible', $combustibleCols, true) ? 'id_combustible' : 'id');
    $combustibleNameCol = in_array('nombre', $combustibleCols, true) ? 'nombre' : $combustibleIdCol;

    $surtidores = $pdo->query("SELECT {$surtidorIdCol} AS id, {$surtidorNameCol} AS nombre, nro_surtidor FROM estacion_surtidores WHERE activo = 'Y' ORDER BY nro_surtidor")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $tanques = $pdo->query("SELECT {$tanqueIdCol} AS id, {$tanqueNameCol} AS nombre FROM estacion_tanques WHERE activo = 'Y' ORDER BY {$tanqueNameCol}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $combustibles = $pdo->query("SELECT {$combustibleIdCol} AS id, {$combustibleNameCol} AS nombre, unidad_medida FROM estacion_combustibles WHERE activo = 'Y' ORDER BY {$combustibleNameCol}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $surtidores = [];
    $tanques = [];
    $combustibles = [];
}
?>
<!doctype html>
<html lang="es" x-data="picosApp()" x-init="init()">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Picos</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
  <div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">
    <div class="flex items-center justify-between gap-3 mb-5">
      <div class="flex items-center gap-3">
        <a href="/public/estacion-surtidores/" class="w-10 h-10 rounded-lg bg-white/10 border border-white/10 text-white hover:bg-white/20 flex items-center justify-center">
          <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
          <h1 class="text-xl sm:text-2xl font-bold"><i class="fa-solid fa-gas-pump mr-2 text-cyan-400"></i>Picos</h1>
          <p class="text-xs text-slate-400">Configura la conexión física entre surtidor, tanque y combustible</p>
        </div>
      </div>
      <button @click="nuevo()" class="px-4 py-2 bg-cyan-500 hover:bg-cyan-400 text-slate-950 rounded-lg font-semibold">
        <i class="fas fa-plus mr-1"></i>Nuevo Pico
      </button>
    </div>

    <div class="bg-white/5 border border-white/10 rounded-xl p-3 sm:p-4 mb-4">
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex-1 min-w-[220px] relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
          <input x-model="search" @input.debounce.300ms="page=1;load()" placeholder="Buscar pico, surtidor, tanque o combustible..."
                 class="w-full pl-9 pr-3 py-2 rounded-lg border border-white/10 bg-slate-900">
        </div>
        <button @click="load()" class="px-4 py-2 rounded-lg border border-white/10 bg-slate-900 hover:bg-white/10">Actualizar</button>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-xl overflow-hidden text-slate-900">
      <div x-show="loading" class="p-8 text-center text-slate-500"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando...</div>
      <div class="overflow-x-auto" x-show="!loading" x-cloak>
        <table class="min-w-full">
          <thead class="bg-slate-100 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-4 py-3 text-left">Surtidor</th>
              <th class="px-4 py-3 text-left">Pico</th>
              <th class="px-4 py-3 text-left">Combustible</th>
              <th class="px-4 py-3 text-left">Tanque</th>
              <th class="px-4 py-3 text-right">Totalizador</th>
              <th class="px-4 py-3 text-center">Estado</th>
              <th class="px-4 py-3 text-center">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <template x-for="r in rows" :key="r.id">
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                  <div class="font-semibold" x-text="r.surtidor"></div>
                  <div class="text-xs text-slate-500" x-text="'N° ' + r.id_surtidor"></div>
                </td>
                <td class="px-4 py-3">
                  <div class="font-semibold" x-text="r.nombre || ('Pico ' + r.nro_pico)"></div>
                  <div class="text-xs text-slate-500" x-text="'Pico ' + r.nro_pico"></div>
                </td>
                <td class="px-4 py-3" x-text="r.combustible"></td>
                <td class="px-4 py-3" x-text="r.tanque"></td>
                <td class="px-4 py-3 text-right font-medium" x-text="parseFloat(r.totalizador_actual || 0).toFixed(3)"></td>
                <td class="px-4 py-3 text-center">
                  <span class="px-2 py-1 text-xs rounded-full" :class="String(r.activo) === 'Y' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'" x-text="String(r.activo) === 'Y' ? 'Activo' : 'Inactivo'"></span>
                </td>
                <td class="px-4 py-3 text-center">
                  <div class="inline-flex items-center gap-1">
                    <button @click="editar(r)" class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600"><i class="fas fa-edit"></i></button>
                    <button @click="eliminar(r)" class="w-8 h-8 rounded-lg bg-red-50 text-red-600"><i class="fas fa-trash"></i></button>
                  </div>
                </td>
              </tr>
            </template>
            <tr x-show="rows.length===0"><td colspan="7" class="p-8 text-center text-slate-500">Sin picos configurados</td></tr>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-sm">
        <div>Total: <strong x-text="total"></strong></div>
        <div class="inline-flex items-center gap-2">
          <button @click="prevPage()" :disabled="page<=1" class="px-3 py-1 rounded border border-slate-300 disabled:opacity-40"><i class="fas fa-chevron-left"></i></button>
          <span>Pág. <span x-text="page"></span> / <span x-text="pages"></span></span>
          <button @click="nextPage()" :disabled="page>=pages" class="px-3 py-1 rounded border border-slate-300 disabled:opacity-40"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <div x-show="showForm" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/60" @click="showForm=false"></div>
    <div class="relative w-full max-w-2xl bg-white text-slate-900 rounded-xl border border-slate-200 overflow-hidden">
      <div class="px-4 py-3 bg-cyan-500 text-slate-950 font-semibold flex items-center justify-between">
        <span x-text="form.id ? 'Editar pico' : 'Nuevo pico'"></span>
        <button @click="showForm=false" class="w-8 h-8 rounded hover:bg-black/10"><i class="fas fa-times"></i></button>
      </div>
      <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-slate-500">Surtidor</label>
          <select x-model="form.id_surtidor" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white">
            <option value="">Seleccionar...</option>
            <template x-for="s in surtidores">
              <option :value="s.id" x-text="s.nombre + ' (#' + s.nro_surtidor + ')'"></option>
            </template>
          </select>
        </div>
        <div>
          <label class="text-xs text-slate-500">Número de pico</label>
          <input x-model="form.nro_pico" type="number" min="1" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white">
        </div>
        <div class="sm:col-span-2">
          <label class="text-xs text-slate-500">Nombre</label>
          <input x-model="form.nombre" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white" placeholder="Ej: Pico 1A - Nafta Común">
        </div>
        <div>
          <label class="text-xs text-slate-500">Combustible</label>
          <select x-model="form.id_combustible" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white">
            <option value="">Seleccionar...</option>
            <template x-for="c in combustibles">
              <option :value="c.id" x-text="c.nombre"></option>
            </template>
          </select>
        </div>
        <div>
          <label class="text-xs text-slate-500">Tanque</label>
          <select x-model="form.id_tanque" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white">
            <option value="">Seleccionar...</option>
            <template x-for="t in tanques">
              <option :value="t.id" x-text="t.nombre"></option>
            </template>
          </select>
        </div>
        <div>
          <label class="text-xs text-slate-500">Totalizador actual</label>
          <input x-model="form.totalizador_actual" type="number" step="0.001" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white">
        </div>
        <div>
          <label class="text-xs text-slate-500">Estado</label>
          <select x-model="form.activo" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white">
            <option value="Y">Activo</option>
            <option value="N">Inactivo</option>
          </select>
        </div>
      </div>
      <div class="px-4 py-3 border-t border-slate-200 flex justify-end gap-2">
        <button @click="showForm=false" class="px-4 py-2 rounded-lg border border-slate-300">Cancelar</button>
        <button @click="guardar()" class="px-4 py-2 rounded-lg bg-cyan-500 hover:bg-cyan-400 text-slate-950 font-semibold" :disabled="saving">
          <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i> Guardar
        </button>
      </div>
    </div>
  </div>

  <script>
    function picosApp() {
      return {
        API: '/public/estacion-picos/api',
        idEmpresa: <?= (int)$idEmpresa ?>,
        loading: false,
        saving: false,
        rows: [],
        surtidores: <?= json_encode($surtidores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        tanques: <?= json_encode($tanques, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        combustibles: <?= json_encode($combustibles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        search: '',
        page: 1,
        perPage: 25,
        total: 0,
        pages: 1,
        showForm: false,
        form: { id: null, id_surtidor: '', nro_pico: '', nombre: '', id_combustible: '', id_tanque: '', totalizador_actual: 0, activo: 'Y' },
        init() { this.load(); },
        nuevo() { this.form = { id: null, id_surtidor: '', nro_pico: '', nombre: '', id_combustible: '', id_tanque: '', totalizador_actual: 0, activo: 'Y' }; this.showForm = true; },
        editar(r) { this.form = { ...r, id: r.id, activo: String(r.activo || 'Y') }; this.showForm = true; },
        async load() {
          this.loading = true;
          try {
            const q = new URLSearchParams({ id_empresa: String(this.idEmpresa), page: String(this.page), per_page: String(this.perPage), search: this.search || '' });
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
        async guardar() {
          this.saving = true;
          try {
            const res = await fetch(`${this.API}/guardar.php`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ ...this.form, id_empresa: this.idEmpresa })
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
        async eliminar(r) {
          if (!confirm(`¿Eliminar el pico ${r.nombre || r.nro_pico}?`)) return;
          try {
            const res = await fetch(`${this.API}/eliminar.php`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id_empresa: this.idEmpresa, id: r.id })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'No se pudo eliminar');
            await this.load();
          } catch (e) {
            alert(e.message || 'Error al eliminar');
          }
        },
        prevPage() { if (this.page > 1) { this.page--; this.load(); } },
        nextPage() { if (this.page < this.pages) { this.page++; this.load(); } }
      };
    }
  </script>
</body>
</html>
