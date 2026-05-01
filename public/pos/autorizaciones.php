<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin();
$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
$userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$isMobile = (bool)preg_match('/mobile|android|iphone|ipad|ipod|webos|blackberry|iemobile|opera mini/', $userAgent);
$redirectAfterDecisionUrl = $isMobile
    ? '/public/pos/mobile.php'
    : '/public/pos/index_desktop_dropdown.php?desktop=1';

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
        $roleAdminLike = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT'], true)
            || (strpos($role, 'ADMIN') !== false && strpos($role, 'VENDEDOR') === false && strpos($role, 'CAJER') === false);
        $isAdmin = ($privAdmin === 'Y') || $roleAdminLike;
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
  <title>Centro de Autorizaciones POS</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script>window.__AUTH_REDIRECT_POS_URL__ = <?= json_encode($redirectAfterDecisionUrl, JSON_UNESCAPED_SLASHES) ?>;</script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="authCenter()" x-init="init()">
  <div class="mx-auto max-w-7xl p-4 md:p-6">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-black tracking-tight">Centro de Autorizaciones POS</h1>
        <p class="mt-1 text-sm text-slate-400">Control central de solicitudes pendientes, aprobadas y rechazadas.</p>
      </div>
      <button class="rounded-xl bg-cyan-500 px-4 py-2 font-semibold text-slate-950 transition hover:bg-cyan-400" @click="load()">Actualizar</button>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-4">
      <select x-model="filterType" @change="load()" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-3 text-sm text-slate-100 outline-none focus:border-cyan-500">
        <option value="ALL">Todos los tipos</option>
        <option value="VOID">Anulación Caja</option>
        <option value="PRICE">Precio especial</option>
        <option value="ITEM_DELETE">Eliminar ítem</option>
      </select>
      <select x-model="filterStatus" @change="load()" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-3 text-sm text-slate-100 outline-none focus:border-cyan-500">
        <option value="PENDIENTE">Pendientes</option>
        <option value="ALL">Todos los estados</option>
        <option value="APROBADO">Aprobados</option>
        <option value="RECHAZADO">Rechazados</option>
        <option value="EJECUTADO">Ejecutados</option>
      </select>
      <div class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3">
        <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total visible</div>
        <div class="mt-1 text-xl font-black" x-text="rows.length"></div>
      </div>
      <div class="rounded-xl border border-slate-800 bg-slate-900 px-4 py-3">
        <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Pendientes</div>
        <div class="mt-1 text-xl font-black text-amber-400" x-text="rows.filter(r => r.estado === 'PENDIENTE').length"></div>
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
      <template x-for="row in rows" :key="row.tipo + '-' + row.id">
        <section class="rounded-2xl border border-slate-800 bg-slate-900/95 p-4 shadow-[0_18px_60px_rgba(2,6,23,0.35)]">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide"
                      :class="row.tipo === 'VOID' ? 'bg-fuchsia-500/15 text-fuchsia-300' : (row.tipo === 'PRICE' ? 'bg-cyan-500/15 text-cyan-300' : 'bg-amber-500/15 text-amber-300')"
                      x-text="typeLabel(row.tipo)"></span>
                <span class="rounded-full px-2.5 py-1 text-[11px] font-bold"
                      :class="statusClass(row.estado)"
                      x-text="row.estado"></span>
                <span class="text-xs text-slate-500" x-text="'#' + row.id"></span>
              </div>
              <h2 class="mt-3 text-lg font-black leading-tight text-slate-100" x-text="mainTitle(row)"></h2>
              <p class="mt-1 text-sm text-slate-400" x-text="mainSubtitle(row)"></p>
            </div>
            <div class="text-right text-xs text-slate-500">
              <div>Solicitud</div>
              <div class="mt-1 font-semibold text-slate-300" x-text="row.fecha_solicitud || '-'"></div>
              <template x-if="row.fecha_resolucion">
                <div class="mt-2">
                  <div>Resolución</div>
                  <div class="mt-1 font-semibold text-slate-300" x-text="row.fecha_resolucion"></div>
                </div>
              </template>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Solicita</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="row.solicitado_por_nombre || row.solicitado_por_login || '-'"></div>
              <div class="mt-1 text-xs text-slate-400" x-text="row.solicitado_por_login ? ('@' + row.solicitado_por_login) : '-'"></div>
              <div class="mt-1 text-xs text-slate-500" x-text="'Login ID: ' + (row.solicitado_por_id_login || '-')"></div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Caja / Origen</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="'Caja #' + (row.id_caja || '-')"></div>
              <div class="mt-1 text-xs text-slate-500" x-text="row.origen || '-'"></div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Autorizado por</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="row.aprobado_por_nombre || row.aprobado_por_login || '-'"></div>
              <div class="mt-1 text-xs text-slate-400" x-text="row.aprobado_por_login ? ('@' + row.aprobado_por_login) : '-'"></div>
              <div class="mt-1 text-xs text-slate-500" x-text="'Login ID: ' + (row.aprobado_por_id_login || '-')"></div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Referencia</div>
              <div class="mt-1 font-semibold text-slate-100" x-text="referenceLabel(row)"></div>
              <div class="mt-1 text-xs text-slate-500" x-text="secondaryReference(row)"></div>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3 lg:col-span-2">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Detalle de la solicitud</div>
              <div class="mt-2 space-y-1 text-sm text-slate-300">
                <template x-if="row.tipo === 'VOID'">
                  <div>
                    <div x-text="'Operación: #' + (row.referencia_id || '-')"></div>
                    <div class="text-slate-500" x-text="'Motivo: ' + (row.motivo || 'Sin detalle')"></div>
                  </div>
                </template>
                <template x-if="row.tipo === 'PRICE'">
                  <div>
                    <div x-text="'Precio intentado: ' + money(row.precio)"></div>
                    <div x-text="'Precio mínimo: ' + money(row.precio_minimo)"></div>
                    <div class="text-slate-500" x-text="'Motivo: ' + (row.motivo || 'Sin detalle')"></div>
                  </div>
                </template>
                <template x-if="row.tipo === 'ITEM_DELETE'">
                  <div>
                    <div x-text="'Cantidad: ' + qty(row.cantidad)"></div>
                    <div x-text="'Precio unitario: ' + money(row.precio)"></div>
                    <div class="text-slate-500" x-text="'Motivo: ' + (row.motivo || 'Sin detalle')"></div>
                  </div>
                </template>
              </div>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Payload técnico</div>
              <div class="mt-2 text-xs text-slate-400">
                <div x-text="'Producto ID: ' + ((row.payload && row.payload.id_producto) || row.id_producto || '-')"></div>
                <div x-text="'Usuario ID: ' + ((row.payload && row.payload.id_usuario) || row.solicitado_por_id_login || '-')"></div>
                <div x-text="'Empresa ID: ' + ((row.payload && row.payload.id_empresa) || idEmpresa || '-')"></div>
                <div x-text="'Caja ID: ' + ((row.payload && row.payload.id_caja) || row.id_caja || '-')"></div>
              </div>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-4">
            <div class="text-xs text-slate-500" x-text="footerNote(row)"></div>
            <div class="flex flex-wrap gap-2" x-show="row.estado === 'PENDIENTE'">
              <button class="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-emerald-400"
                      @click="decide(row, 'APROBAR')">Aprobar</button>
              <button class="rounded-xl bg-rose-500 px-4 py-2 text-sm font-bold text-white transition hover:bg-rose-400"
                      @click="decide(row, 'RECHAZAR')">Rechazar</button>
            </div>
          </div>
        </section>
      </template>
    </div>
  </div>

<script>
function authCenter() {
  return {
    idEmpresa: <?= (int)$idEmpresa ?>,
    redirectAfterDecisionUrl: window.__AUTH_REDIRECT_POS_URL__ || '/public/pos/index_desktop_dropdown.php?desktop=1',
    rows: [],
    filterType: 'ALL',
    filterStatus: 'PENDIENTE',
    loading: false,

    async init() { await this.load(); },

    money(v) {
      return new Intl.NumberFormat('es-PY').format(Number(v || 0)) + ' Gs';
    },

    qty(v) {
      const num = Number(v || 0);
      return Number.isInteger(num) ? String(num) : num.toFixed(3);
    },

    typeLabel(tipo) {
      return ({
        VOID: 'Anulación Caja',
        PRICE: 'Precio especial',
        ITEM_DELETE: 'Eliminar ítem'
      })[tipo] || tipo;
    },

    statusClass(status) {
      return ({
        PENDIENTE: 'bg-amber-500/15 text-amber-300',
        APROBADO: 'bg-emerald-500/15 text-emerald-300',
        RECHAZADO: 'bg-rose-500/15 text-rose-300',
        EJECUTADO: 'bg-cyan-500/15 text-cyan-300'
      })[status] || 'bg-slate-700 text-slate-200';
    },

    mainTitle(row) {
      if (row.tipo === 'VOID') return `Operación #${row.referencia_id || '-'}`;
      return row.descripcion_producto || `Producto #${row.id_producto || '-'}`;
    },

    mainSubtitle(row) {
      if (row.tipo === 'PRICE') return 'Solicitud de autorización para vender debajo del mínimo';
      if (row.tipo === 'ITEM_DELETE') return 'Solicitud para eliminar un ítem ya cargado en el carrito';
      return 'Solicitud para anular una operación registrada en caja';
    },

    referenceLabel(row) {
      if (row.tipo === 'VOID') return `Operación #${row.referencia_id || '-'}`;
      return `Producto #${row.id_producto || '-'}`;
    },

    secondaryReference(row) {
      if (row.tipo === 'PRICE') return `Intentado ${this.money(row.precio)} vs mínimo ${this.money(row.precio_minimo)}`;
      if (row.tipo === 'ITEM_DELETE') return `Cantidad ${this.qty(row.cantidad)}`;
      return row.origen || '-';
    },

    footerNote(row) {
      if (row.estado === 'PENDIENTE') return 'Pendiente de decisión administrativa.';
      if (row.estado === 'APROBADO') return `Aprobada por ${row.aprobado_por_login || 'administrador'}.`;
      if (row.estado === 'RECHAZADO') return `Rechazada por ${row.aprobado_por_login || 'administrador'}.`;
      if (row.estado === 'EJECUTADO') return 'Ya fue ejecutada en POS.';
      return '';
    },

    async load() {
      this.loading = true;
      try {
        const url = `api/autorizaciones.php?action=list&id_empresa=${this.idEmpresa}&type=${encodeURIComponent(this.filterType)}&status=${encodeURIComponent(this.filterStatus)}&limit=300&_ts=${Date.now()}`;
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
      const motivo = prompt(decision === 'APROBAR' ? 'Motivo/nota de aprobación (opcional):' : 'Motivo de rechazo (opcional):', '') || '';
      try {
        const r = await fetch(`api/autorizaciones.php?action=decide&id_empresa=${this.idEmpresa}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ tipo: row.tipo, id: row.id, decision, motivo })
        });
        const d = await r.json();
        if (!d?.success) throw new Error(d?.error || d?.message || 'No se pudo actualizar');
        window.location.href = this.redirectAfterDecisionUrl;
      } catch (e) {
        alert(e.message || 'Error');
      }
    }
  }
}
</script>
</body>
</html>
