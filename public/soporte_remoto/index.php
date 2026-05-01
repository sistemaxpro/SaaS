<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
$allowedSupport = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Soporte Remoto</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="supportRemoteApp()" x-init="init()">
<div class="max-w-7xl mx-auto p-4 md:p-6">
    <div class="flex items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold">Soporte Remoto</h1>
            <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · #<?= (int)$idEmpresa ?> · Usuario <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Cerrar</button>
    </div>

    <?php if (!$allowedSupport): ?>
        <div class="rounded-2xl border border-red-800 bg-red-950/40 p-5 text-red-200">
            Acceso reservado a soporte técnico de empresas <?= htmlspecialchars(implode(', ', SISTEMAX_SUPPORT_COMPANIES), ENT_QUOTES, 'UTF-8') ?>.
        </div>
    <?php else: ?>
    <div class="grid grid-cols-1 xl:grid-cols-[360px_1fr] gap-4">
        <aside class="space-y-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Nueva Sesión</h2>
                    <span class="text-xs text-slate-500">WebRTC</span>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Empresa cliente</label>
                        <input x-model="companySearch" @input.debounce.250ms="searchCompanies()" type="text" placeholder="Buscar empresa..." class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm">
                        <div x-show="companyOptions.length > 0" class="mt-2 max-h-48 overflow-auto rounded-xl border border-slate-800 bg-slate-950">
                            <template x-for="item in companyOptions" :key="'co-' + item.id_empresa">
                                <button type="button" @click="pickCompany(item)" class="w-full text-left px-3 py-2 hover:bg-slate-800 text-sm border-b border-slate-800 last:border-b-0">
                                    <span x-text="item.empresa"></span>
                                </button>
                            </template>
                        </div>
                        <div x-show="selectedCompany.id_empresa" class="mt-2 rounded-xl bg-sky-500/10 border border-sky-500/20 p-3 text-sm">
                            <div class="font-semibold" x-text="selectedCompany.empresa"></div>
                            <div class="text-xs text-slate-400" x-text="(selectedCompany.empresa || 'Empresa') + ' · #' + selectedCompany.id_empresa"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Usuario cliente</label>
                        <input x-model="userSearch" @input.debounce.250ms="searchUsers()" type="text" :disabled="!selectedCompany.id_empresa" placeholder="Buscar usuario..." class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm disabled:opacity-50">
                        <div x-show="userOptions.length > 0" class="mt-2 max-h-48 overflow-auto rounded-xl border border-slate-800 bg-slate-950">
                            <button type="button" @click="clearClientUser()" class="w-full text-left px-3 py-2 hover:bg-slate-800 text-sm border-b border-slate-800 text-slate-300">Cualquier usuario de la empresa</button>
                            <template x-for="item in userOptions" :key="'us-' + item.id_login">
                                <button type="button" @click="pickUser(item)" class="w-full text-left px-3 py-2 hover:bg-slate-800 text-sm border-b border-slate-800 last:border-b-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="font-medium" x-text="item.name"></div>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg"
                                              :class="Number(item.online || 0) === 1 ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'">
                                            <svg viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="3" y="4.5" width="18" height="12" rx="2"></rect>
                                                <path d="M8 19.5h8"></path>
                                                <path d="M12 16.5v3"></path>
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-400 flex items-center gap-2">
                                        <span x-text="'@' + item.login"></span>
                                        <span :class="Number(item.online || 0) === 1 ? 'text-emerald-400' : 'text-red-400'" x-text="Number(item.online || 0) === 1 ? 'sesión abierta' : 'off'"></span>
                                    </div>
                                    <div class="text-[11px] text-slate-500" x-text="formatLastSeen(item.last_seen)"></div>
                                </button>
                            </template>
                        </div>
                        <div x-show="selectedUser.id_login" class="mt-2 rounded-xl bg-emerald-500/10 border border-emerald-500/20 p-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-semibold" x-text="selectedUser.name"></div>
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg"
                                      :class="Number(selectedUser.online || 0) === 1 ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'">
                                    <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <rect x="3" y="4.5" width="18" height="12" rx="2"></rect>
                                        <path d="M8 19.5h8"></path>
                                        <path d="M12 16.5v3"></path>
                                    </svg>
                                </span>
                            </div>
                            <div class="text-xs text-slate-400 flex items-center gap-2">
                                <span x-text="'@' + selectedUser.login"></span>
                                <span :class="Number(selectedUser.online || 0) === 1 ? 'text-emerald-400' : 'text-red-400'" x-text="Number(selectedUser.online || 0) === 1 ? 'sesión abierta' : 'off'"></span>
                            </div>
                            <div class="text-[11px] text-slate-500 mt-1" x-text="formatLastSeen(selectedUser.last_seen)"></div>
                        </div>
                        <div x-show="selectedCompany.id_empresa && !selectedUser.id_login" class="mt-2 text-xs text-slate-500">Si no elegís usuario, puede entrar cualquier usuario de la empresa cliente.</div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Título</label>
                        <input x-model="sessionTitle" type="text" placeholder="Soporte POS / Ventas / Error puntual" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-sm">
                    </div>
                    <button @click="createSession()" :disabled="creatingSession || !selectedCompany.id_empresa" class="w-full px-4 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-sm font-semibold">
                        <span x-text="creatingSession ? 'Creando...' : 'Crear Sesión'"></span>
                    </button>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Sesiones Abiertas</h2>
                    <button @click="loadSessions()" class="text-xs text-sky-400">Actualizar</button>
                </div>
                <div class="space-y-2 max-h-[60vh] overflow-auto">
                    <template x-for="item in sessions" :key="'session-' + item.id">
                        <button type="button" @click="selectSession(item.id)" class="w-full text-left rounded-xl border p-3 transition"
                                :class="activeSession && activeSession.id === item.id ? 'border-blue-500 bg-blue-500/10' : 'border-slate-800 bg-slate-950 hover:bg-slate-800'">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-medium truncate" x-text="item.title || 'Soporte remoto'"></div>
                                <span class="text-[11px] px-2 py-0.5 rounded-full"
                                      :class="item.status === 'active' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'"
                                      x-text="item.status"></span>
                            </div>
                            <div class="text-xs text-slate-400 mt-1 truncate" x-text="item.client_company_name"></div>
                            <div class="text-xs text-slate-500 truncate" x-text="item.client_scope_label"></div>
                        </button>
                    </template>
                    <div x-show="sessions.length === 0" class="text-sm text-slate-500 py-8 text-center">Sin sesiones abiertas</div>
                </div>
            </div>
        </aside>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 min-h-[70vh] flex flex-col">
            <template x-if="!activeSession">
                <div class="flex-1 grid place-items-center text-center text-slate-500">
                    <div>
                        <div class="text-5xl mb-3">🖥️</div>
                        <div class="text-lg font-semibold text-slate-300">Seleccioná o creá una sesión</div>
                        <div class="text-sm mt-1">Vas a obtener un link para que el cliente comparta su pantalla.</div>
                    </div>
                </div>
            </template>

            <template x-if="activeSession">
                <div class="flex-1 flex flex-col gap-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-bold" x-text="activeSession.title || 'Soporte remoto'"></h2>
                            <div class="text-sm text-slate-400" x-text="activeSession.client_company_name + ' · ' + activeSession.client_scope_label"></div>
                            <div class="text-xs text-slate-500 mt-1">Código <span x-text="activeSession.join_code"></span></div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button @click="copyInviteLink()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm border border-slate-700">Copiar link</button>
                            <button @click="copyAbsoluteInviteLink()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm border border-slate-700">Copiar URL completa</button>
                            <button @click="notifyClient()" :disabled="notifyingClient || !activeSession.client_login_id" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 disabled:opacity-50 text-sm"> <span x-text="notifyingClient ? 'Notificando...' : 'Notificar cliente'"></span></button>
                            <button @click="endSession()" class="px-3 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-sm">Finalizar</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-4 flex-1">
                        <div x-ref="screenPanel" class="rounded-2xl border border-slate-800 bg-slate-950 overflow-hidden flex flex-col min-h-[420px]">
                            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between text-sm">
                                <span>Pantalla del cliente</span>
                                <div class="flex items-center gap-3">
                                    <button @click="toggleScreenFullscreen()" type="button" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-[11px] text-slate-200">
                                        <span x-text="screenFullscreen ? 'Salir pantalla completa' : 'Pantalla completa'"></span>
                                    </button>
                                    <span class="text-xs" :class="peerConnected ? 'text-emerald-400' : 'text-amber-400'" x-text="peerConnected ? 'Conectado' : 'Esperando compartir pantalla'"></span>
                                </div>
                            </div>
                            <div class="flex-1 grid place-items-center bg-black">
                                <video x-ref="remoteVideo" autoplay playsinline class="max-w-full bg-black" :class="screenFullscreen ? 'w-screen h-screen object-contain' : 'max-h-[70vh]'"></video>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                                <div class="text-sm font-semibold mb-2">Invitación</div>
                                <textarea readonly class="w-full h-32 px-3 py-2 rounded-xl bg-slate-900 border border-slate-800 text-xs text-slate-300" x-text="inviteAbsoluteUrl()"></textarea>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                                <div class="text-sm font-semibold mb-2">Estado</div>
                                <div class="space-y-2 text-sm">
                                    <div class="flex items-center justify-between"><span class="text-slate-400">Cliente</span><span x-text="clientReady ? 'Listo' : 'Pendiente'"></span></div>
                                    <div class="flex items-center justify-between"><span class="text-slate-400">Soporte</span><span>En línea</span></div>
                                    <div class="flex items-center justify-between"><span class="text-slate-400">Último evento</span><span class="truncate max-w-[130px] text-right" x-text="lastEventLabel || '-' "></span></div>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                                <div class="text-sm font-semibold mb-2">Notas</div>
                                <ul class="space-y-2 text-xs text-slate-400 max-h-64 overflow-auto">
                                    <template x-for="log in logs" :key="log.id">
                                        <li x-text="log.message"></li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </section>
    </div>
    <?php endif; ?>
</div>

<script>
function supportRemoteApp() {
    return {
        companySearch: '',
        companyOptions: [],
        selectedCompany: {},
        userSearch: '',
        userOptions: [],
        selectedUser: {},
        sessionTitle: '',
        creatingSession: false,
        sessions: [],
        activeSession: null,
        logs: [],
        peerConnected: false,
        clientReady: false,
        notifyingClient: false,
        screenFullscreen: false,
        lastEventId: 0,
        lastEventLabel: '',
        pollTimer: null,
        userPresenceTimer: null,
        pc: null,
        iceServers: [{ urls: ['stun:stun.l.google.com:19302'] }],

        async init() {
            await this.loadConfig();
            await this.loadSessions();
            this.searchCompanies();
            this.startUserPresencePolling();
            window.addEventListener('pagehide', () => this.stopUserPresencePolling(), { once: true });
            document.addEventListener('fullscreenchange', () => {
                this.screenFullscreen = document.fullscreenElement === this.$refs.screenPanel;
            });
        },

        async loadConfig() {
            const res = await fetch('api.php?action=config', { credentials: 'same-origin' });
            const data = await res.json();
            if (data?.ok && Array.isArray(data.ice_servers) && data.ice_servers.length) {
                this.iceServers = data.ice_servers;
            }
        },

        async searchCompanies() {
            const res = await fetch('api.php?action=support_catalog_companies&q=' + encodeURIComponent(this.companySearch || ''), { credentials: 'same-origin' });
            const data = await res.json();
            this.companyOptions = data?.items || [];
        },

        pickCompany(item) {
            this.selectedCompany = item || {};
            this.selectedUser = {};
            this.userOptions = [];
            this.userSearch = '';
            if (this.selectedCompany.id_empresa) {
                this.searchUsers();
            }
        },

        async searchUsers() {
            if (!this.selectedCompany.id_empresa) {
                this.userOptions = [];
                this.selectedUser = {};
                return;
            }
            const res = await fetch(`api.php?action=support_catalog_users&id_empresa=${encodeURIComponent(this.selectedCompany.id_empresa)}&q=${encodeURIComponent(this.userSearch || '')}`, { credentials: 'same-origin' });
            const data = await res.json();
            this.userOptions = data?.items || [];
            if (this.selectedUser?.id_login) {
                const freshSelected = this.userOptions.find(item => Number(item.id_login) === Number(this.selectedUser.id_login));
                if (freshSelected) {
                    this.selectedUser = freshSelected;
                } else if (Number(this.selectedUser.id_empresa || this.selectedCompany.id_empresa || 0) !== Number(this.selectedCompany.id_empresa || 0)) {
                    this.selectedUser = {};
                }
            }
        },

        pickUser(item) {
            this.selectedUser = item || {};
        },

        clearClientUser() {
            this.selectedUser = {};
        },

        startUserPresencePolling() {
            this.stopUserPresencePolling();
            this.userPresenceTimer = setInterval(() => {
                if (!this.selectedCompany?.id_empresa) return;
                this.searchUsers();
            }, 8000);
        },

        stopUserPresencePolling() {
            if (this.userPresenceTimer) {
                clearInterval(this.userPresenceTimer);
                this.userPresenceTimer = null;
            }
        },

        formatLastSeen(value) {
            if (!value) return 'sin actividad reciente';
            const normalized = String(value).replace(' ', 'T');
            const date = new Date(normalized);
            if (Number.isNaN(date.getTime())) {
                return 'última actividad: ' + String(value);
            }
            const now = new Date();
            const diffMs = now.getTime() - date.getTime();
            const diffMin = Math.floor(diffMs / 60000);
            if (diffMin <= 1) return 'última actividad: hace 1 min';
            if (diffMin < 60) return 'última actividad: hace ' + diffMin + ' min';
            return 'última actividad: ' + date.toLocaleString('es-PY', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        },

        async createSession() {
            if (!this.selectedCompany.id_empresa || this.creatingSession) return;
            this.creatingSession = true;
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'support_create_session',
                        client_company_id: Number(this.selectedCompany.id_empresa || 0),
                        client_login_id: Number(this.selectedUser.id_login || 0),
                        title: (this.sessionTitle || '').trim() || 'Soporte remoto',
                    })
                });
                const data = await res.json();
                if (!data?.ok) throw new Error(data?.error || 'No se pudo crear la sesión');
                if (data.notification_sent) {
                    this.addLog('Notificación enviada al cliente seleccionado.');
                } else if (this.selectedUser.id_login) {
                    this.addLog('La sesión se creó sin notificación automática al cliente.');
                }
                await this.loadSessions();
                this.selectSession(data.session.id);
            } catch (e) {
                alert(e.message || 'No se pudo crear la sesión');
            }
            this.creatingSession = false;
        },

        async loadSessions() {
            const res = await fetch('api.php?action=support_list_sessions', { credentials: 'same-origin' });
            const data = await res.json();
            this.sessions = data?.items || [];
            if (this.activeSession) {
                const fresh = this.sessions.find(s => Number(s.id) === Number(this.activeSession.id));
                if (fresh) this.activeSession = fresh;
            }
        },

        async selectSession(sessionId) {
            await this.destroyPeer(false);
            this.logs = [];
            this.clientReady = false;
            this.peerConnected = false;
            this.lastEventId = 0;
            const res = await fetch('api.php?action=support_get_session&session_id=' + encodeURIComponent(sessionId), { credentials: 'same-origin' });
            const data = await res.json();
            if (!data?.ok) {
                alert(data?.error || 'No se pudo abrir la sesión');
                return;
            }
            this.activeSession = data.session;
            this.addLog('Sesión abierta. Esperando que el cliente comparta su pantalla.');
            this.startPolling();
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
            if (!this.activeSession?.id) return;
            try {
                const res = await fetch(`api.php?action=poll&role=support&session_id=${encodeURIComponent(this.activeSession.id)}&last_id=${encodeURIComponent(this.lastEventId)}`, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data?.ok) return;
                this.activeSession = data.session;
                for (const event of (data.events || [])) {
                    this.lastEventId = Math.max(this.lastEventId, Number(event.id || 0));
                    await this.handleEvent(event);
                }
            } catch (e) {
                console.error(e);
            }
        },

        addLog(message) {
            this.logs.unshift({ id: Date.now() + Math.random(), message });
            this.logs = this.logs.slice(0, 50);
        },

        async ensurePeer() {
            if (this.pc) return this.pc;
            const pc = new RTCPeerConnection({ iceServers: this.iceServers });
            pc.ontrack = (event) => {
                const stream = event.streams && event.streams[0] ? event.streams[0] : null;
                if (stream && this.$refs.remoteVideo) {
                    this.$refs.remoteVideo.srcObject = stream;
                }
            };
            pc.onicecandidate = async (event) => {
                if (!event.candidate || !this.activeSession?.id) return;
                await this.sendSignal('ice', { candidate: event.candidate });
            };
            pc.onconnectionstatechange = () => {
                const state = String(pc.connectionState || '');
                this.peerConnected = state === 'connected';
                this.lastEventLabel = state || this.lastEventLabel;
                if (state === 'connected') this.addLog('Pantalla del cliente conectada.');
                if (['failed', 'closed', 'disconnected'].includes(state)) this.addLog('Conexión WebRTC: ' + state);
            };
            pc.ondatachannel = (event) => {
                const ch = event.channel;
                ch.onmessage = (msg) => this.addLog('Mensaje cliente: ' + String(msg.data || ''));
            };
            this.pc = pc;
            return pc;
        },

        async handleEvent(event) {
            this.lastEventLabel = event.event_type || '';
            if (event.event_type === 'client_ready') {
                this.clientReady = true;
                this.addLog('Cliente listo para compartir pantalla.');
                return;
            }
            if (event.event_type === 'offer') {
                this.clientReady = true;
                const pc = await this.ensurePeer();
                await pc.setRemoteDescription(new RTCSessionDescription(event.payload.sdp));
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await this.sendSignal('answer', { sdp: pc.localDescription });
                this.addLog('Oferta recibida y respondida.');
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
                this.addLog('La sesión fue finalizada.');
                await this.destroyPeer(false);
                this.stopPolling();
            }
        },

        async sendSignal(type, payload) {
            if (!this.activeSession?.id) return;
            await fetch('api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'signal', role: 'support', session_id: Number(this.activeSession.id), event_type: type, payload })
            });
        },

        inviteAbsoluteUrl() {
            if (!this.activeSession?.invite_url) return '';
            return new URL(this.activeSession.invite_url, window.location.origin).toString();
        },

        async copyInviteLink() {
            if (!this.activeSession?.invite_url) return;
            await navigator.clipboard.writeText(this.activeSession.invite_url);
            this.addLog('Link relativo copiado al portapapeles.');
        },

        async copyAbsoluteInviteLink() {
            const url = this.inviteAbsoluteUrl();
            if (!url) return;
            await navigator.clipboard.writeText(url);
            this.addLog('URL completa copiada al portapapeles.');
        },

        async notifyClient() {
            if (!this.activeSession?.id || !this.activeSession?.client_login_id || this.notifyingClient) return;
            this.notifyingClient = true;
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'support_notify_client', session_id: Number(this.activeSession.id) })
                });
                const data = await res.json();
                if (!data?.ok) throw new Error(data?.error || 'No se pudo notificar');
                this.addLog(data.message || 'Notificación enviada al cliente.');
            } catch (e) {
                alert(e.message || 'No se pudo enviar la notificación');
            }
            this.notifyingClient = false;
        },

        async endSession() {
            if (!this.activeSession?.id) return;
            try {
                await fetch('api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'end', role: 'support', session_id: Number(this.activeSession.id) })
                });
            } catch (e) {
                console.error(e);
            }
            await this.destroyPeer(false);
            this.stopPolling();
            await this.loadSessions();
            this.activeSession = null;
        },

        async toggleScreenFullscreen() {
            const panel = this.$refs.screenPanel;
            if (!panel) return;
            try {
                if (document.fullscreenElement === panel) {
                    await document.exitFullscreen();
                    this.screenFullscreen = false;
                    return;
                }
                await panel.requestFullscreen();
                this.screenFullscreen = true;
            } catch (e) {
                console.error(e);
            }
        },

        async destroyPeer(stopVideo = true) {
            if (this.pc) {
                try { this.pc.close(); } catch (e) {}
                this.pc = null;
            }
            this.peerConnected = false;
            if (stopVideo && this.$refs.remoteVideo) {
                this.$refs.remoteVideo.srcObject = null;
            }
        }
    }
}
</script>
</body>
</html>
