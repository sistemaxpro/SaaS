<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$empresa = (string)(Session::get('empresa') ?? 'SistemaX');
$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico Push</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="pushDiagApp()" x-init="init()">
    <main class="mx-auto max-w-3xl px-4 py-5 sm:px-6">
        <section class="rounded-[28px] border border-slate-800 bg-slate-900/90 p-5 shadow-2xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">SistemaX</div>
                    <h1 class="mt-2 text-3xl font-black text-white">Diagnóstico Push</h1>
                    <p class="mt-2 text-sm text-slate-400">Verificá si este celular está listo para recibir notificaciones aunque la app esté cerrada.</p>
                </div>
                <a href="/public/menu/menu.php" class="rounded-full border border-slate-700 px-4 py-2 text-xs font-bold text-slate-200 hover:bg-slate-800">Volver</a>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                    <div class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Empresa / Usuario</div>
                    <div class="mt-2 text-base font-bold text-white"><?= htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-1 text-sm text-slate-400">Empresa <?= $idEmpresa ?> · Login <?= $idLogin ?></div>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                    <div class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Estado general</div>
                    <div class="mt-2 text-base font-bold" :class="readyForBackgroundPush ? 'text-emerald-300' : 'text-amber-300'" x-text="readyForBackgroundPush ? 'Listo para push' : 'Falta configurar'"></div>
                    <div class="mt-1 text-sm text-slate-400" x-text="readyHint"></div>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <template x-for="item in clientChecks" :key="item.key">
                    <div class="rounded-2xl border p-4" :class="item.ok ? 'border-emerald-700/60 bg-emerald-500/10' : 'border-amber-700/60 bg-amber-500/10'">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-[11px] uppercase tracking-[0.22em] text-slate-400" x-text="item.label"></div>
                                <div class="mt-1 text-sm font-bold" x-text="item.value"></div>
                            </div>
                            <div class="text-xl" x-text="item.ok ? 'OK' : '!'"></div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <button @click="requestNotifications()" class="rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-500">Conceder notificaciones</button>
                <button @click="syncPush()" class="rounded-xl bg-cyan-600 px-4 py-3 text-sm font-bold text-white hover:bg-cyan-500">Reparar suscripción push</button>
                <button @click="sendTest()" :disabled="sendingTest" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white hover:bg-emerald-500 disabled:opacity-50" x-text="sendingTest ? 'Enviando...' : 'Enviar prueba'"></button>
                <button @click="sendNativeFcmTest()" :disabled="sendingNativeFcmTest" class="rounded-xl bg-violet-600 px-4 py-3 text-sm font-bold text-white hover:bg-violet-500 disabled:opacity-50" x-text="sendingNativeFcmTest ? 'Enviando FCM...' : 'Probar autorización nativa FCM'"></button>
                <button @click="refreshServerStatus()" class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-bold text-slate-200 hover:bg-slate-800">Actualizar</button>
            </div>

            <div x-show="message" x-cloak class="mt-4 rounded-2xl border border-slate-800 bg-slate-950/80 px-4 py-3 text-sm" :class="messageType === 'error' ? 'text-rose-300' : 'text-cyan-200'">
                <span x-text="message"></span>
            </div>

            <div class="mt-6 rounded-[24px] border border-slate-800 bg-slate-950/80 p-4">
                <div class="text-sm font-bold text-white">Servidor</div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-4">
                        <div class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Suscripciones</div>
                        <div class="mt-2 text-2xl font-black text-cyan-300" x-text="server.subscription.active_count + ' activas'"></div>
                        <div class="mt-1 text-xs text-slate-400" x-text="'Total registradas: ' + server.subscription.total"></div>
                    </div>
                    <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-4">
                        <div class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Último envío OK</div>
                        <div class="mt-2 text-sm font-bold text-white" x-text="server.subscription.last_success_at || 'Sin datos'"></div>
                        <div class="mt-1 text-xs text-rose-300" x-show="server.subscription.last_error" x-text="server.subscription.last_error"></div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/80 overflow-hidden">
                    <div class="border-b border-slate-800 px-4 py-3 text-sm font-bold text-white">Últimos push enviados</div>
                    <div class="divide-y divide-slate-800">
                        <template x-for="row in server.recent_pushes" :key="row.id">
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-bold text-white" x-text="row.title"></div>
                                    <div class="text-xs font-bold" :class="Number(row.ok) === 1 ? 'text-emerald-300' : 'text-rose-300'" x-text="Number(row.ok) === 1 ? 'OK' : 'ERROR'"></div>
                                </div>
                                <div class="mt-1 text-sm text-slate-300" x-text="row.body"></div>
                                <div class="mt-1 text-xs text-slate-500" x-text="(row.channel || 'push') + ' · ' + (row.created_at || '') + (row.status_code ? ' · HTTP ' + row.status_code : '')"></div>
                            </div>
                        </template>
                        <div x-show="!server.recent_pushes.length" class="px-4 py-6 text-sm text-slate-500">Todavía no hay push registrados para este usuario.</div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-slate-800 bg-slate-900/80 overflow-hidden">
                    <div class="border-b border-slate-800 px-4 py-3 text-sm font-bold text-white">Últimos FCM nativos</div>
                    <div class="divide-y divide-slate-800">
                        <template x-for="row in server.recent_fcm" :key="'fcm-' + row.id">
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-bold text-white" x-text="row.title"></div>
                                    <div class="text-xs font-bold" :class="Number(row.ok) === 1 ? 'text-emerald-300' : 'text-rose-300'" x-text="Number(row.ok) === 1 ? 'OK' : 'ERROR'"></div>
                                </div>
                                <div class="mt-1 text-sm text-slate-300" x-text="row.body"></div>
                                <div class="mt-1 text-xs text-slate-500" x-text="(row.channel || 'fcm') + ' · ' + (row.created_at || '')"></div>
                                <div class="mt-1 text-xs text-rose-300" x-show="row.error_text" x-text="row.error_text"></div>
                            </div>
                        </template>
                        <div x-show="!server.recent_fcm.length" class="px-4 py-6 text-sm text-slate-500">Todavía no hay FCM nativos registrados para este usuario.</div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        function pushDiagApp() {
            return {
                message: '',
                messageType: 'info',
                sendingTest: false,
                sendingNativeFcmTest: false,
                publicKey: '',
                server: {
                    subscription: {
                        total: 0,
                        active_count: 0,
                        last_seen_at: '',
                        last_success_at: '',
                        last_error_at: '',
                        last_error: '',
                    },
                    recent_pushes: [],
                    recent_fcm: [],
                },
                clientChecks: [],
                readyForBackgroundPush: false,
                readyHint: 'Verificando...',

                async init() {
                    await this.refreshClientStatus();
                    await this.refreshServerStatus();
                },

                setMessage(text, type = 'info') {
                    this.message = String(text || '');
                    this.messageType = type;
                },

                async refreshClientStatus() {
                    const notificationSupported = 'Notification' in window;
                    const permission = notificationSupported ? Notification.permission : 'unsupported';
                    const swSupported = 'serviceWorker' in navigator;
                    const pushSupported = 'PushManager' in window;
                    const standaloneDisplay = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
                    const persistedInstalled = localStorage.getItem('smx_pwa_installed') === '1' || sessionStorage.getItem('smx_pwa_installed') === '1';
                    const androidBridge = !!(
                        window.Android &&
                        (
                            typeof window.Android.openExternalUrlResult === 'function' ||
                            typeof window.Android.openExternalResult === 'function' ||
                            typeof window.Android.openExternalUrl === 'function' ||
                            typeof window.Android.openExternal === 'function'
                        )
                    );
                    const androidAppReferrer = String(document.referrer || '').startsWith('android-app://');
                    let relatedInstalled = false;
                    try {
                        if (typeof navigator.getInstalledRelatedApps === 'function') {
                            const related = await navigator.getInstalledRelatedApps();
                            relatedInstalled = Array.isArray(related) && related.length > 0;
                        }
                    } catch (e) {}
                    const standalone = standaloneDisplay || persistedInstalled || relatedInstalled || androidBridge || androidAppReferrer;

                    if (standalone) {
                        localStorage.setItem('smx_pwa_installed', '1');
                        sessionStorage.setItem('smx_pwa_installed', '1');
                    }

                    let swReady = false;
                    let pushSubscribed = false;
                    try {
                        if (swSupported) {
                            const reg = await navigator.serviceWorker.register('/sw.js');
                            swReady = !!reg;
                            const ready = await navigator.serviceWorker.ready;
                            const sub = pushSupported ? await ready.pushManager.getSubscription() : null;
                            pushSubscribed = !!sub;
                        }
                    } catch (e) {
                        swReady = false;
                    }

                    this.clientChecks = [
                        { key: 'permission', label: 'Permiso notificaciones', value: permission, ok: permission === 'granted' },
                        { key: 'sw', label: 'Service Worker', value: swReady ? 'Activo' : 'No disponible', ok: swReady },
                        { key: 'push', label: 'Suscripción push', value: pushSubscribed ? 'Activa' : 'No activa', ok: pushSubscribed },
                        { key: 'standalone', label: 'App instalada', value: standalone ? 'Sí' : 'No', ok: standalone },
                    ];

                    this.readyForBackgroundPush = permission === 'granted' && swReady && pushSubscribed;
                    this.readyHint = this.readyForBackgroundPush
                        ? 'Este celular ya puede recibir push aunque la app esté cerrada.'
                        : 'Falta permiso o suscripción push.';
                },

                async refreshServerStatus() {
                    try {
                        const res = await fetch('/public/menu/api/push_diag.php?action=status&_ts=' + Date.now(), {
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        const data = await res.json();
                        if (!data || !data.success) {
                            throw new Error((data && data.error) || 'No se pudo leer estado del servidor');
                        }
                        this.server = {
                            subscription: data.subscription || this.server.subscription,
                            recent_pushes: Array.isArray(data.recent_pushes) ? data.recent_pushes : [],
                            recent_fcm: Array.isArray(data.recent_fcm) ? data.recent_fcm : [],
                        };
                    } catch (e) {
                        this.setMessage(e && e.message ? e.message : 'No se pudo consultar el estado del servidor', 'error');
                    }
                },

                async requestNotifications() {
                    if (!('Notification' in window)) {
                        this.setMessage('Este navegador no soporta notificaciones.', 'error');
                        return;
                    }
                    try {
                        const permission = await Notification.requestPermission();
                        await this.refreshClientStatus();
                        this.setMessage(permission === 'granted' ? 'Permiso concedido.' : 'Permiso no concedido.', permission === 'granted' ? 'info' : 'error');
                    } catch (e) {
                        this.setMessage('No se pudo pedir permiso.', 'error');
                    }
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

                async fetchPublicKey() {
                    if (this.publicKey) return this.publicKey;
                    const res = await fetch('/public/menu/api/chat.php?action=push_public_key&_ts=' + Date.now(), {
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    const data = await res.json();
                    if (!data || !data.success || !data.public_key) {
                        throw new Error((data && data.error) || 'No se pudo obtener la clave pública');
                    }
                    this.publicKey = String(data.public_key || '');
                    return this.publicKey;
                },

                async syncPush() {
                    try {
                        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                            throw new Error('Este navegador no soporta push');
                        }
                        if (!('Notification' in window) || Notification.permission !== 'granted') {
                            throw new Error('Primero concedé permiso de notificaciones');
                        }
                        const reg = await navigator.serviceWorker.register('/sw.js');
                        const ready = await navigator.serviceWorker.ready;
                        let subscription = await ready.pushManager.getSubscription();
                        const key = await this.fetchPublicKey();
                        if (!subscription) {
                            subscription = await ready.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: this.urlBase64ToUint8Array(key),
                            });
                        }
                        const saveRes = await fetch('/public/menu/api/chat.php?action=push_subscribe', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                subscription: subscription.toJSON(),
                                content_encoding: (PushManager.supportedContentEncodings && PushManager.supportedContentEncodings[0]) || 'aes128gcm',
                            }),
                        });
                        const saveData = await saveRes.json();
                        if (!saveData || !saveData.success) {
                            throw new Error((saveData && saveData.error) || 'No se pudo guardar la suscripción');
                        }
                        await this.refreshClientStatus();
                        await this.refreshServerStatus();
                        this.setMessage('Suscripción push reparada correctamente.');
                    } catch (e) {
                        this.setMessage(e && e.message ? e.message : 'No se pudo reparar la suscripción push', 'error');
                    }
                },

                async sendTest() {
                    this.sendingTest = true;
                    try {
                        const res = await fetch('/public/menu/api/push_diag.php?action=test', {
                            method: 'POST',
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        const data = await res.json();
                        if (!data || !data.success) {
                            throw new Error((data && data.error) || 'No se pudo enviar la prueba');
                        }
                        this.setMessage('Prueba enviada. Cerrá la app y verificá si llega la notificación.');
                        await this.refreshServerStatus();
                    } catch (e) {
                        this.setMessage(e && e.message ? e.message : 'No se pudo enviar la prueba', 'error');
                    } finally {
                        this.sendingTest = false;
                    }
                },

                async sendNativeFcmTest() {
                    this.sendingNativeFcmTest = true;
                    try {
                        const res = await fetch('/public/menu/api/push_diag.php?action=test_fcm_auth', {
                            method: 'POST',
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        const data = await res.json();
                        if (!data || !data.success) {
                            throw new Error((data && data.error) || 'No se pudo enviar la prueba FCM');
                        }
                        this.setMessage('Prueba FCM nativa enviada. Si esto llega con la app cerrada, es el mismo canal del POS.');
                        await this.refreshServerStatus();
                    } catch (e) {
                        this.setMessage(e && e.message ? e.message : 'No se pudo enviar la prueba FCM', 'error');
                    } finally {
                        this.sendingNativeFcmTest = false;
                    }
                },
            };
        }
    </script>
</body>
</html>
