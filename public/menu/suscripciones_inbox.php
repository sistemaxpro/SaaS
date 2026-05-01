<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
$canAccess = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true);
$alertsStorageKey = 'smx-subscription-inbox-alerts-' . $idEmpresa . '-' . $idLogin;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bandeja de Suscripciones</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="subscriptionInboxApp()" x-init="init()">
<div class="mx-auto max-w-7xl p-4 md:p-6">
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-black tracking-tight">Bandeja de Suscripciones</h1>
            <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · Usuario <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button"
                    @click="enableAlerts()"
                    class="rounded-xl border px-3 py-2 text-sm font-semibold transition"
                    :class="alertsEnabled ? 'border-emerald-600/60 bg-emerald-500/10 text-emerald-200 hover:bg-emerald-500/20' : 'border-amber-700/60 bg-amber-500/10 text-amber-200 hover:bg-amber-500/20'">
                <span x-text="alertsEnabled ? (pushSubscribed ? 'Push activo' : 'Alertas activas') : 'Activar alertas'"></span>
            </button>
            <button type="button" @click="loadUsers()" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-800">Actualizar</button>
            <button type="button" onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-800">Cerrar</button>
        </div>
    </div>

    <?php if (!$canAccess): ?>
        <div class="rounded-2xl border border-red-800 bg-red-950/40 p-5 text-red-200">
            Acceso restringido a usuarios habilitados de empresas <?= htmlspecialchars(implode(', ', SISTEMAX_SUPPORT_COMPANIES), ENT_QUOTES, 'UTF-8') ?>.
        </div>
    <?php else: ?>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <div class="rounded-2xl border border-indigo-800/50 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-indigo-300">Canal</div>
                <div class="mt-2 text-lg font-bold text-white">SistemaX Suscripciones</div>
                <div class="mt-1 text-sm text-slate-400">Respuestas salen desde la cuenta oficial de suscripciones.</div>
            </div>
            <div class="rounded-2xl border border-cyan-800/50 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-cyan-300">Empresas</div>
                <div class="mt-2 text-3xl font-black text-white" x-text="totals.companies">0</div>
                <div class="mt-1 text-sm text-slate-400">Empresas con conversaciones en esta bandeja.</div>
            </div>
            <div class="rounded-2xl border border-emerald-800/50 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-emerald-300">Usuarios</div>
                <div class="mt-2 text-3xl font-black text-white" x-text="totals.users">0</div>
                <div class="mt-1 text-sm text-slate-400">Clientes con consultas activas o históricas.</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[320px_minmax(0,1fr)] xl:grid-cols-[360px_minmax(0,1fr)]">
            <aside class="flex min-h-[60vh] flex-col rounded-2xl border border-slate-800 bg-slate-900 lg:min-h-[72vh]"
                   x-show="showUsersPane()"
                   x-cloak>
                <div class="border-b border-slate-800 p-4">
                    <div class="mb-3 flex items-center justify-between gap-2 lg:hidden">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Conversaciones</div>
                        <span class="rounded-full border border-slate-700 bg-slate-950 px-2.5 py-1 text-[11px] font-semibold text-slate-300" x-text="`${users.length} hilos`"></span>
                    </div>
                    <input type="text"
                           x-model="search"
                           @input.debounce.300ms="loadUsers()"
                           placeholder="Buscar empresa o usuario..."
                           class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                </div>
                <div class="flex-1 overflow-auto p-3 space-y-2">
                    <template x-if="loadingUsers">
                        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-4 text-sm text-slate-400">Cargando conversaciones...</div>
                    </template>
                    <template x-if="!loadingUsers && users.length === 0">
                        <div class="rounded-2xl border border-dashed border-slate-800 bg-slate-950/60 px-4 py-6 text-sm text-slate-400">Todavía no hay conversaciones en la bandeja de suscripciones.</div>
                    </template>
                    <template x-for="user in users" :key="user.id_login">
                        <button type="button"
                                @click="selectUser(user)"
                                class="w-full rounded-2xl border px-3 py-3 text-left transition"
                                :class="selected && selected.id_login === user.id_login ? 'border-cyan-500/60 bg-cyan-500/10' : 'border-slate-800 bg-slate-950/60 hover:bg-slate-950'">
                            <div class="flex items-center gap-3">
                                <img :src="user.avatar_url" alt="" class="h-12 w-12 rounded-full border border-slate-700 object-cover">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="truncate text-sm font-bold text-white" x-text="user.usuario_nombre || user.usuario_login"></div>
                                        <span x-show="Number(user.unread_count || 0) > 0"
                                              class="inline-flex min-w-[20px] justify-center rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-bold text-white"
                                              x-text="user.unread_count"></span>
                                    </div>
                                    <div class="truncate text-xs text-slate-400" x-text="user.empresa_nombre"></div>
                                    <div class="mt-1 truncate text-[11px] text-slate-500" x-text="user.last_message || 'Sin mensajes'"></div>
                                </div>
                            </div>
                        </button>
                    </template>
                </div>
            </aside>

            <section class="flex min-h-[60vh] flex-col rounded-2xl border border-slate-800 bg-slate-900 lg:min-h-[72vh]"
                     x-show="showThreadPane()"
                     x-cloak>
                <div class="border-b border-slate-800 px-4 py-4 sm:px-5">
                    <template x-if="selected">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <button type="button"
                                        x-show="showMobileBackButton()"
                                        x-cloak
                                        @click="goBackToUsers()"
                                        class="mb-3 inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-xs font-semibold text-slate-200 hover:bg-slate-900">
                                    <i class="fa-solid fa-arrow-left"></i>
                                    Volver a conversaciones
                                </button>
                                <div class="text-lg font-bold text-white" x-text="selected.usuario_nombre || selected.usuario_login"></div>
                                <div class="text-sm text-slate-400" x-text="selected.empresa_nombre"></div>
                            </div>
                            <div class="shrink-0 rounded-full border border-indigo-500/30 bg-indigo-500/10 px-3 py-1 text-xs font-semibold text-indigo-200">
                                Respondiendo como SistemaX Suscripciones
                            </div>
                        </div>
                    </template>
                    <template x-if="!selected">
                        <div class="text-sm text-slate-400">Seleccioná una conversación para ver y responder.</div>
                    </template>
                </div>

                <div class="flex-1 overflow-auto px-3 py-4 sm:px-4 space-y-3" id="subscriptionInboxThread">
                    <template x-if="loadingThread">
                        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-4 text-sm text-slate-400">Cargando conversación...</div>
                    </template>
                    <template x-if="!loadingThread && selected && messages.length === 0">
                        <div class="rounded-2xl border border-dashed border-slate-800 bg-slate-950/60 px-4 py-6 text-sm text-slate-400">Sin mensajes todavía.</div>
                    </template>
                    <template x-for="message in messages" :key="message.id">
                        <div class="flex" :class="Number(message.from_login) === Number(supportUser.id_login) ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[82%] rounded-2xl px-4 py-3 text-sm shadow-sm"
                                 :class="Number(message.from_login) === Number(supportUser.id_login) ? 'bg-cyan-500/15 border border-cyan-500/30 text-slate-100' : 'bg-slate-950/70 border border-slate-800 text-slate-100'">
                                <div class="mb-1 text-[11px] font-semibold" :class="Number(message.from_login) === Number(supportUser.id_login) ? 'text-cyan-200' : 'text-slate-400'" x-text="message.from_nombre || 'Usuario'"></div>
                                <div class="whitespace-pre-wrap break-words" x-text="message.mensaje || ''"></div>
                                <div class="mt-2 text-[10px] text-slate-500" x-text="message.sent_at || ''"></div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="border-t border-slate-800 p-4">
                    <div class="grid gap-3">
                        <textarea x-model="draft"
                                  :disabled="!selected || sending"
                                  rows="3"
                                  placeholder="Escribí una respuesta para el cliente..."
                                  class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-3 py-3 text-sm text-white outline-none focus:border-cyan-500 disabled:opacity-50"></textarea>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-slate-400" x-text="statusMessage"></div>
                            <button type="button"
                                    @click="sendMessage()"
                                    :disabled="!selected || !draft.trim() || sending"
                                    class="rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-cyan-500 disabled:opacity-50">
                                <span x-text="sending ? 'Enviando...' : 'Responder'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<div x-cloak
     x-show="toast.visible"
     x-transition.opacity
     class="fixed inset-x-3 top-3 z-50 sm:left-auto sm:right-4 sm:w-full sm:max-w-sm">
    <div class="rounded-2xl border border-cyan-500/30 bg-slate-900/95 p-4 shadow-2xl shadow-cyan-900/40 backdrop-blur">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="text-sm font-bold text-white" x-text="toast.title"></div>
                <div class="mt-1 text-sm text-slate-300" x-text="toast.message"></div>
            </div>
            <button type="button"
                    @click="hideToast()"
                    class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-xs font-semibold text-slate-300 hover:bg-slate-800">
                OK
            </button>
        </div>
    </div>
</div>

<script>
function subscriptionInboxApp() {
    return {
        alertsStorageKey: <?= json_encode($alertsStorageKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        search: '',
        users: [],
        selected: null,
        messages: [],
        totals: { companies: 0, users: 0 },
        supportUser: {},
        loadingUsers: false,
        loadingThread: false,
        sending: false,
        draft: '',
        statusMessage: '',
        isMobile: false,
        mobileView: 'list',
        pushSupported: false,
        pushSubscribed: false,
        pushRegistration: null,
        pushPublicKey: '',
        alertsEnabled: false,
        notificationsPermission: ('Notification' in window) ? Notification.permission : 'unsupported',
        audioReady: false,
        audioContext: null,
        pollHandle: null,
        hasLoadedUsersOnce: false,
        unreadSnapshot: {},
        toast: {
            visible: false,
            title: '',
            message: '',
            timer: null,
        },

        async init() {
            this.syncViewport();
            window.addEventListener('resize', () => this.syncViewport());
            this.restoreAlertsPreference();
            this.bindAudioUnlock();
            await this.initPush();
            await this.loadUsers();
            this.startPolling();
        },

        syncViewport() {
            this.isMobile = window.innerWidth < 1024;
            if (!this.isMobile) {
                this.mobileView = 'split';
            } else if (this.mobileView === 'split') {
                this.mobileView = this.selected ? 'thread' : 'list';
            }
        },

        showUsersPane() {
            return !this.isMobile || this.mobileView === 'list';
        },

        showThreadPane() {
            if (!this.isMobile) {
                return true;
            }
            return this.mobileView === 'thread' || !!this.selected;
        },

        showMobileBackButton() {
            return this.isMobile && !!this.selected;
        },

        goBackToUsers() {
            if (!this.isMobile) return;
            this.mobileView = 'list';
        },

        restoreAlertsPreference() {
            try {
                const saved = window.localStorage.getItem(this.alertsStorageKey);
                this.alertsEnabled = saved === '1';
            } catch (e) {
                this.alertsEnabled = false;
            }
        },

        persistAlertsPreference() {
            try {
                window.localStorage.setItem(this.alertsStorageKey, this.alertsEnabled ? '1' : '0');
            } catch (e) {
            }
        },

        bindAudioUnlock() {
            const unlock = () => {
                this.ensureAudioContext();
                window.removeEventListener('pointerdown', unlock);
                window.removeEventListener('touchstart', unlock);
                window.removeEventListener('keydown', unlock);
            };
            window.addEventListener('pointerdown', unlock, { once: true });
            window.addEventListener('touchstart', unlock, { once: true });
            window.addEventListener('keydown', unlock, { once: true });
        },

        ensureAudioContext() {
            try {
                if (this.audioContext) {
                    if (this.audioContext.state === 'suspended') {
                        this.audioContext.resume();
                    }
                    this.audioReady = true;
                    return;
                }
                const Context = window.AudioContext || window.webkitAudioContext;
                if (!Context) return;
                this.audioContext = new Context();
                if (this.audioContext.state === 'suspended') {
                    this.audioContext.resume();
                }
                this.audioReady = true;
            } catch (e) {
                this.audioReady = false;
            }
        },

        startPolling() {
            if (this.pollHandle) {
                window.clearInterval(this.pollHandle);
            }
            this.pollHandle = window.setInterval(async () => {
                await this.loadUsers(true);
                if (this.selected) {
                    await this.loadThread(true);
                }
            }, 15000);
            window.addEventListener('beforeunload', () => {
                if (this.pollHandle) {
                    window.clearInterval(this.pollHandle);
                }
            }, { once: true });
        },

        async enableAlerts() {
            this.ensureAudioContext();
            if ('Notification' in window) {
                try {
                    const permission = await Notification.requestPermission();
                    this.notificationsPermission = permission;
                } catch (e) {
                    this.notificationsPermission = Notification.permission;
                }
            }
            if (this.notificationsPermission === 'granted') {
                await this.subscribePush();
            }
            this.alertsEnabled = true;
            this.persistAlertsPreference();
            this.showToast('Alertas activadas', this.pushSubscribed
                ? 'La app instalada ya puede recibir push real del servidor.'
                : (this.notificationsPermission === 'granted'
                    ? 'Notificaciones locales activadas. Si la app está instalada, intentará registrar push.'
                    : 'Recibirás popup interno y sonido cuando el navegador lo permita.'));
        },

        async initPush() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                this.pushSupported = false;
                return;
            }
            try {
                this.pushRegistration = await navigator.serviceWorker.register('/public/sw.js', { scope: '/public/' });
                const readyRegistration = await navigator.serviceWorker.ready;
                this.pushRegistration = readyRegistration;
                this.pushSupported = true;
                const existingSubscription = await readyRegistration.pushManager.getSubscription();
                this.pushSubscribed = !!existingSubscription;
                if (this.pushSubscribed && this.notificationsPermission === 'granted') {
                    this.alertsEnabled = true;
                    this.persistAlertsPreference();
                }
                if (this.notificationsPermission === 'granted') {
                    await this.subscribePush(true);
                }
            } catch (e) {
                this.pushSupported = false;
            }
        },

        async fetchPushPublicKey() {
            if (this.pushPublicKey) {
                return this.pushPublicKey;
            }
            const res = await fetch(`/public/menu/api/chat.php?action=push_public_key&_ts=${Date.now()}`, {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const data = await res.json();
            if (!data || !data.success || !data.public_key) {
                throw new Error((data && data.error) || 'No se pudo obtener clave push');
            }
            this.pushPublicKey = String(data.public_key || '');
            return this.pushPublicKey;
        },

        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        },

        async subscribePush(silent = false) {
            if (!this.pushSupported || this.notificationsPermission !== 'granted') {
                return false;
            }
            try {
                const publicKey = await this.fetchPushPublicKey();
                const registration = this.pushRegistration || await navigator.serviceWorker.ready;
                let subscription = await registration.pushManager.getSubscription();
                if (!subscription) {
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: this.urlBase64ToUint8Array(publicKey),
                    });
                }
                const subscriptionJson = subscription.toJSON();
                const contentEncoding = (PushManager.supportedContentEncodings && PushManager.supportedContentEncodings[0]) || 'aes128gcm';
                const res = await fetch('/public/menu/api/chat.php?action=push_subscribe', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        subscription: subscriptionJson,
                        content_encoding: contentEncoding,
                    }),
                });
                const data = await res.json();
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'No se pudo registrar push');
                }
                this.pushSubscribed = true;
                this.alertsEnabled = true;
                this.persistAlertsPreference();
                if (!silent) {
                    this.showToast('Push activado', 'La app instalada recibirá notificaciones del servidor cuando entren chats nuevos.');
                }
                return true;
            } catch (e) {
                this.pushSubscribed = false;
                if (this.notificationsPermission === 'granted' && this.alertsEnabled) {
                    this.persistAlertsPreference();
                }
                if (!silent) {
                    this.showToast('Push no disponible', e && e.message ? e.message : 'No se pudo activar el push del navegador.');
                }
                return false;
            }
        },

        snapshotUnread(users) {
            const map = {};
            (Array.isArray(users) ? users : []).forEach((user) => {
                map[String(user.id_login)] = Number(user.unread_count || 0);
            });
            return map;
        },

        detectIncomingMessages(users) {
            if (!this.hasLoadedUsersOnce) {
                this.unreadSnapshot = this.snapshotUnread(users);
                this.hasLoadedUsersOnce = true;
                return;
            }
            const nextSnapshot = this.snapshotUnread(users);
            for (const user of (Array.isArray(users) ? users : [])) {
                const key = String(user.id_login);
                const previous = Number(this.unreadSnapshot[key] || 0);
                const current = Number(user.unread_count || 0);
                if (current > previous) {
                    this.notifyIncomingMessage(user, current - previous);
                    break;
                }
            }
            this.unreadSnapshot = nextSnapshot;
        },

        notifyIncomingMessage(user, delta) {
            const sender = user && (user.usuario_nombre || user.usuario_login) ? (user.usuario_nombre || user.usuario_login) : 'Cliente';
            const company = user && user.empresa_nombre ? user.empresa_nombre : 'Empresa';
            const body = `${company}: ${user.last_message || 'Nuevo mensaje en la bandeja.'}`;
            this.showToast(`Nuevo mensaje de ${sender}`, body);
            if (this.alertsEnabled) {
                this.playAlertSound();
                this.showSystemNotification(user, delta, body);
            }
        },

        async showSystemNotification(user, delta, body) {
            if (!('Notification' in window) || this.notificationsPermission !== 'granted') {
                return;
            }
            const title = `SistemaX Suscripciones (${delta})`;
            const options = {
                body,
                tag: `subscription-inbox-${user.id_login}`,
                renotify: true,
                data: {
                    url: '/public/menu/suscripciones_inbox.php',
                    peer_login: Number(user.id_login || 0),
                },
            };

            try {
                if ('serviceWorker' in navigator) {
                    const registration = await navigator.serviceWorker.ready;
                    if (registration && typeof registration.showNotification === 'function') {
                        await registration.showNotification(title, options);
                        return;
                    }
                }
            } catch (e) {
            }

            try {
                new Notification(title, options);
            } catch (e) {
            }
        },

        playAlertSound() {
            if (!this.audioReady) return;
            try {
                this.ensureAudioContext();
                if (!this.audioContext) return;
                const now = this.audioContext.currentTime;
                const gain = this.audioContext.createGain();
                gain.connect(this.audioContext.destination);
                gain.gain.setValueAtTime(0.0001, now);
                gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.28);

                const osc = this.audioContext.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, now);
                osc.frequency.exponentialRampToValueAtTime(660, now + 0.25);
                osc.connect(gain);
                osc.start(now);
                osc.stop(now + 0.3);
            } catch (e) {
            }
        },

        showToast(title, message) {
            if (this.toast.timer) {
                window.clearTimeout(this.toast.timer);
            }
            this.toast = {
                visible: true,
                title: title || 'SistemaX',
                message: message || '',
                timer: window.setTimeout(() => this.hideToast(), 5000),
            };
        },

        hideToast() {
            if (this.toast.timer) {
                window.clearTimeout(this.toast.timer);
            }
            this.toast.visible = false;
            this.toast.timer = null;
        },

        async loadUsers(silent = false) {
            if (!silent) {
                this.loadingUsers = true;
                this.statusMessage = '';
            }
            try {
                const url = `/public/menu/api/chat.php?action=subscription_inbox_users&q=${encodeURIComponent(this.search || '')}&_ts=${Date.now()}`;
                const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.success) throw new Error((data && data.error) || 'No se pudo cargar la bandeja');
                const nextUsers = Array.isArray(data.items) ? data.items : [];
                this.detectIncomingMessages(nextUsers);
                this.users = nextUsers;
                this.totals = {
                    companies: Number(data.total_companies || 0),
                    users: Number(data.total_users || 0),
                };
                this.supportUser = data.support_user || {};
                if (this.selected) {
                    const refreshed = this.users.find((item) => Number(item.id_login) === Number(this.selected.id_login));
                    this.selected = refreshed || null;
                }
                if (!this.selected && this.users.length > 0) {
                    await this.selectUser(this.users[0]);
                } else if (!this.selected && this.isMobile) {
                    this.mobileView = 'list';
                }
            } catch (e) {
                if (!silent) {
                    this.statusMessage = e && e.message ? e.message : 'No se pudo cargar la bandeja';
                }
            } finally {
                if (!silent) {
                    this.loadingUsers = false;
                }
            }
        },

        async selectUser(user) {
            this.selected = user || null;
            this.messages = [];
            this.draft = '';
            if (!this.selected) return;
            if (this.isMobile) {
                this.mobileView = 'thread';
            }
            await this.loadThread();
        },

        async loadThread(silent = false) {
            if (!this.selected) return;
            if (!silent) {
                this.loadingThread = true;
                this.statusMessage = '';
            }
            try {
                const url = `/public/menu/api/chat.php?action=subscription_inbox_thread&peer_login=${encodeURIComponent(this.selected.id_login)}&_ts=${Date.now()}`;
                const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                const data = await res.json();
                if (!data || !data.success) throw new Error((data && data.error) || 'No se pudo cargar la conversación');
                this.messages = Array.isArray(data.items) ? data.items : [];
                queueMicrotask(() => {
                    const box = document.getElementById('subscriptionInboxThread');
                    if (box) box.scrollTop = box.scrollHeight;
                });
            } catch (e) {
                if (!silent) {
                    this.statusMessage = e && e.message ? e.message : 'No se pudo cargar la conversación';
                }
            } finally {
                if (!silent) {
                    this.loadingThread = false;
                }
            }
        },

        async sendMessage() {
            if (!this.selected || !this.draft.trim() || this.sending) return;
            this.sending = true;
            this.statusMessage = '';
            try {
                const res = await fetch('/public/menu/api/chat.php?action=subscription_inbox_send', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        peer_login: Number(this.selected.id_login),
                        mensaje: this.draft.trim(),
                    })
                });
                const data = await res.json();
                if (!data || !data.success) throw new Error((data && data.error) || 'No se pudo enviar la respuesta');
                this.draft = '';
                await this.loadThread();
                await this.loadUsers();
                this.statusMessage = 'Respuesta enviada.';
            } catch (e) {
                this.statusMessage = e && e.message ? e.message : 'No se pudo enviar la respuesta';
            } finally {
                this.sending = false;
            }
        }
    };
}
</script>
</body>
</html>
