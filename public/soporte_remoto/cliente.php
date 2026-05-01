<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$token = trim((string)($_GET['token'] ?? ''));
$idEmpresa = (int)Session::getIdEmpresa();
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Compartir Mi Pantalla</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 overflow-hidden" x-data="remoteClientApp()" x-init="init()">
<div class="w-screen h-screen p-0">
    <div x-show="error" class="absolute top-4 left-4 right-4 z-20 rounded-2xl border border-red-800 bg-red-950/85 p-4 text-red-200" x-text="error"></div>

    <div x-show="!session && !error" class="w-full h-full grid place-items-center">
        <div class="text-center text-slate-500 px-6">
            <div class="text-5xl mb-3">🖥️</div>
            <div class="text-lg font-semibold text-slate-300">Esperando solicitud para compartir pantalla</div>
        </div>
    </div>

    <div x-show="session" class="w-full h-full">
        <section class="w-full h-full bg-slate-950 flex flex-col">
            <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-800 bg-slate-950/95">
                <div class="min-w-0">
                    <div class="text-sm text-sky-100 truncate">Soporte SistemaX <?= htmlspecialchars(implode(' / ', SISTEMAX_SUPPORT_COMPANIES), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-xs text-slate-400 truncate" x-text="(session?.support_company_name || '') + ' · ' + (session?.support_name || '')"></div>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="openSystemWindow('/public/menu/menu.php')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">
                        Abrir Menú
                    </button>
                    <button @click="openSystemWindow('/public/')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">
                        Abrir Home
                    </button>
                    <span class="text-xs px-2 py-1 rounded-full" :class="peerConnected ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'" x-text="peerConnected ? 'Compartiendo' : 'Pendiente'"></span>
                    <button @click="startShare()" :disabled="sharing || peerConnected" class="px-3 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-sm font-semibold">
                        <span x-text="sharing ? 'Preparando...' : 'Compartir'"></span>
                    </button>
                    <button @click="stopShare()" :disabled="!localStream" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 disabled:opacity-50 text-sm">Detener</button>
                </div>
            </div>
            <div class="flex-1 bg-black flex items-center justify-center">
                <div class="text-center px-6">
                    <div class="text-5xl mb-3" x-text="peerConnected ? '🖥️' : '📡'"></div>
                    <div class="text-lg font-semibold text-slate-200" x-text="peerConnected ? 'Pantalla compartida con soporte' : 'Listo para compartir pantalla'"></div>
                    <div class="text-sm text-slate-500 mt-2">
                        Abrí Menú u Home en otra ventana y compartí esa ventana o la pantalla completa. No vuelvas al inicio desde esta app si querés seguir mostrando el sistema.
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
function remoteClientApp() {
    return {
        token: <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        session: null,
        error: '',
        availableSessions: [],
        localStream: null,
        sharing: false,
        peerConnected: false,
        lastEventId: 0,
        pollTimer: null,
        pc: null,
        dataChannel: null,
        logs: [],
        iceServers: [{ urls: ['stun:stun.l.google.com:19302'] }],

        async init() {
            await this.loadConfig();
            if (this.token) {
                await this.fetchSession();
                if (this.session?.id) {
                    this.startPolling();
                }
            } else {
                await this.loadAvailableSessions();
                setInterval(() => this.loadAvailableSessions(), 8000);
            }
        },

        async loadAvailableSessions() {
            const res = await fetch('api.php?action=client_list_sessions', { credentials: 'same-origin' });
            const data = await res.json();
            this.availableSessions = data?.items || [];
            if (!this.session && this.availableSessions.length === 1) {
                this.openSession(this.availableSessions[0]);
            }
        },

        openSession(item) {
            if (!item?.id) return;
            this.token = String(item.token || '');
            this.session = item;
            this.error = '';
            this.lastEventId = 0;
            this.logs = [];
            this.startPolling();
        },

        addLog(message) {
            this.logs.unshift({ id: Date.now() + Math.random(), message });
            this.logs = this.logs.slice(0, 50);
        },

        openSystemWindow(path) {
            const target = String(path || '').trim() || '/public/menu/menu.php';
            const win = window.open(target, '_blank', 'noopener,noreferrer');
            if (!win) {
                this.error = 'El navegador bloqueó la apertura. Permití popups para abrir el sistema en otra ventana.';
                return;
            }
            this.error = '';
        },

        async loadConfig() {
            const res = await fetch('api.php?action=config', { credentials: 'same-origin' });
            const data = await res.json();
            if (data?.ok && Array.isArray(data.ice_servers) && data.ice_servers.length) {
                this.iceServers = data.ice_servers;
            }
        },

        async fetchSession() {
            const params = this.token
                ? 'token=' + encodeURIComponent(this.token)
                : 'session_id=' + encodeURIComponent(this.session?.id || 0);
            const res = await fetch('api.php?action=client_get_session&' + params, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data?.ok) {
                this.error = data?.error || 'No se pudo abrir la sesión';
                return;
            }
            this.session = data.session;
            this.syncAvailableSession(data.session);
        },

        startPolling() {
            this.stopPolling();
            this.pollNow();
            this.pollTimer = setInterval(() => this.pollNow(), 1500);
        },

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        async pollNow() {
            if (!this.session?.id) return;
            try {
                const res = await fetch(`api.php?action=poll&role=client&token=${encodeURIComponent(this.token)}&session_id=${encodeURIComponent(this.session.id)}&last_id=${encodeURIComponent(this.lastEventId)}`, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data?.ok) return;
                this.session = data.session;
                this.syncAvailableSession(data.session);
                for (const event of (data.events || [])) {
                    this.lastEventId = Math.max(this.lastEventId, Number(event.id || 0));
                    await this.handleEvent(event);
                }
            } catch (e) {
                console.error(e);
            }
        },

        async ensurePeer() {
            if (this.pc) return this.pc;
            const pc = new RTCPeerConnection({ iceServers: this.iceServers });
            pc.onicecandidate = async (event) => {
                if (!event.candidate || !this.session?.id) return;
                await this.sendSignal('ice', { candidate: event.candidate });
            };
            pc.onconnectionstatechange = () => {
                const state = String(pc.connectionState || '');
                this.peerConnected = state === 'connected';
                if (state === 'connected') this.addLog('Soporte conectado a la pantalla compartida.');
                if (['failed', 'closed', 'disconnected'].includes(state)) this.addLog('Conexión WebRTC: ' + state);
            };
            this.dataChannel = pc.createDataChannel('support-chat');
            this.dataChannel.onopen = () => this.addLog('Canal de datos abierto.');
            this.dataChannel.onmessage = (event) => this.addLog('Soporte: ' + String(event.data || ''));
            this.pc = pc;
            return pc;
        },

        async startShare() {
            if (this.sharing || !this.session?.id) return;
            this.sharing = true;
            try {
                const stream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        displaySurface: 'monitor'
                    },
                    audio: false,
                    preferCurrentTab: false,
                    selfBrowserSurface: 'exclude',
                    surfaceSwitching: 'include',
                    monitorTypeSurfaces: 'include'
                });
                this.localStream = stream;
                this.notifyParentShareState(true);
                const [track] = stream.getVideoTracks();
                if (track) {
                    track.onended = () => { this.stopShare(); };
                }

                await fetch('api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'client_accept', token: this.token })
                });

                const pc = await this.ensurePeer();
                stream.getTracks().forEach(trackItem => pc.addTrack(trackItem, stream));
                const offer = await pc.createOffer({ offerToReceiveVideo: false, offerToReceiveAudio: false });
                await pc.setLocalDescription(offer);
                await this.sendSignal('offer', { sdp: pc.localDescription });
                this.addLog('Oferta enviada al soporte.');
            } catch (e) {
                this.error = e?.message || 'No se pudo compartir la pantalla';
            }
            this.sharing = false;
        },

        async handleEvent(event) {
            if (event.event_type === 'answer') {
                const pc = await this.ensurePeer();
                await pc.setRemoteDescription(new RTCSessionDescription(event.payload.sdp));
                this.addLog('Respuesta del soporte recibida.');
                return;
            }
            if (event.event_type === 'ice') {
                const pc = await this.ensurePeer();
                if (event.payload?.candidate) {
                    try {
                        await pc.addIceCandidate(new RTCIceCandidate(event.payload.candidate));
                    } catch (err) {
                        console.warn('ICE add failed', err);
                    }
                }
                return;
            }
            if (event.event_type === 'ended') {
                this.addLog('El soporte finalizó la sesión.');
                await this.stopShare(false);
                this.stopPolling();
            }
        },

        async sendSignal(type, payload) {
            if (!this.session?.id) return;
            await fetch('api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'signal',
                    role: 'client',
                    token: this.token,
                    session_id: Number(this.session.id),
                    event_type: type,
                    payload
                })
            });
        },

        async stopShare(notify = true) {
            this.notifyParentShareState(false);
            if (this.localStream) {
                this.localStream.getTracks().forEach(track => track.stop());
                this.localStream = null;
            }
            if (this.dataChannel) {
                try { this.dataChannel.close(); } catch (e) {}
                this.dataChannel = null;
            }
            if (this.pc) {
                try { this.pc.close(); } catch (e) {}
                this.pc = null;
            }
            this.peerConnected = false;
            if (notify && this.session?.id) {
                try {
                    await fetch('api.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'end', role: 'client', token: this.token, session_id: Number(this.session.id) })
                    });
                } catch (e) {
                    console.error(e);
                }
            }
            await this.loadAvailableSessions();
        },

        syncAvailableSession(session) {
            if (!session?.id) return;
            const idx = this.availableSessions.findIndex(item => Number(item.id) === Number(session.id));
            if (idx >= 0) {
                this.availableSessions[idx] = session;
            }
        },

        notifyParentShareState(active) {
            try {
                window.parent?.postMessage({ type: 'smx-screen-share-state', active: !!active }, '*');
            } catch (e) {}
        }
    }
}

window.addEventListener('beforeunload', function() {
    try {
        window.parent?.postMessage({ type: 'smx-screen-share-state', active: false }, '*');
    } catch (e) {}
});
</script>
</body>
</html>
