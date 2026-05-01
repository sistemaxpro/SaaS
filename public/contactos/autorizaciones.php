<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin();

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
$isAdmin = false;
try {
    $pdoMaster = Database::getMasterConnection();
    $masterDb = Database::getMasterDbName();
    $stAdmin = $pdoMaster->prepare("
        SELECT priv_admin, role
        FROM {$masterDb}.sec_users
        WHERE id_login = :id_login
          AND id_empresa = :id_empresa
        LIMIT 1
    ");
    $stAdmin->execute([':id_login' => $idLogin, ':id_empresa' => $idEmpresa]);
    $u = $stAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$u) {
        $stAdmin = $pdoMaster->prepare("
            SELECT priv_admin, role
            FROM {$masterDb}.sec_users
            WHERE id_login = :id_login
            LIMIT 1
        ");
        $stAdmin->execute([':id_login' => $idLogin]);
        $u = $stAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($u) {
        $privAdmin = strtoupper(trim((string)($u['priv_admin'] ?? 'N')));
        $role = strtoupper(trim((string)($u['role'] ?? '')));
        $isAdmin = in_array($privAdmin, ['Y', 'S', '1', 'TRUE'], true)
            || in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
            || strpos($role, 'ADMIN') !== false
            || strpos($role, 'SUPERVIS') !== false;
    }
} catch (Throwable $e) {
    $isAdmin = false;
}

if (!$isAdmin) {
    http_response_code(403);
    echo 'No autorizado';
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Autorizaciones de Kardex</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="kardexAuthCenter()" x-init="init()">
  <div class="mx-auto max-w-7xl p-4 md:p-6">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-black tracking-tight">Autorizaciones de Kardex</h1>
        <p class="mt-1 text-sm text-slate-400">Solicitudes pendientes para anular o desanular movimientos del estado de cuenta.</p>
      </div>
      <button class="rounded-xl bg-cyan-500 px-4 py-2 font-semibold text-slate-950 transition hover:bg-cyan-400" @click="load()">Actualizar</button>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-4">
      <select x-model="filterStatus" @change="load()" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-3 text-sm text-slate-100 outline-none focus:border-cyan-500">
        <option value="pending">Pendientes</option>
        <option value="all">Todos los estados</option>
        <option value="authorized">Autorizados</option>
        <option value="rejected">Rechazados</option>
      </select>
      <div class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3">
        <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total visible</div>
        <div class="mt-1 text-xl font-black" x-text="rows.length"></div>
      </div>
      <div class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3">
        <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Pendientes</div>
        <div class="mt-1 text-xl font-black text-amber-400" x-text="rows.filter(r => r.status === 'pending').length"></div>
      </div>
      <div class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3">
        <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Autorizados</div>
        <div class="mt-1 text-xl font-black text-emerald-400" x-text="rows.filter(r => r.status === 'authorized').length"></div>
      </div>
    </div>

    <div x-show="loading" class="rounded-2xl border border-slate-800 bg-slate-900 px-5 py-10 text-center text-slate-400">
      Cargando autorizaciones...
    </div>

    <div x-show="!loading && rows.length === 0" class="rounded-2xl border border-slate-800 bg-slate-900 px-5 py-12 text-center">
      <div class="text-lg font-semibold text-slate-200">Sin solicitudes</div>
      <div class="mt-1 text-sm text-slate-500">No hay registros para el filtro seleccionado.</div>
    </div>

    <div x-show="!loading && rows.length > 0" class="space-y-4">
      <template x-for="row in rows" :key="row.id">
        <section class="rounded-2xl border border-slate-800 bg-slate-900/95 p-4 shadow-[0_18px_60px_rgba(2,6,23,0.35)]">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-cyan-500/15 px-2.5 py-1 text-[11px] font-bold tracking-wide text-cyan-300" x-text="row.accion === 'desanular' ? 'Desanulación' : 'Anulación'"></span>
                <span class="rounded-full px-2.5 py-1 text-[11px] font-bold"
                      :class="statusClass(row.status)"
                      x-text="statusLabel(row.status)"></span>
                <span class="text-xs text-slate-500" x-text="'#' + row.id"></span>
              </div>
              <h2 class="mt-3 text-lg font-black leading-tight text-slate-100" x-text="'Registro #' + (row.record_id || '-')"></h2>
              <p class="mt-1 text-sm text-slate-400" x-text="row.extracto_concepto || row.mensaje || '-'"></p>
            </div>
            <div class="text-right text-xs text-slate-500">
              <div>Solicitud</div>
              <div class="mt-1 font-semibold text-slate-300" x-text="row.created_at || '-'"></div>
              <template x-if="row.resolved_at">
                <div class="mt-2">
                  <div>Resolución</div>
                  <div class="mt-1 font-semibold text-slate-300" x-text="row.resolved_at"></div>
                </div>
              </template>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Solicita</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="row.requested_by_login || '-'"></div>
              <div class="mt-1 text-xs text-slate-500" x-text="'Login ID: ' + (row.requested_by_login_id || '-')"></div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Autorizado por</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="row.authorized_by_login || '-'"></div>
              <div class="mt-1 text-xs text-slate-500" x-text="'Login ID: ' + (row.authorized_by_login_id || '-')"></div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Fecha extracto</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="row.extracto_fecha || '-'"></div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Estado actual</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="String(row.extracto_estado) === '1' ? 'Anulado' : 'Activo'"></div>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-4">
            <div class="text-xs text-slate-500" x-text="row.mensaje || ''"></div>
            <div class="flex flex-wrap gap-2" x-show="row.status === 'pending'">
              <button class="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-emerald-400"
                      @click="decide(row, 'approve')">Aprobar</button>
              <button class="rounded-xl bg-rose-500 px-4 py-2 text-sm font-bold text-white transition hover:bg-rose-400"
                      @click="decide(row, 'reject')">Rechazar</button>
            </div>
          </div>
        </section>
      </template>
    </div>
  </div>

<script>
function kardexAuthCenter() {
  return {
    idEmpresa: <?= (int)$idEmpresa ?>,
    rows: [],
    filterStatus: 'pending',
    loading: false,

    async init() { await this.load(); },

    statusLabel(status) {
      return ({
        pending: 'Pendiente',
        authorized: 'Autorizado',
        rejected: 'Rechazado'
      })[status] || status;
    },

    statusClass(status) {
      return ({
        pending: 'bg-amber-500/15 text-amber-300',
        authorized: 'bg-emerald-500/15 text-emerald-300',
        rejected: 'bg-rose-500/15 text-rose-300'
      })[status] || 'bg-slate-700 text-slate-200';
    },

    async load() {
      this.loading = true;
      try {
        const url = `api/autorizaciones.php?action=list&id_empresa=${this.idEmpresa}&status=${encodeURIComponent(this.filterStatus)}&limit=300&_ts=${Date.now()}`;
        const r = await fetch(url, { credentials: 'same-origin' });
        const d = await r.json();
        this.rows = Array.isArray(d?.items) ? d.items : [];
      } catch (_) {
        alert('No se pudo cargar autorizaciones');
      } finally {
        this.loading = false;
      }
    },

    async decide(row, decision) {
      try {
        const r = await fetch(`api/autorizaciones.php?action=decide&id_empresa=${this.idEmpresa}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ id: row.id, decision })
        });
        const d = await r.json();
        if (!d?.success) throw new Error(d?.error || d?.message || 'No se pudo actualizar');
        await this.load();
      } catch (e) {
        alert(e.message || 'Error');
      }
    }
  }
}
</script>
</body>
</html>
