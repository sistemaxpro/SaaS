<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::start();
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Errores Reportados - Desarrollo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div x-data="bugsBoard()" x-init="init()" class="max-w-7xl mx-auto px-4 py-5">
    <div class="flex items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold">Cuadro de Errores Reportados</h1>
            <p class="text-slate-400 text-sm">Seguimiento de análisis</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-white text-sm font-semibold">Salir</button>
            <button @click="load()" class="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold">Actualizar</button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
        <input x-model="search" @input.debounce.400ms="load()" type="text" placeholder="Buscar incidencia, empresa, app..."
               class="md:col-span-2 px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-sm">
        <select x-model="status" @change="load()" class="px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-sm">
            <option value="all">Todos</option>
            <option value="nuevo">Nuevo</option>
            <option value="en_analisis">En análisis</option>
            <option value="resuelto">Resuelto</option>
        </select>
        <div class="px-3 py-2 rounded-lg bg-slate-900 border border-slate-700 text-sm text-slate-300" x-text="rows.length + ' reportes'"></div>
    </div>
    <div x-show="errorMsg" class="mb-4 px-3 py-2 rounded-lg bg-red-900/40 border border-red-700 text-red-200 text-sm" x-text="errorMsg"></div>

    <div class="overflow-auto rounded-xl border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900 text-slate-300">
            <tr>
                <th class="text-left px-3 py-2">Incidencia</th>
                <th class="text-left px-3 py-2">Empresa</th>
                <th class="text-left px-3 py-2">App</th>
                <th class="text-left px-3 py-2">Estado</th>
                <th class="text-left px-3 py-2">Fecha</th>
                <th class="text-right px-3 py-2">Acción</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="r in rows" :key="r.id">
                <tr class="border-t border-slate-800">
                    <td class="px-3 py-2 font-mono text-xs" x-text="r.incident"></td>
                    <td class="px-3 py-2">
                        <div x-text="r.empresa_reportante"></div>
                        <div class="text-xs text-slate-400">#<span x-text="r.id_empresa_reportante"></span> · <span x-text="r.usuario_reportante || 'N/D'"></span></div>
                        <div class="text-xs text-cyan-300 mt-1" x-text="affectedEmpresasLabel(r)"></div>
                    </td>
                    <td class="px-3 py-2" x-text="r.app || '-'"></td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-1 rounded text-xs font-semibold"
                              :class="r.status==='nuevo'?'bg-red-900/50 text-red-300':(r.status==='en_analisis'?'bg-amber-900/50 text-amber-300':'bg-emerald-900/50 text-emerald-300')"
                              x-text="r.status"></span>
                    </td>
                    <td class="px-3 py-2 text-xs text-slate-300" x-text="r.created_at"></td>
                    <td class="px-3 py-2 text-right">
                        <button @click="open(r)" class="px-2 py-1 rounded bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold">Seguimiento</button>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>
    </div>

    <div x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/70" @click="show=false"></div>
        <div class="relative w-full max-w-3xl rounded-xl bg-slate-900 border border-slate-700 p-4 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="font-bold text-lg">Seguimiento de Análisis</h2>
                <button @click="show=false" class="w-8 h-8 rounded bg-slate-800">x</button>
            </div>
            <div class="text-sm text-slate-300">
                <div><b>Incidencia:</b> <span x-text="current.incident || '-'"></span></div>
                <div><b>Error:</b> <span x-text="current.error_msg || '-'"></span></div>
                <div><b>Empresas afectadas:</b> <span x-text="affectedEmpresasLabel(current)"></span></div>
                <div class="flex items-center justify-between gap-2">
                    <b>Detalle:</b>
                    <button @click="copyErrorDetails()" class="px-2 py-1 rounded bg-cyan-700 hover:bg-cyan-600 text-white text-xs font-semibold">
                        Copiar al portapapeles
                    </button>
                </div>
                <pre class="mt-1 p-2 rounded bg-slate-950 border border-slate-800 text-xs whitespace-pre-wrap" x-text="current.error_detail || '-'"></pre>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <select x-model="current.status" class="px-3 py-2 rounded bg-slate-950 border border-slate-700 text-sm">
                    <option value="nuevo">Nuevo</option>
                    <option value="en_analisis">En análisis</option>
                    <option value="resuelto">Resuelto</option>
                </select>
                <input x-model="current.analyzed_by" type="text" disabled class="px-3 py-2 rounded bg-slate-800 border border-slate-700 text-sm text-slate-400">
            </div>
            <textarea x-model="current.analysis_note" rows="5" placeholder="Notas de análisis técnico..." class="w-full px-3 py-2 rounded bg-slate-950 border border-slate-700 text-sm"></textarea>
            <div x-show="copyNotice" x-cloak class="px-3 py-2 rounded bg-emerald-900/40 border border-emerald-700 text-emerald-200 text-xs" x-text="copyNotice"></div>
            <div class="flex justify-end gap-2">
                <button @click="show=false" class="px-4 py-2 rounded bg-slate-700 text-sm">Cerrar</button>
                <button @click="save()" class="px-4 py-2 rounded bg-emerald-600 text-sm font-semibold">Guardar seguimiento</button>
            </div>
        </div>
    </div>
</div>

<script>
function bugsBoard() {
    return {
        rows: [],
        search: '',
        status: 'all',
        show: false,
        current: {},
        errorMsg: '',
        copyNotice: '',
        maxSeenId: 0,
        pollTimer: null,
        affectedEmpresasLabel(row) {
            let list = [];
            try {
                list = JSON.parse((row && row.affected_empresas_json) ? row.affected_empresas_json : '[]');
            } catch (e) {
                list = [];
            }
            if (!Array.isArray(list) || list.length === 0) {
                const n = Number(row && row.id_empresa_reportante ? row.id_empresa_reportante : 0);
                return n > 0 ? `Empresas: #${n}` : 'Empresas: N/D';
            }
            const ids = [];
            for (const it of list) {
                const id = Number(it && it.id_empresa ? it.id_empresa : 0);
                if (id > 0 && !ids.includes(id)) ids.push(id);
            }
            return ids.length ? `Empresas: ${ids.map(x => '#' + x).join(', ')}` : 'Empresas: N/D';
        },
        async fetchJsonWithRetry(url, tries = 2, delayMs = 450) {
            let lastErr = null;
            for (let i = 0; i < tries; i++) {
                try {
                    const res = await fetch(url, { credentials: 'same-origin' });
                    const txt = await res.text();
                    return JSON.parse(txt);
                } catch (e) {
                    lastErr = e;
                    if (i < tries - 1) {
                        await new Promise(r => setTimeout(r, delayMs));
                    }
                }
            }
            throw lastErr || new Error('No se pudo conectar');
        },
        async init() {
            await this.load(false);
            this.startPolling();
        },
        startPolling() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => this.checkIncoming(), 10000);
        },
        async checkIncoming() {
            try {
                const data = await this.fetchJsonWithRetry('/public/devbugs/api/list.php?status=all&limit=20', 2, 300);
                if (!data.ok) return;
                const latest = (data.data || []);
                if (!latest.length) return;
                const incomingMax = latest.reduce((m, r) => Math.max(m, Number(r.id || 0)), 0);
                if (this.maxSeenId > 0 && incomingMax > this.maxSeenId) {
                    this.playAlert();
                }
                this.rows = latest;
                this.maxSeenId = Math.max(this.maxSeenId, incomingMax);
            } catch (e) {}
        },
        playAlert() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                const ctx = new Ctx();
                const o1 = ctx.createOscillator();
                const g1 = ctx.createGain();
                o1.type = 'sine';
                o1.frequency.value = 880;
                g1.gain.value = 0.001;
                o1.connect(g1); g1.connect(ctx.destination);
                o1.start();
                g1.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.03);
                g1.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
                o1.stop(ctx.currentTime + 0.28);

                const o2 = ctx.createOscillator();
                const g2 = ctx.createGain();
                o2.type = 'sine';
                o2.frequency.value = 1175;
                g2.gain.value = 0.001;
                o2.connect(g2); g2.connect(ctx.destination);
                o2.start(ctx.currentTime + 0.18);
                g2.gain.exponentialRampToValueAtTime(0.07, ctx.currentTime + 0.22);
                g2.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.45);
                o2.stop(ctx.currentTime + 0.5);
            } catch (e) {}
        },
        async load(withSound = false) {
            this.errorMsg = '';
            const p = new URLSearchParams({ search: this.search, status: this.status, limit: 200 });
            let data = null;
            try {
                data = await this.fetchJsonWithRetry('/public/devbugs/api/list.php?' + p.toString(), 3, 450);
            } catch (e) {
                this.errorMsg = 'No se pudo leer la respuesta del servidor.';
                return;
            }
            if (data.ok) {
                const list = data.data || [];
                const incomingMax = list.reduce((m, r) => Math.max(m, Number(r.id || 0)), 0);
                if (withSound && this.maxSeenId > 0 && incomingMax > this.maxSeenId) {
                    this.playAlert();
                }
                this.rows = list;
                this.maxSeenId = Math.max(this.maxSeenId, incomingMax);
            } else {
                this.errorMsg = data.error || 'No se pudo cargar el listado.';
            }
        },
        open(r) {
            this.current = JSON.parse(JSON.stringify(r));
            this.copyNotice = '';
            this.show = true;
        },
        async copyErrorDetails() {
            const payload = [
                `Incidencia: ${this.current.incident || '-'}`,
                `Empresa: ${this.current.empresa_reportante || '-'} (#${this.current.id_empresa_reportante || '-'})`,
                `Usuario: ${this.current.usuario_reportante || '-'}`,
                `App: ${this.current.app || '-'}`,
                `Error: ${this.current.error_msg || '-'}`,
                `Detalle:`,
                `${this.current.error_detail || '-'}`
            ].join('\n');

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(payload);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = payload;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    document.execCommand('copy');
                    ta.remove();
                }
                this.copyNotice = 'Detalles copiados al portapapeles.';
                setTimeout(() => { this.copyNotice = ''; }, 2000);
            } catch (e) {
                this.copyNotice = 'No se pudo copiar al portapapeles.';
            }
        },
        async save() {
            const payload = {
                id: this.current.id,
                status: this.current.status,
                analysis_note: this.current.analysis_note || ''
            };
            const res = await fetch('/public/devbugs/api/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const txt = await res.text();
            let data = null;
            try { data = JSON.parse(txt); } catch (e) {
                this.errorMsg = 'No se pudo guardar: respuesta inválida.';
                return;
            }
            if (data.ok) {
                this.show = false;
                await this.load(false);
            } else {
                this.errorMsg = data.error || 'No se pudo guardar el seguimiento.';
            }
        }
    };
}
</script>
</body>
</html>
