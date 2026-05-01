<?php
/**
 * Módulo Gestión de Gastos - Mobile + Voz (registro directo)
 */

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_caja');
$permisos = Permission::getAppPermissions('app_grid_caja');
?>
<!DOCTYPE html>
<html lang="es" x-data="gastosMobileApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gastos Móvil - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        };
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        body { overscroll-behavior-y: contain; -webkit-tap-highlight-color: transparent; }
        input, select, textarea { font-size: 16px !important; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans antialiased pb-24">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>

<div x-cloak x-show="toast.show" x-transition
     class="fixed top-4 left-1/2 -translate-x-1/2 z-[90] px-4 py-2 rounded-xl text-sm font-semibold shadow-lg"
     :class="toast.type === 'error' ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white'">
    <span x-text="toast.message"></span>
</div>
<div x-cloak x-show="showVoiceModal" class="fixed inset-0 z-[95] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/55" @click="cancelVoiceEntry()"></div>
    <div class="relative w-full max-w-md bg-white dark:bg-slate-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-slate-700 p-5">
        <h3 class="text-base font-bold text-gray-900 dark:text-white mb-3">Confirmar carga por voz</h3>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between gap-3">
                <span class="text-gray-500">Concepto</span>
                <span class="font-semibold text-gray-900 dark:text-white text-right" x-text="pendingVoiceEntry.concepto || '-'"></span>
            </div>
            <div class="flex justify-between gap-3">
                <span class="text-gray-500">Monto</span>
                <span class="font-semibold text-gray-900 dark:text-white" x-text="formatMoney(pendingVoiceEntry.monto || 0)"></span>
            </div>
            <div class="flex justify-between gap-3">
                <span class="text-gray-500">Pagado por</span>
                <span class="font-semibold text-gray-900 dark:text-white text-right" x-text="(pendingVoiceEntry.pagado_por_tipo || '') + (pendingVoiceEntry.pagado_por_detalle ? ' · ' + pendingVoiceEntry.pagado_por_detalle : '')"></span>
            </div>
        </div>
        <div class="mt-5 grid grid-cols-3 gap-2">
            <button @click="cancelVoiceEntry()" class="py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold">Cancelar</button>
            <button @click="repeatVoiceEntry()" class="py-2 rounded-lg bg-amber-100 text-amber-800 text-sm font-semibold">Repetir</button>
            <button @click="acceptVoiceEntry()" :disabled="saving" class="py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold disabled:opacity-50">Aceptar</button>
        </div>
    </div>
</div>

<header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-30">
    <div class="px-4 h-14 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-600 dark:text-gray-300">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1 class="text-lg font-bold text-gray-900 dark:text-white"><i class="fas fa-file-invoice-dollar text-emerald-600 mr-1"></i>Gastos</h1>
        </div>
        <a href="index.php?desktop=1" class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
            <i class="fas fa-desktop text-sm"></i>
        </a>
    </div>
</header>

<div class="px-4 py-3 space-y-3">
    <div class="grid grid-cols-2 gap-3">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3 text-center">
            <p class="text-[11px] text-gray-500">Mes</p>
            <p class="text-lg font-bold text-emerald-600" x-text="formatMoney(stats.monto_mes)"></p>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3 text-center">
            <p class="text-[11px] text-gray-500">Total</p>
            <p class="text-lg font-bold text-gray-900 dark:text-white" x-text="stats.cantidad"></p>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 space-y-3">
        <h2 class="text-sm font-bold text-gray-900 dark:text-white">Carga rápida</h2>
        <input x-model="form.concepto" type="text" placeholder="Concepto"
               class="w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600">
        <input x-model="form.monto" type="number" step="0.01" min="0" placeholder="Monto"
               class="w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600">
        <div class="grid grid-cols-2 gap-2">
            <select x-model="form.pagado_por_tipo" class="px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600">
                <template x-for="t in tiposPago" :key="t"><option :value="t" x-text="t"></option></template>
            </select>
            <select x-model="form.id_caja" class="px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600">
                <option value="">Sin caja</option>
                <template x-for="c in cajas" :key="c.id_caja"><option :value="c.id_caja" x-text="c.caja"></option></template>
            </select>
        </div>
        <input x-model="form.pagado_por_detalle" list="pagadoPorSugeridos" type="text" placeholder="Detalle de pago"
               class="w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600">
        <datalist id="pagadoPorSugeridos">
            <template x-for="s in pagadoPorSugeridos" :key="s"><option :value="s"></option></template>
        </datalist>
        <button @click="registrarManual()" :disabled="saving || permisos.priv_insert !== 'Y'"
                class="w-full py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-semibold disabled:opacity-50">
            <span x-show="!saving">Guardar gasto</span>
            <span x-show="saving">Guardando...</span>
        </button>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white">Asistente de voz</h2>
            <span class="text-[11px] px-2 py-0.5 rounded-full"
                  :class="voiceState === 'idle' ? 'bg-gray-100 text-gray-600' : 'bg-emerald-100 text-emerald-700'"
                  x-text="voiceStateLabel()"></span>
        </div>
        <p class="text-xs text-gray-500 mb-2">Ejemplo: "luz 35000, pagado por tarjeta continental". Luego confirma en el modal.</p>
        <div class="flex gap-2">
            <button @click="startVoiceFlow()" :disabled="isListening || !voiceEnabled"
                    class="flex-1 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold disabled:opacity-50">
                <i class="fas fa-microphone mr-1"></i><span x-text="isListening ? 'Escuchando...' : 'Hablar'"></span>
            </button>
            <button @click="stopListening()" :disabled="!isListening" class="px-4 py-2.5 rounded-xl bg-gray-200 text-gray-700 text-sm font-semibold disabled:opacity-50">
                Detener
            </button>
        </div>
        <p class="text-xs mt-2" :class="voiceEnabled ? 'text-gray-500' : 'text-red-500'" x-text="voiceMessage"></p>
        <p class="text-xs text-gray-500 mt-1" x-show="lastTranscript">Último: <span class="font-medium" x-text="lastTranscript"></span></p>
        <div x-show="voiceDraft" class="mt-3 p-3 rounded-lg bg-indigo-50 text-indigo-700 text-xs">
            Borrador: <span x-text="voiceDraft ? (voiceDraft.concepto + ' - ' + formatMoney(voiceDraft.monto)) : ''"></span>
        </div>
    </div>

    <div class="space-y-2">
        <template x-for="g in gastos" :key="g.id_gasto">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white" x-text="g.concepto"></h3>
                        <p class="text-[11px] text-gray-500" x-text="formatDate(g.fecha)"></p>
                        <p class="text-[11px] text-gray-500" x-text="(g.pagado_por_tipo || '') + (g.pagado_por_detalle ? ' · ' + g.pagado_por_detalle : '')"></p>
                    </div>
                    <p class="text-sm font-bold text-red-600" x-text="formatMoney(g.monto)"></p>
                </div>
            </div>
        </template>
        <div x-show="!loading && gastos.length === 0" class="py-8 text-center text-gray-400 text-sm">Sin gastos</div>
    </div>
</div>

<script>
function gastosMobileApp() {
    return {
        permisos: window.__PERMISOS__ || {},
        loading: false,
        saving: false,
        gastos: [],
        stats: { monto_total: 0, monto_mes: 0, cantidad: 0 },
        tiposPago: ['CAJA', 'TARJETA', 'TRANSFERENCIA', 'PROVEEDOR', 'OTRO'],
        pagadoPorSugeridos: [],
        cajas: [],
        form: {
            concepto: '',
            monto: '',
            pagado_por_tipo: 'CAJA',
            pagado_por_detalle: '',
            id_caja: '',
        },

        recognition: null,
        isListening: false,
        voiceEnabled: false,
        voiceState: 'idle',
        voiceDraft: null,
        voiceMessage: 'Presiona "Hablar" para empezar.',
        lastTranscript: '',
        voiceStartDelayMs: 1800,
        voiceRetryDelayMs: 1500,
        noSpeechRetries: 0,
        maxNoSpeechRetries: 8,
        voiceSessionEndsAt: 0,
        voiceAutoRestartMs: 450,

        toast: { show: false, message: '', type: 'success' },
        showVoiceModal: false,
        pendingVoiceEntry: { concepto: '', monto: 0, pagado_por_tipo: 'CAJA', pagado_por_detalle: '' },
        offlineQueue: [],

        offlineKey(suffix) {
            return `smx:gastos:mobile:<?= (int) Session::get('id_empresa', 169) ?>:${suffix}`;
        },
        readStorage(key, fallback = null) {
            return window.SmxOfflineDb?.getSync(key, fallback) ?? fallback;
        },
        writeStorage(key, data) {
            return window.SmxOfflineDb?.set(key, data);
        },
        buildOfflineGasto(payload) {
            const now = new Date();
            return {
                id_gasto: `offline-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                concepto: payload.concepto,
                monto: Number(payload.monto || 0),
                pagado_por_tipo: payload.pagado_por_tipo || 'CAJA',
                pagado_por_detalle: payload.pagado_por_detalle || '',
                fecha: now.toISOString(),
                estado: 'ACTIVO',
                offline_pending: true,
            };
        },
        mergeQueuedGastos(items) {
            const seen = new Set();
            return [...this.offlineQueue.map(entry => entry.preview).filter(Boolean), ...(Array.isArray(items) ? items : [])]
                .filter((row) => {
                    const key = String(row.id_gasto || '');
                    if (seen.has(key)) return false;
                    seen.add(key);
                    return true;
                });
        },
        async syncOfflineQueue() {
            if (!navigator.onLine || !this.offlineQueue.length) return;
            const pending = [...this.offlineQueue];
            const remaining = [];
            for (const entry of pending) {
                try {
                    const res = await fetch('./api/registrar.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(entry.payload),
                    });
                    const data = await res.json();
                    if (!data.ok) throw new Error(data.error || 'No se pudo sincronizar gasto');
                } catch (_) {
                    remaining.push(entry);
                }
            }
            this.offlineQueue = remaining;
            this.writeStorage(this.offlineKey('queue'), remaining);
            await this.loadCatalogos();
            await this.loadGastos();
        },

        async init() {
            await (window.SmxOfflineDb?.ready || Promise.resolve());
            this.offlineQueue = this.readStorage(this.offlineKey('queue'), []);
            window.addEventListener('online', () => this.syncOfflineQueue());
            this.setupVoice();
            await this.loadCatalogos();
            await this.loadGastos();
            await this.syncOfflineQueue();
        },

        setupVoice() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) {
                this.voiceEnabled = false;
                this.voiceMessage = 'Tu navegador no soporta reconocimiento de voz.';
                return;
            }
            this.voiceEnabled = true;
            const rec = new SR();
            rec.lang = 'es-PY';
            rec.interimResults = false;
            rec.continuous = false;
            rec.maxAlternatives = 1;
            rec.onstart = () => { this.isListening = true; };
            rec.onend = () => {
                this.isListening = false;
                if (
                    this.voiceState === 'awaiting_command' &&
                    Date.now() < this.voiceSessionEndsAt &&
                    !this.showVoiceModal
                ) {
                    setTimeout(() => this.startListening(), this.voiceAutoRestartMs);
                }
            };
            rec.onerror = (ev) => {
                this.isListening = false;
                const err = ev.error || 'desconocido';
                if (err === 'no-speech' && this.voiceState !== 'idle') {
                    this.noSpeechRetries += 1;
                    this.voiceMessage = 'No te escuché. Habla ahora...';
                    if (this.noSpeechRetries <= this.maxNoSpeechRetries) {
                        setTimeout(() => this.startListening(), this.voiceRetryDelayMs);
                    } else {
                        this.voiceMessage = 'No detecté voz. Presiona "Hablar" para reintentar.';
                        this.voiceState = 'idle';
                        this.noSpeechRetries = 0;
                    }
                    return;
                }
                if (err === 'not-allowed' || err === 'service-not-allowed') {
                    this.voiceMessage = 'Micrófono bloqueado. Habilita permisos del navegador.';
                    this.voiceState = 'idle';
                    return;
                }
                this.voiceMessage = 'Error de voz: ' + err;
            };
            rec.onresult = (event) => {
                const text = ((event.results && event.results[0] && event.results[0][0] && event.results[0][0].transcript) || '').trim();
                if (!text) return;
                this.lastTranscript = text;
                this.handleVoiceText(text);
            };
            this.recognition = rec;
        },

        startListening() {
            if (!this.recognition) return;
            try { this.recognition.start(); } catch (e) {}
        },

        stopListening() {
            if (!this.recognition) return;
            try { this.recognition.stop(); } catch (e) {}
            this.voiceState = 'idle';
            this.voiceSessionEndsAt = 0;
        },

        startVoiceFlow() {
            if (!this.voiceEnabled) return;
            this.voiceState = 'awaiting_command';
            this.voiceDraft = null;
            this.noSpeechRetries = 0;
            this.voiceSessionEndsAt = Date.now() + 20000;
            this.voiceMessage = 'Preparando micrófono... Di: concepto monto, pagado por medio.';
            setTimeout(() => this.startListening(), this.voiceStartDelayMs);
        },

        handleVoiceText(textRaw) {
            const text = (textRaw || '').toLowerCase().trim();
            if (this.voiceState !== 'awaiting_command') return;

            const parsed = this.parseExpenseCommand(text);
            if (!parsed) {
                this.voiceMessage = 'No entendí. Ejemplo: luz 35000, pagado por caja';
                setTimeout(() => this.startListening(), this.voiceRetryDelayMs);
                return;
            }

            this.voiceDraft = parsed;
            this.pendingVoiceEntry = { ...parsed };
            this.form.concepto = parsed.concepto || '';
            this.form.monto = String(Math.round(parsed.monto || 0));
            this.form.pagado_por_tipo = parsed.pagado_por_tipo || 'CAJA';
            this.form.pagado_por_detalle = parsed.pagado_por_detalle || '';
            this.voiceState = 'idle';
            this.voiceMessage = 'Revisa el modal y confirma la carga.';
            this.showVoiceModal = true;
        },

        parseExpenseCommand(text) {
            const cleaned = (text || '').replace(/\s+/g, ' ').trim();
            if (!cleaned) return null;

            const amountMatch = cleaned.match(/(?:^|\s)(\d[\d.,]*)(?=\s|$)/);
            if (!amountMatch) return null;
            const montoRaw = amountMatch[1] || '';
            const monto = this.parseAmount(montoRaw);
            if (monto <= 0) return null;

            let work = cleaned;

            // Quitar solo la primera ocurrencia del monto detectado.
            const amountEscaped = montoRaw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            work = work.replace(new RegExp(`(^|\\s)${amountEscaped}(?=\\s|$)`, 'i'), ' ');

            // Detectar medio de pago tanto en formato natural como libre.
            const paymentFromPhrase = work.match(/pagado por\s+(.+)$/i);
            const medioPart = paymentFromPhrase ? (paymentFromPhrase[1] || '').trim() : '';
            const pago = this.parsePaymentMethod(medioPart || work);

            // Quitar frases de pago para aislar concepto.
            work = work
                .replace(/pagado por\s+.+$/i, ' ')
                .replace(/\b(caja|efectivo|tarjeta|transferencia|banco|proveedor)\b/gi, ' ')
                .replace(/\b(de|con|por|en)\b/gi, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            // Remover detalle de pago detectado si quedó embebido.
            if (pago && pago.pagado_por_detalle) {
                const detailEscaped = String(pago.pagado_por_detalle).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                work = work.replace(new RegExp(detailEscaped, 'i'), ' ').replace(/\s+/g, ' ').trim();
            }

            const concepto = work.replace(/[,:;.-]+$/g, '').trim();
            if (!concepto) return null;

            return {
                concepto,
                monto,
                pagado_por_tipo: (pago && pago.pagado_por_tipo) ? pago.pagado_por_tipo : (this.form.pagado_por_tipo || 'CAJA'),
                pagado_por_detalle: (pago && pago.pagado_por_detalle) ? pago.pagado_por_detalle : (this.form.pagado_por_detalle || ''),
            };
        },

        parsePaymentMethod(raw) {
            const t = String(raw || '').toLowerCase().trim();
            if (!t) return null;
            if (t.includes('caja') || t.includes('efectivo')) return { pagado_por_tipo: 'CAJA', pagado_por_detalle: 'Caja' };
            if (t.includes('tarjeta')) {
                const m = t.match(/tarjeta(?:\s+[a-z0-9áéíóúñ]+){0,3}/i);
                return { pagado_por_tipo: 'TARJETA', pagado_por_detalle: this.capitalizeWords((m && m[0]) ? m[0] : 'tarjeta') };
            }
            if (t.includes('transferencia') || t.includes('banco')) {
                const m = t.match(/(?:transferencia|banco)(?:\s+[a-z0-9áéíóúñ]+){0,3}/i);
                return { pagado_por_tipo: 'TRANSFERENCIA', pagado_por_detalle: this.capitalizeWords((m && m[0]) ? m[0] : 'transferencia') };
            }
            if (t.includes('proveedor')) {
                const m = t.match(/proveedor(?:\s+[a-z0-9áéíóúñ]+){0,4}/i);
                return { pagado_por_tipo: 'PROVEEDOR', pagado_por_detalle: this.capitalizeWords((m && m[0]) ? m[0] : 'proveedor') };
            }
            return { pagado_por_tipo: 'OTRO', pagado_por_detalle: this.capitalizeWords(t) };
        },

        capitalizeWords(v) {
            return String(v || '').replace(/\b\w/g, (c) => c.toUpperCase());
        },

        parseAmount(v) {
            const raw = String(v || '').trim();
            if (!raw) return 0;
            if (raw.includes(',') && raw.includes('.')) {
                return Number(raw.replace(/\./g, '').replace(',', '.')) || 0;
            }
            if (raw.includes(',') && !raw.includes('.')) {
                const parts = raw.split(',');
                if ((parts[1] || '').length <= 2) {
                    return Number(raw.replace(',', '.')) || 0;
                }
            }
            return Number(raw.replace(/,/g, '')) || 0;
        },

        showToast(message, type = 'success') {
            this.toast.message = message;
            this.toast.type = type;
            this.toast.show = true;
            setTimeout(() => { this.toast.show = false; }, 2200);
        },

        cancelVoiceEntry() {
            this.showVoiceModal = false;
            this.pendingVoiceEntry = { concepto: '', monto: 0, pagado_por_tipo: 'CAJA', pagado_por_detalle: '' };
            this.voiceState = 'idle';
            this.voiceSessionEndsAt = 0;
            this.voiceMessage = 'Operación cancelada.';
        },

        repeatVoiceEntry() {
            this.showVoiceModal = false;
            this.pendingVoiceEntry = { concepto: '', monto: 0, pagado_por_tipo: 'CAJA', pagado_por_detalle: '' };
            this.startVoiceFlow();
        },

        async acceptVoiceEntry() {
            this.showVoiceModal = false;
            await this.registrarManual({ fromVoice: true, keepForm: true });
        },

        async registrarManual(opts = {}) {
            const fromVoice = !!opts.fromVoice;
            const keepForm = !!opts.keepForm;

            if ((this.form.concepto || '').trim() === '') {
                this.showToast('Concepto obligatorio', 'error');
                return;
            }
            if (Number(this.form.monto || 0) <= 0) {
                this.showToast('Monto inválido', 'error');
                return;
            }

            this.saving = true;
            try {
                const payload = {
                    concepto: this.form.concepto,
                    monto: Number(this.form.monto),
                    pagado_por_tipo: this.form.pagado_por_tipo,
                    pagado_por_detalle: this.form.pagado_por_detalle,
                    id_caja: this.form.id_caja ? Number(this.form.id_caja) : 0,
                    origen: fromVoice ? 'VOZ' : 'MANUAL',
                };
                if (!navigator.onLine) {
                    this.offlineQueue.unshift({ payload, preview: this.buildOfflineGasto(payload) });
                    this.writeStorage(this.offlineKey('queue'), this.offlineQueue);
                    if (!keepForm) {
                        this.form.concepto = '';
                        this.form.monto = '';
                        this.form.pagado_por_detalle = '';
                    }
                    this.voiceDraft = null;
                    this.voiceState = 'idle';
                    this.voiceMessage = 'Gasto guardado offline.';
                    await this.loadGastos();
                    this.showToast('Gasto guardado offline', 'success');
                    return;
                }
                const res = await fetch('./api/registrar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'No se pudo guardar');

                if (!keepForm) {
                    this.form.concepto = '';
                    this.form.monto = '';
                    this.form.pagado_por_detalle = '';
                }

                this.voiceDraft = null;
                this.voiceState = 'idle';
                this.voiceMessage = 'Gasto guardado.';

                await this.loadCatalogos();
                await this.loadGastos();
                this.showToast('Gasto guardado');
            } catch (e) {
                this.voiceState = 'idle';
                this.voiceMessage = e.message || 'Error al guardar gasto';
                this.showToast(this.voiceMessage, 'error');
            } finally {
                this.saving = false;
            }
        },

        async loadCatalogos() {
            try {
                const res = await fetch('./api/catalogos.php');
                const data = await res.json();
                if (!data.ok) return;
                this.cajas = data.cajas || [];
                this.pagadoPorSugeridos = data.pagado_por_sugeridos || [];
                this.tiposPago = data.tipos_pago || this.tiposPago;
                this.writeStorage(this.offlineKey('catalogos'), {
                    cajas: this.cajas,
                    pagadoPorSugeridos: this.pagadoPorSugeridos,
                    tiposPago: this.tiposPago,
                });
            } catch (e) {
                const cached = this.readStorage(this.offlineKey('catalogos'));
                if (cached) {
                    this.cajas = cached.cajas || [];
                    this.pagadoPorSugeridos = cached.pagadoPorSugeridos || [];
                    this.tiposPago = cached.tiposPago || this.tiposPago;
                    return;
                }
                console.error(e);
            }
        },

        async loadGastos() {
            this.loading = true;
            try {
                const res = await fetch('./api/list.php?per_page=20&estado=ACTIVO');
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Error');
                this.gastos = this.mergeQueuedGastos(data.data || []);
                this.stats = data.stats || this.stats;
                this.writeStorage(this.offlineKey('list'), {
                    gastos: data.data || [],
                    stats: this.stats,
                });
            } catch (e) {
                const cached = this.readStorage(this.offlineKey('list'));
                if (cached) {
                    this.gastos = this.mergeQueuedGastos(cached.gastos || []);
                    this.stats = cached.stats || this.stats;
                    this.showToast('Mostrando gastos desde cache offline', 'error');
                } else {
                    console.error(e);
                }
            } finally {
                this.loading = false;
            }
        },

        voiceStateLabel() {
            const labels = {
                idle: 'En espera',
                awaiting_command: 'Esperando comando',
            };
            return labels[this.voiceState] || 'En espera';
        },

        formatMoney(v) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: 'PYG', maximumFractionDigits: 0 }).format(Number(v || 0));
        },

        formatDate(v) {
            const d = new Date(v);
            if (Number.isNaN(d.getTime())) return v || '';
            return d.toLocaleString('es-PY');
        },
    };
}
</script>
</body>
</html>
