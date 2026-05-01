<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

function assistAdminAbsoluteUrl(string $url): string
{
    if ($url === '' || preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $url;
}

$assistApiAbsoluteUrl = assistAdminAbsoluteUrl('/public/api/assist.php');

$idEmpresa = (int)Session::getIdEmpresa();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
$allowed = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assist Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="assistAdmin()" x-init="init()">
<div class="max-w-7xl mx-auto p-4 md:p-6">
    <div class="flex items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold">Assist Console</h1>
            <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · #<?= (int)$idEmpresa ?> · Usuario <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-xs text-slate-500 mt-1">Host actual: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost', ENT_QUOTES, 'UTF-8') ?> · Assist API URL: <?= htmlspecialchars($assistApiAbsoluteUrl, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Cerrar</button>
    </div>

    <?php if (!$allowed): ?>
        <div class="rounded-2xl border border-red-800 bg-red-950/40 p-5 text-red-200">Acceso reservado a soporte técnico.</div>
    <?php else: ?>
    <div class="space-y-4">
        <div x-show="toast.text" x-transition class="rounded-2xl border px-4 py-3 text-sm"
             :class="toast.tone === 'error' ? 'border-rose-700/40 bg-rose-900/20 text-rose-200' : 'border-emerald-700/40 bg-emerald-900/20 text-emerald-200'">
            <span x-text="toast.text"></span>
        </div>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <button @click="reloadAll()" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold">Actualizar todo</button>
                <button @click="sessionStatus='open'; loadSessions()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Sesiones abiertas</button>
                <button @click="copyDeviceIds()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Copiar IDs de equipos</button>
                <button @click="advancedAgentTools = !advancedAgentTools" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" x-text="advancedAgentTools ? 'Ocultar opciones avanzadas' : 'Opciones avanzadas'"></button>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                    <div class="text-xs text-slate-500 uppercase tracking-[0.25em]">Devices</div>
                    <div class="mt-2 text-2xl font-bold text-slate-100" x-text="devices.length"></div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                    <div class="text-xs text-slate-500 uppercase tracking-[0.25em]">Online</div>
                    <div class="mt-2 text-2xl font-bold text-emerald-300" x-text="devices.filter(d => d.status === 'online').length"></div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                    <div class="text-xs text-slate-500 uppercase tracking-[0.25em]">Sessions</div>
                    <div class="mt-2 text-2xl font-bold text-sky-300" x-text="sessions.length"></div>
                </div>
            </div>
        </section>

        <section x-show="advancedAgentTools" x-transition class="rounded-2xl border border-emerald-800/60 bg-emerald-950/20 p-4 space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-emerald-100">Alta de agente</h2>
                    <p class="text-sm text-emerald-200/80">Generá una credencial temporal para registrar un equipo nuevo en la consola Assist.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button @click="probeLocalAgent()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Detectar agente local</button>
                    <button @click="registerLocalAgent()" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold" :disabled="!bootstrap.token || localAgent.busy" :class="(!bootstrap.token || localAgent.busy) ? 'opacity-50 cursor-not-allowed' : ''">Registrar en este equipo</button>
                    <button @click="issueBootstrapToken()" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold">Nueva credencial</button>
                    <button @click="copyBootstrapToken()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!bootstrap.token" :class="!bootstrap.token ? 'opacity-50 cursor-not-allowed' : ''">Copiar credencial</button>
                    <button @click="copyBootstrapCommand()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!bootstrap.token" :class="!bootstrap.token ? 'opacity-50 cursor-not-allowed' : ''">Copiar comando</button>
                </div>
            </div>
            <div class="grid gap-3 lg:grid-cols-[1.2fr,0.8fr]">
                <div class="rounded-2xl border border-emerald-800/40 bg-slate-950 p-4">
                    <div class="text-xs uppercase tracking-[0.25em] text-emerald-300/70">Credencial temporal</div>
                    <div class="mt-2 break-all rounded-xl border border-slate-800 bg-slate-900 px-3 py-3 font-mono text-sm text-slate-100" x-text="bootstrap.token || 'Todavía no emitido'"></div>
                    <div class="mt-2 text-xs text-slate-400" x-text="bootstrap.expiresAt ? ('Vence: ' + bootstrap.expiresAt) : 'Validez: 10 minutos desde la emisión'"></div>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 space-y-2">
                    <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Registro rápido</div>
                    <label class="block text-xs text-slate-400">Nombre visible del equipo</label>
                    <input type="text" x-model.trim="bootstrap.deviceName" class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="Caja Mostrador">
                    <label class="block text-xs text-slate-400">Assist API URL</label>
                    <input type="text" x-model.trim="bootstrap.apiUrl" class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="<?= htmlspecialchars($assistApiAbsoluteUrl, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="grid gap-3 lg:grid-cols-[0.8fr,1.2fr]">
                <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 space-y-2">
                    <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Equipo local</div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[11px] px-2 py-1 rounded-full"
                              :class="localAgent.reachable ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-800 text-slate-300'"
                              x-text="localAgent.reachable ? 'detectado' : 'sin conexión'"></span>
                        <span class="text-[11px] px-2 py-1 rounded-full"
                              :class="localAgent.registered ? 'bg-sky-500/20 text-sky-300' : 'bg-slate-800 text-slate-300'"
                              x-text="localAgent.registered ? 'registrado' : 'sin registrar'"></span>
                    </div>
                    <div class="text-sm text-slate-300" x-text="localAgent.deviceName || 'Sin datos del agente local'"></div>
                    <div class="text-xs text-slate-500" x-text="localAgent.summary || 'Probá localhost:17890 para detectar el servicio.'"></div>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                    <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Registro por navegador</div>
                    <p class="mt-2 text-sm text-slate-300">Si el navegador puede alcanzar `http://127.0.0.1:17890`, este botón registra y sincroniza el agente sin usar terminal.</p>
                    <div class="mt-3 text-xs text-slate-500">Requiere que `sistemax-agent` esté corriendo en el equipo actual.</div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Comando local de alta</div>
                <pre class="mt-2 whitespace-pre-wrap break-all text-xs text-slate-300" x-text="bootstrapCommand()"></pre>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Dispositivos</h2>
                    <p class="text-sm text-slate-400">Inventario devuelto por `admin_devices`.</p>
                </div>
                <input type="text" x-model.trim="deviceSearch" @input.debounce.300ms="loadDevices()" class="w-full max-w-sm rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Empresa, usuario, equipo o host">
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-800">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-950 text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-left">ID</th>
                            <th class="px-4 py-3 text-left">Empresa</th>
                            <th class="px-4 py-3 text-left">Usuario</th>
                            <th class="px-4 py-3 text-left">Equipo</th>
                            <th class="px-4 py-3 text-left">Estado</th>
                            <th class="px-4 py-3 text-left">Última vista</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 bg-slate-900">
                        <template x-for="device in devices" :key="'device-' + device.id">
                            <tr>
                                <td class="px-4 py-3 text-slate-300" x-text="device.id"></td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-100" x-text="device.company_name || ('Empresa #' + device.id_empresa)"></div>
                                    <div class="text-xs text-slate-500" x-text="'#' + (device.id_empresa || 0)"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-100" x-text="device.user_name || '-'"></div>
                                    <div class="text-xs text-slate-500" x-text="'@' + (device.login_name || '-')"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-100" x-text="device.device_name || '-'"></div>
                                    <div class="text-xs text-slate-500" x-text="device.host_name || device.platform || '-'"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-4 w-5 items-center justify-center rounded border"
                                              :class="device.status === 'online' ? 'border-emerald-400 bg-emerald-500/20' : 'border-slate-500 bg-slate-300/10'">
                                            <span class="h-1.5 w-3 rounded-sm"
                                                  :class="device.status === 'online' ? 'bg-emerald-400' : 'bg-slate-300'"></span>
                                        </span>
                                        <span class="text-[11px]"
                                              :class="device.status === 'online' ? 'text-emerald-300' : 'text-slate-300'"
                                              x-text="device.status === 'online' ? 'online' : 'offline'"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-400" x-text="device.last_seen_at || '-'"></td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button @click="createDeviceSession(device.id)" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold">Crear sesión</button>
                                        <button @click="loadDeviceFrame(device.id)" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs">Ver frame</button>
                                        <button @click="openDeviceStream(device.id)" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-xs font-semibold">Abrir stream</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="devices.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center text-slate-500">Sin dispositivos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Frame de dispositivo</h2>
                    <p class="text-sm text-slate-400">Snapshot más reciente subido por la tablet o equipo.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="openDeviceStream(selectedDeviceId)" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold" :disabled="!selectedDeviceId">Abrir stream</button>
                    <button @click="toggleFrameAutorefresh()" class="px-3 py-2 rounded-xl text-sm"
                            :class="frameAutorefresh ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-200'"
                            :disabled="!selectedDeviceId">
                        <span x-text="frameAutorefresh ? 'Auto-refresh ON' : 'Auto-refresh OFF'"></span>
                    </button>
                    <button @click="refreshSelectedFrame()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!selectedDeviceId">Actualizar frame</button>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 min-h-[240px]">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div class="text-xs text-slate-500" x-text="selectedDeviceId ? ('Device #' + selectedDeviceId) : 'Sin device seleccionado'"></div>
                    <div class="text-xs text-slate-500" x-text="frameAutorefresh ? ('Refresco cada ' + frameRefreshMs / 1000 + 's') : 'Refresco manual'"></div>
                </div>
                <template x-if="selectedFrame && selectedFrame.image_url">
                    <div class="space-y-3">
                        <img :src="frameImageSrc()" alt="Último frame" class="w-full max-h-[480px] object-contain rounded-xl border border-slate-800 bg-black">
                        <div class="text-xs text-slate-400" x-text="'Capturado: ' + (selectedFrame.created_at || '-') + ' · ' + (selectedFrame.width || 0) + 'x' + (selectedFrame.height || 0)"></div>
                    </div>
                </template>
                <div x-show="selectedDeviceId && !selectedFrame" class="text-sm text-slate-500">Ese device todavía no subió frames.</div>
                <div x-show="!selectedDeviceId" class="text-sm text-slate-500">Elegí `Ver frame` en un dispositivo para cargar la vista.</div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 text-sm text-slate-300">
                Flujo simplificado activo: soporte trabaja directo sobre equipos registrados. El cliente solo instala el agente y queda visible en esta consola.
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Sesiones</h2>
                    <p class="text-sm text-slate-400">Listado de `admin_sessions` y cierre manual.</p>
                </div>
                <div class="flex gap-2">
                    <button @click="sessionStatus='open'; loadSessions()" class="px-3 py-2 rounded-xl text-sm" :class="sessionStatus==='open' ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-300'">Abiertas</button>
                    <button @click="sessionStatus='all'; loadSessions()" class="px-3 py-2 rounded-xl text-sm" :class="sessionStatus==='all' ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-300'">Todas</button>
                </div>
            </div>
            <div class="grid gap-3">
                <template x-for="session in sessions" :key="'session-' + session.id">
                    <article class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[11px] px-2 py-1 rounded-full bg-slate-800 text-slate-200" x-text="'#' + session.id"></span>
                                    <span class="text-[11px] px-2 py-1 rounded-full"
                                          :class="session.status === 'active' ? 'bg-emerald-500/20 text-emerald-300' : (session.status === 'waiting_consent' ? 'bg-amber-500/20 text-amber-300' : 'bg-slate-800 text-slate-300')"
                                          x-text="session.status"></span>
                                </div>
                                <div class="mt-2 font-semibold text-slate-100" x-text="session.company_name || ('Empresa #' + session.id_empresa)"></div>
                                <div class="text-sm text-slate-400" x-text="(session.user_name || '-') + ' · ' + (session.device_name || session.host_name || '-')"></div>
                            </div>
                            <div class="text-right text-xs text-slate-500">
                                <div x-text="'Modo: ' + (session.mode || '-')"></div>
                                <div x-text="'Support: ' + (session.support_login_name || '-')"></div>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button @click="loadSessionEvents(session.id)" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Ver eventos</button>
                            <button @click="endSession(session.id)" class="px-3 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-sm font-semibold">Cerrar sesión</button>
                        </div>
                    </article>
                </template>
                <div x-show="sessions.length === 0" class="rounded-2xl border border-slate-800 bg-slate-950 p-8 text-center text-slate-500">
                    Sin sesiones.
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Eventos de sesión</h2>
                    <p class="text-sm text-slate-400">Timeline de auditoría para la última sesión consultada.</p>
                </div>
                <div class="text-xs text-slate-500" x-text="selectedSessionId ? ('Sesión #' + selectedSessionId) : 'Sin sesión seleccionada'"></div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 max-h-80 overflow-auto">
                <template x-for="event in sessionEvents" :key="'ev-' + event.id">
                    <div class="border-b border-slate-800 py-2 last:border-b-0">
                        <div class="text-sm text-slate-200" x-text="event.event_type"></div>
                        <div class="text-xs text-slate-500" x-text="(event.created_at || '-') + ' · ' + (event.actor_type || '-')"></div>
                        <pre class="mt-1 text-[11px] text-slate-400 whitespace-pre-wrap break-all" x-text="event.payload || ''"></pre>
                    </div>
                </template>
                <div x-show="sessionEvents.length === 0" class="text-center text-slate-500 py-6">Sin eventos cargados.</div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Control asistido</h2>
                    <p class="text-sm text-slate-400">Comandos básicos por accesibilidad para Android: navegación, tap y swipe.</p>
                </div>
                <div class="text-xs text-slate-500" x-text="controlSummary()"></div>
            </div>
            <div class="grid gap-3 lg:grid-cols-[0.85fr,1.15fr]">
                <div class="space-y-3">
                    <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Acciones rápidas</div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button @click="quickControl('back')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!canSendControl()">Back</button>
                            <button @click="quickControl('home')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!canSendControl()">Home</button>
                            <button @click="quickControl('recents')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!canSendControl()">Recents</button>
                            <button @click="quickControl('notifications')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!canSendControl()">Notificaciones</button>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 space-y-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Tap puntual</div>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" x-model="control.x" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="X">
                            <input type="number" x-model="control.y" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="Y">
                        </div>
                        <input type="number" x-model="control.durationMs" class="w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="Duración ms">
                        <div class="flex gap-2">
                            <button @click="sendTapControl()" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold" :disabled="!canSendControl()">Enviar tap</button>
                            <button @click="toggleTapMode()" class="px-3 py-2 rounded-xl text-sm" :class="control.tapArmed ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-200'" :disabled="!canSendControl()">
                                <span x-text="control.tapArmed ? 'Tap sobre video ON' : 'Tap sobre video OFF'"></span>
                            </button>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 space-y-3">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Swipe</div>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" x-model="control.startX" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="Start X">
                            <input type="number" x-model="control.startY" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="Start Y">
                            <input type="number" x-model="control.endX" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="End X">
                            <input type="number" x-model="control.endY" class="rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="End Y">
                        </div>
                        <button @click="sendSwipeControl()" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold" :disabled="!canSendControl()">Enviar swipe</button>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                    <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Capacidades reportadas</div>
                    <div class="mt-3 grid gap-2 md:grid-cols-2 text-sm">
                        <div class="rounded-xl border border-slate-800 bg-slate-900 px-3 py-2">Pantalla: <span class="text-slate-200" x-text="Number(selectedSessionDevice()?.permissions_screen || 0) ? 'ok' : 'pendiente'"></span></div>
                        <div class="rounded-xl border border-slate-800 bg-slate-900 px-3 py-2">Accesibilidad: <span class="text-slate-200" x-text="Number(selectedSessionDevice()?.permissions_accessibility || 0) ? 'ok' : 'pendiente'"></span></div>
                        <div class="rounded-xl border border-slate-800 bg-slate-900 px-3 py-2">Control: <span class="text-slate-200" x-text="Number(selectedSessionDevice()?.can_input_control || 0) ? 'soportado' : 'sin soporte'"></span></div>
                        <div class="rounded-xl border border-slate-800 bg-slate-900 px-3 py-2">Resolución: <span class="text-slate-200" x-text="(selectedDisplayWidth() || '?') + 'x' + (selectedDisplayHeight() || '?')"></span></div>
                    </div>
                    <pre class="mt-3 whitespace-pre-wrap break-all text-[11px] text-slate-300 max-h-[220px] overflow-auto" x-text="JSON.stringify(selectedSessionCapabilities(), null, 2) || '{}'"></pre>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Señalización WebRTC</h2>
                    <p class="text-sm text-slate-400">Canal manual para `offer / answer / ice-candidate` de la consola Assist.</p>
                </div>
                <button @click="pullSignals()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm" :disabled="!selectedSessionId">Leer señales</button>
            </div>
            <div class="grid gap-3 lg:grid-cols-[0.8fr,1.2fr]">
                <div class="space-y-3">
                    <div class="text-xs text-slate-500" x-text="selectedSessionId ? ('Sesión #' + selectedSessionId) : 'Elegí una sesión para señalizar'"></div>
                    <select x-model="signalForm.type" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm">
                        <option value="offer">offer</option>
                        <option value="answer">answer</option>
                        <option value="ice-candidate">ice-candidate</option>
                        <option value="renegotiate">renegotiate</option>
                    </select>
                    <textarea x-model="signalForm.payloadText" class="w-full min-h-[180px] rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs" placeholder='{"sdp":"..."}'></textarea>
                    <button @click="pushSignal()" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold" :disabled="!selectedSessionId">Enviar señal</button>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4 max-h-[320px] overflow-auto space-y-3">
                    <template x-for="signal in signals" :key="'signal-' + signal.id">
                        <div class="border-b border-slate-800 pb-3 last:border-b-0">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] px-2 py-1 rounded-full bg-slate-800 text-slate-200" x-text="'#' + signal.id"></span>
                                <span class="text-[11px] px-2 py-1 rounded-full bg-sky-500/20 text-sky-300" x-text="signal.signal_type"></span>
                                <span class="text-[11px] px-2 py-1 rounded-full bg-slate-800 text-slate-300" x-text="signal.sender_type"></span>
                            </div>
                            <div class="mt-2 text-xs text-slate-500" x-text="signal.created_at || '-'"></div>
                            <pre class="mt-2 whitespace-pre-wrap break-all text-[11px] text-slate-300" x-text="JSON.stringify(signal.payload || {}, null, 2)"></pre>
                        </div>
                    </template>
                    <div x-show="signals.length === 0" class="text-sm text-slate-500">Sin señales pendientes recibidas.</div>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Peer WebRTC Web</h2>
                    <p class="text-sm text-slate-400">Prepara la oferta desde el navegador del soporte y consume `answer/candidates` automáticamente.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="startWebRtcOffer()" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold" :disabled="!selectedSessionId || webrtc.busy">Iniciar offer</button>
                    <button @click="stopWebRtcPeer()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Cerrar peer</button>
                </div>
            </div>
            <div class="grid gap-3 lg:grid-cols-[1.1fr,0.9fr]">
                <div class="rounded-2xl border border-slate-800 bg-black min-h-[280px] flex items-center justify-center overflow-hidden">
                    <video id="assistRemoteVideo" @click="handleRemoteVideoClick($event)" class="w-full max-h-[60vh] object-contain" autoplay playsinline controls></video>
                </div>
                <div class="space-y-3">
                    <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Estado</div>
                        <div class="mt-2 text-sm text-slate-200" x-text="webrtc.status"></div>
                        <div class="mt-2 text-xs text-slate-500" x-text="webrtc.connectionState || 'sin peer'"></div>
                        <div class="mt-2 text-xs text-slate-500" x-text="'Última answer: ' + (webrtc.answerAt || '-')"></div>
                        <div class="mt-1 text-xs text-slate-500" x-text="'Último polling: ' + (webrtc.lastSignalAt || '-') + ' · #' + webrtc.pollCount"></div>
                    </div>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                        <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Oferta local</div>
                        <pre class="mt-2 whitespace-pre-wrap break-all text-[11px] text-slate-300 max-h-[180px] overflow-auto" x-text="webrtc.lastOfferSdp || 'Todavía no generada'"></pre>
                    </div>
                    <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs uppercase tracking-[0.25em] text-slate-500">Trazas WebRTC</div>
                            <button @click="webrtc.traces=[]" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-[11px] text-slate-300">Limpiar</button>
                        </div>
                        <div class="mt-3 max-h-[220px] overflow-auto space-y-2">
                            <template x-for="(trace, index) in webrtc.traces" :key="'trace-' + index + '-' + trace.at">
                                <div class="rounded-xl border px-3 py-2 text-xs"
                                     :class="trace.tone === 'error' ? 'border-rose-800/40 bg-rose-950/30 text-rose-200' : (trace.tone === 'ok' ? 'border-emerald-800/40 bg-emerald-950/30 text-emerald-200' : 'border-slate-800 bg-slate-900 text-slate-300')">
                                    <div class="font-mono text-[11px]" x-text="trace.at"></div>
                                    <div class="mt-1" x-text="trace.text"></div>
                                </div>
                            </template>
                            <div x-show="webrtc.traces.length === 0" class="text-xs text-slate-500">Todavía no hay trazas de negociación.</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
    <?php endif; ?>
</div>
<script>
function assistAdmin() {
    return {
        toast: { text: '', tone: 'ok' },
        devices: [],
        requests: [],
        sessions: [],
        sessionEvents: [],
        signals: [],
        selectedSessionId: null,
        selectedDeviceId: null,
        selectedSessionDeviceId: null,
        selectedFrame: null,
        selectedFrameNonce: Date.now(),
        frameAutorefresh: false,
        frameRefreshMs: 2500,
        frameTimer: null,
        signalPollTimer: null,
        signalForm: {
            type: 'offer',
            payloadText: '{\n  "sdp": ""\n}'
        },
        control: {
            tapArmed: false,
            x: '',
            y: '',
            startX: '',
            startY: '',
            endX: '',
            endY: '',
            durationMs: 260
        },
        advancedAgentTools: false,
        webrtc: {
            pc: null,
            busy: false,
            status: 'Sin iniciar',
            connectionState: '',
            lastOfferSdp: '',
            traces: [],
            answerAt: '',
            lastSignalAt: '',
            pollCount: 0
        },
        deviceSearch: '',
        requestStatus: 'open',
        sessionStatus: 'open',
        bootstrap: {
            token: '',
            expiresAt: '',
            deviceName: 'Caja Mostrador',
            apiUrl: <?= json_encode($assistApiAbsoluteUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        },
        localAgent: {
            reachable: false,
            registered: false,
            busy: false,
            deviceName: '',
            summary: ''
        },

        async init() {
            await this.probeLocalAgent(true);
            await this.reloadAll();
        },

        notify(text, tone = 'ok') {
            this.toast = { text, tone };
            setTimeout(() => {
                if (this.toast.text === text) this.toast.text = '';
            }, 3000);
        },

        traceWebRtc(message, tone = 'info') {
            const stamp = new Date().toLocaleTimeString('es-AR', { hour12: false });
            this.webrtc.traces.unshift({
                at: stamp,
                tone,
                text: message
            });
            this.webrtc.traces = this.webrtc.traces.slice(0, 40);
        },

        frameImageSrc() {
            if (!this.selectedFrame?.image_url) return '';
            return this.selectedFrame.image_url + '?_ts=' + this.selectedFrameNonce;
        },

        deviceCapabilities(device) {
            if (!device || !device.capabilities_json) return {};
            if (typeof device.capabilities_json === 'object') return device.capabilities_json;
            try {
                return JSON.parse(device.capabilities_json);
            } catch (_) {
                return {};
            }
        },

        selectedSession() {
            return this.sessions.find((session) => Number(session.id || 0) === Number(this.selectedSessionId || 0)) || null;
        },

        selectedSessionDevice() {
            const session = this.selectedSession();
            const deviceId = Number(session?.target_device_id || this.selectedSessionDeviceId || 0);
            if (!deviceId) return null;
            return this.devices.find((device) => Number(device.id || 0) === deviceId) || null;
        },

        selectedSessionCapabilities() {
            return this.deviceCapabilities(this.selectedSessionDevice());
        },

        selectedDisplayWidth() {
            return Number(this.selectedSessionCapabilities().display_width_px || 0);
        },

        selectedDisplayHeight() {
            return Number(this.selectedSessionCapabilities().display_height_px || 0);
        },

        canSendControl() {
            return !!this.selectedSessionId;
        },

        controlSummary() {
            const device = this.selectedSessionDevice();
            if (!device) return 'Elegí una sesión para habilitar controles.';
            const caps = this.selectedSessionCapabilities();
            if (!Number(device.can_input_control || 0)) return 'El agente todavía no informó soporte de control.';
            if (!Number(device.permissions_accessibility || 0)) return 'El equipo soporta control, pero Accesibilidad todavía no está habilitada.';
            return 'Control asistido listo. Resolución reportada: ' + (caps.display_width_px || '?') + 'x' + (caps.display_height_px || '?') + '.';
        },

        bootstrapCommand() {
            if (!this.bootstrap.token) {
                return 'Emití un token y luego usá este comando en el equipo destino.';
            }
            const payload = {
                bootstrap_token: this.bootstrap.token,
                assist_api_url: this.bootstrap.apiUrl || <?= json_encode($assistApiAbsoluteUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                device_name: this.bootstrap.deviceName || 'Caja Mostrador',
                status: 'online'
            };
            const body = JSON.stringify(payload).replace(/'/g, "\\'");
            return "curl -X POST http://127.0.0.1:17890/assist/sync -H 'Content-Type: application/json' -d '" + body + "'";
        },

        async fetchJson(url, options = {}) {
            const res = await fetch(url, { credentials: 'same-origin', ...options });
            const data = await res.json();
            if (!data?.ok) throw new Error(data?.error || 'Error de API');
            return Object.prototype.hasOwnProperty.call(data, 'data') ? data.data : [];
        },

        async fetchLocalAgent(path, options = {}) {
            const res = await fetch('http://127.0.0.1:17890' + path, options);
            const data = await res.json();
            if (!data?.ok) throw new Error(data?.error || 'Error del agente local');
            return data.data || data;
        },

        async reloadAll() {
            await Promise.all([this.loadDevices(), this.loadSessions()]);
        },

        async loadDevices() {
            const q = encodeURIComponent(this.deviceSearch || '');
            this.devices = await this.fetchJson('/public/api/assist.php?action=admin_devices&q=' + q);
        },

        async loadSessions() {
            this.sessions = await this.fetchJson('/public/api/assist.php?action=admin_sessions&status=' + encodeURIComponent(this.sessionStatus));
        },

        async loadSessionEvents(sessionId) {
            this.selectedSessionId = Number(sessionId || 0);
            const current = this.sessions.find((session) => Number(session.id || 0) === Number(sessionId || 0));
            this.selectedSessionDeviceId = Number(current?.target_device_id || 0);
            this.sessionEvents = await this.fetchJson('/public/api/assist.php?action=admin_session_events&session_id=' + encodeURIComponent(String(sessionId)));
            this.signals = [];
            this.stopSignalPolling();
            this.stopWebRtcPeer();
            this.traceWebRtc('Sesión seleccionada #' + this.selectedSessionId);
        },

        async loadDeviceFrame(deviceId) {
            this.selectedDeviceId = Number(deviceId || 0);
            const data = await this.fetchJson('/public/api/assist.php?action=admin_device_frame&device_id=' + encodeURIComponent(String(deviceId)));
            this.selectedFrame = data || null;
            this.selectedFrameNonce = Date.now();
        },

        async refreshSelectedFrame() {
            if (!this.selectedDeviceId) return;
            try {
                await this.loadDeviceFrame(this.selectedDeviceId);
            } catch (e) {
                this.notify(e.message || 'No se pudo actualizar el frame', 'error');
            }
        },

        toggleFrameAutorefresh() {
            if (!this.selectedDeviceId) return;
            this.frameAutorefresh = !this.frameAutorefresh;
            if (this.frameAutorefresh) {
                this.startFrameTimer();
            } else {
                this.stopFrameTimer();
            }
        },

        startFrameTimer() {
            this.stopFrameTimer();
            this.frameTimer = setInterval(() => {
                if (!this.selectedDeviceId) return;
                this.refreshSelectedFrame();
            }, this.frameRefreshMs);
        },

        stopFrameTimer() {
            if (this.frameTimer) {
                clearInterval(this.frameTimer);
                this.frameTimer = null;
            }
        },

        openDeviceStream(deviceId) {
            const id = Number(deviceId || 0);
            if (!id) return;
            window.open('/public/soporte/assist_stream.php?device_id=' + encodeURIComponent(String(id)), '_blank', 'noopener,noreferrer');
        },

        async pullSignals() {
            if (!this.selectedSessionId) return;
            try {
                const incoming = await this.fetchJson('/public/api/assist.php?action=admin_pull_signals&session_id=' + encodeURIComponent(String(this.selectedSessionId)));
                this.webrtc.pollCount += 1;
                this.webrtc.lastSignalAt = new Date().toLocaleTimeString('es-AR', { hour12: false });
                this.signals = incoming;
                this.traceWebRtc('Polling #' + this.webrtc.pollCount + ' recibió ' + incoming.length + ' señal(es)');
                await this.consumeSignals(incoming);
            } catch (e) {
                this.traceWebRtc('Error al leer señales: ' + (e.message || 'desconocido'), 'error');
                this.notify(e.message || 'No se pudieron leer señales', 'error');
            }
        },

        async pushSignal() {
            if (!this.selectedSessionId) return;
            let payload = {};
            try {
                payload = this.signalForm.payloadText.trim() ? JSON.parse(this.signalForm.payloadText) : {};
            } catch (e) {
                this.notify('JSON inválido en payload', 'error');
                return;
            }
            try {
                await this.postAction({
                    action: 'admin_push_signal',
                    session_id: Number(this.selectedSessionId),
                    signal_type: this.signalForm.type,
                    payload
                });
                this.traceWebRtc('Señal manual enviada: ' + this.signalForm.type);
                this.notify('Señal enviada');
            } catch (e) {
                this.traceWebRtc('Error al enviar señal manual: ' + (e.message || 'desconocido'), 'error');
                this.notify(e.message || 'No se pudo enviar la señal', 'error');
            }
        },

        async sendControlCommand(payload) {
            if (!this.selectedSessionId) {
                this.notify('Elegí una sesión primero', 'error');
                return;
            }
            try {
                await this.postAction({
                    action: 'admin_push_signal',
                    session_id: Number(this.selectedSessionId),
                    signal_type: 'control-command',
                    payload
                });
                this.traceWebRtc('Control enviado: ' + (payload.action || 'command'));
                this.notify('Control enviado');
            } catch (e) {
                this.notify(e.message || 'No se pudo enviar el control', 'error');
            }
        },

        async quickControl(action) {
            await this.sendControlCommand({ action });
        },

        async sendTapControl() {
            const x = Number(this.control.x || 0);
            const y = Number(this.control.y || 0);
            if (!x || !y) {
                this.notify('Indicá X e Y', 'error');
                return;
            }
            await this.sendControlCommand({
                action: 'tap',
                x,
                y,
                duration_ms: Number(this.control.durationMs || 80)
            });
        },

        async sendSwipeControl() {
            const startX = Number(this.control.startX || 0);
            const startY = Number(this.control.startY || 0);
            const endX = Number(this.control.endX || 0);
            const endY = Number(this.control.endY || 0);
            if (!startX || !startY || !endX || !endY) {
                this.notify('Completá coordenadas de swipe', 'error');
                return;
            }
            await this.sendControlCommand({
                action: 'swipe',
                start_x: startX,
                start_y: startY,
                end_x: endX,
                end_y: endY,
                duration_ms: Number(this.control.durationMs || 260)
            });
        },

        toggleTapMode() {
            this.control.tapArmed = !this.control.tapArmed;
        },

        async handleRemoteVideoClick(event) {
            if (!this.control.tapArmed) return;
            const video = event.currentTarget;
            if (!video || !video.videoWidth || !video.videoHeight) {
                this.notify('El stream todavía no informó tamaño de video', 'error');
                return;
            }
            const displayWidth = this.selectedDisplayWidth();
            const displayHeight = this.selectedDisplayHeight();
            if (!displayWidth || !displayHeight) {
                this.notify('El equipo todavía no informó resolución de pantalla', 'error');
                return;
            }
            const rect = video.getBoundingClientRect();
            const scale = Math.min(rect.width / video.videoWidth, rect.height / video.videoHeight);
            const drawnWidth = video.videoWidth * scale;
            const drawnHeight = video.videoHeight * scale;
            const offsetX = (rect.width - drawnWidth) / 2;
            const offsetY = (rect.height - drawnHeight) / 2;
            const localX = event.clientX - rect.left - offsetX;
            const localY = event.clientY - rect.top - offsetY;
            if (localX < 0 || localY < 0 || localX > drawnWidth || localY > drawnHeight) {
                return;
            }
            const x = Math.round((localX / drawnWidth) * displayWidth);
            const y = Math.round((localY / drawnHeight) * displayHeight);
            this.control.x = String(x);
            this.control.y = String(y);
            await this.sendControlCommand({
                action: 'tap',
                x,
                y,
                duration_ms: Number(this.control.durationMs || 80)
            });
        },

        async startWebRtcOffer() {
            if (!this.selectedSessionId || this.webrtc.busy) return;
            this.webrtc.busy = true;
            try {
                this.stopWebRtcPeer();
                this.traceWebRtc('Iniciando peer local para sesión #' + this.selectedSessionId);
                const pc = new RTCPeerConnection({
                    iceServers: [
                        { urls: ['stun:stun.l.google.com:19302'] }
                    ]
                });
                this.webrtc.pc = pc;
                this.webrtc.status = 'Creando offer';
                pc.addTransceiver('video', { direction: 'recvonly' });
                pc.addTransceiver('audio', { direction: 'recvonly' });

                pc.ontrack = (event) => {
                    const video = document.getElementById('assistRemoteVideo');
                    if (video && event.streams && event.streams[0]) {
                        video.srcObject = event.streams[0];
                    }
                    this.webrtc.status = 'Track remoto recibido';
                    this.traceWebRtc('Track remoto recibido desde Android', 'ok');
                };

                pc.onconnectionstatechange = () => {
                    this.webrtc.connectionState = pc.connectionState || '';
                    this.webrtc.status = 'Peer state: ' + (pc.connectionState || 'unknown');
                    this.traceWebRtc('Peer state -> ' + (pc.connectionState || 'unknown'), pc.connectionState === 'connected' ? 'ok' : 'info');
                };

                pc.onicecandidate = async (event) => {
                    if (!event.candidate) return;
                    this.traceWebRtc('ICE local generado');
                    await this.postAction({
                        action: 'admin_push_signal',
                        session_id: Number(this.selectedSessionId),
                        signal_type: 'ice-candidate',
                        payload: event.candidate.toJSON ? event.candidate.toJSON() : {
                            candidate: event.candidate.candidate,
                            sdpMid: event.candidate.sdpMid,
                            sdpMLineIndex: event.candidate.sdpMLineIndex
                        }
                    });
                };

                const offer = await pc.createOffer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: true
                });
                await pc.setLocalDescription(offer);
                this.traceWebRtc('Offer local creada');
                this.webrtc.lastOfferSdp = offer.sdp || '';
                this.signalForm.type = 'offer';
                this.signalForm.payloadText = JSON.stringify({
                    type: offer.type,
                    sdp: offer.sdp || ''
                }, null, 2);
                await this.postAction({
                    action: 'admin_push_signal',
                    session_id: Number(this.selectedSessionId),
                    signal_type: 'offer',
                    payload: {
                        type: offer.type,
                        sdp: offer.sdp || ''
                    }
                });
                this.webrtc.status = 'Offer enviada, esperando answer';
                this.traceWebRtc('Offer enviada al backend Assist', 'ok');
                this.startSignalPolling();
            } catch (e) {
                this.webrtc.status = e.message || 'No se pudo iniciar WebRTC';
                this.traceWebRtc('Error al iniciar WebRTC: ' + this.webrtc.status, 'error');
                this.notify(this.webrtc.status, 'error');
            } finally {
                this.webrtc.busy = false;
            }
        },

        stopWebRtcPeer() {
            const video = document.getElementById('assistRemoteVideo');
            if (video) {
                video.srcObject = null;
            }
            if (this.webrtc.pc) {
                try { this.webrtc.pc.close(); } catch (_) {}
            }
            this.webrtc.pc = null;
            this.webrtc.connectionState = '';
            this.webrtc.lastOfferSdp = '';
            this.webrtc.answerAt = '';
            this.webrtc.lastSignalAt = '';
            this.webrtc.pollCount = 0;
            this.webrtc.status = 'Sin iniciar';
        },

        startSignalPolling() {
            this.stopSignalPolling();
            this.traceWebRtc('Polling automático de señales iniciado');
            this.signalPollTimer = setInterval(() => {
                if (!this.selectedSessionId) return;
                this.pullSignals();
            }, 1500);
        },

        stopSignalPolling() {
            if (this.signalPollTimer) {
                clearInterval(this.signalPollTimer);
                this.signalPollTimer = null;
                this.traceWebRtc('Polling automático detenido');
            }
        },

        async consumeSignals(incoming) {
            if (!this.webrtc.pc || !Array.isArray(incoming) || incoming.length === 0) return;
            for (const signal of incoming) {
                const type = String(signal.signal_type || '').trim();
                const payload = signal.payload || {};
                if (type === 'answer' && payload.sdp) {
                    await this.webrtc.pc.setRemoteDescription({
                        type: payload.type || 'answer',
                        sdp: payload.sdp
                    });
                    this.webrtc.status = 'Answer aplicada';
                    this.webrtc.answerAt = new Date().toLocaleTimeString('es-AR', { hour12: false });
                    this.traceWebRtc('Answer remota aplicada', 'ok');
                    continue;
                }
                if (type === 'ice-candidate' && payload.candidate) {
                    try {
                        await this.webrtc.pc.addIceCandidate(payload);
                        this.traceWebRtc('ICE remoto aplicado');
                    } catch (_) {
                        this.traceWebRtc('Falló ICE remoto recibido', 'error');
                    }
                }
            }
        },

        async postAction(payload) {
            const res = await fetch('/public/api/assist.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data?.ok) throw new Error(data?.error || 'Error al ejecutar acción');
            return data.data || {};
        },

        async issueBootstrapToken() {
            try {
                const data = await this.postAction({ action: 'client_issue_agent_bootstrap' });
                this.bootstrap.token = data.bootstrap_token || '';
                this.bootstrap.expiresAt = data.expires_at || '';
                this.notify('Credencial de agente emitida');
            } catch (e) {
                this.notify(e.message || 'No se pudo emitir el token', 'error');
            }
        },

        copyBootstrapToken() {
            if (!this.bootstrap.token) return;
            navigator.clipboard.writeText(this.bootstrap.token).then(() => {
                this.notify('Credencial copiada');
            }).catch(() => {
                this.notify('No se pudo copiar el token', 'error');
            });
        },

        copyBootstrapCommand() {
            if (!this.bootstrap.token) return;
            navigator.clipboard.writeText(this.bootstrapCommand()).then(() => {
                this.notify('Comando copiado');
            }).catch(() => {
                this.notify('No se pudo copiar el comando', 'error');
            });
        },

        async probeLocalAgent(silent = false) {
            try {
                const data = await this.fetchLocalAgent('/assist/status');
                const state = data.state || {};
                this.localAgent.reachable = true;
                this.localAgent.registered = !!data.registered;
                this.localAgent.deviceName = state.device_name || state.host_name || '';
                this.localAgent.summary = [state.platform, state.architecture, state.agent_version].filter(Boolean).join(' · ') || 'Agente local detectado';
                if (!this.bootstrap.deviceName && state.device_name) {
                    this.bootstrap.deviceName = state.device_name;
                }
                if (!silent) this.notify('Agente local detectado');
            } catch (e) {
                this.localAgent.reachable = false;
                this.localAgent.registered = false;
                this.localAgent.deviceName = '';
                this.localAgent.summary = 'No se pudo conectar a http://127.0.0.1:17890';
                if (!silent) this.notify(e.message || 'No se pudo detectar el agente local', 'error');
            }
        },

        async registerLocalAgent() {
            if (!this.bootstrap.token || this.localAgent.busy) return;
            this.localAgent.busy = true;
            try {
                const data = await this.fetchLocalAgent('/assist/sync', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        bootstrap_token: this.bootstrap.token,
                        assist_api_url: this.bootstrap.apiUrl || <?= json_encode($assistApiAbsoluteUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                        device_name: this.bootstrap.deviceName || 'Caja Mostrador',
                        status: 'online'
                    })
                });
                this.localAgent.reachable = true;
                this.localAgent.registered = true;
                this.localAgent.deviceName = data.device_name || data.host_name || this.bootstrap.deviceName;
                this.localAgent.summary = 'Device #' + (data.device_id || '?') + ' · token activo hasta ' + (data.token_expires_at || '-');
                this.notify('Agente registrado y sincronizado');
                await this.loadDevices();
            } catch (e) {
                this.notify(e.message || 'No se pudo registrar el agente local', 'error');
            } finally {
                this.localAgent.busy = false;
            }
        },

        async createDeviceSession(deviceId) {
            const mode = window.prompt('Modo de sesión:', 'assisted') || 'assisted';
            const safeMode = String(mode || 'assisted').trim() || 'assisted';
            const deviceIdNumber = Number(deviceId || 0);
            if (!deviceIdNumber) return;
            try {
                await this.postAction({
                    action: 'admin_create_session',
                    target_device_id: deviceIdNumber,
                    mode: safeMode
                });
                this.notify('Sesión creada para device #' + deviceIdNumber);
                await this.loadSessions();
            } catch (e) {
                this.notify(e.message || 'No se pudo crear la sesión', 'error');
            }
        },

        async promptCreateSession(item) {
            const deviceId = window.prompt('ID del dispositivo destino para la sesión:', item.source_device_id || item.id || '');
            if (!deviceId) return;
            try {
                await this.postAction({
                    action: 'admin_create_session',
                    target_device_id: Number(deviceId),
                    mode: 'assisted'
                });
                this.notify('Sesión creada');
                await this.loadSessions();
            } catch (e) {
                this.notify(e.message || 'No se pudo crear la sesión', 'error');
            }
        },

        async endSession(sessionId) {
            try {
                await this.postAction({ action: 'admin_end_session', session_id: Number(sessionId), close_reason: 'manual_close' });
                this.notify('Sesión cerrada');
                await this.loadSessions();
                if (Number(this.selectedSessionId || 0) === Number(sessionId)) {
                    await this.loadSessionEvents(sessionId);
                }
            } catch (e) {
                this.notify(e.message || 'No se pudo cerrar la sesión', 'error');
            }
        },

        copyDeviceIds() {
            const lines = this.devices.map((d) => `${d.id} | ${d.company_name || ('Empresa #' + d.id_empresa)} | ${d.user_name || '-'} | ${d.device_name || '-'} | ${d.status || '-'}`);
            navigator.clipboard.writeText(lines.join('\n')).then(() => {
                this.notify('IDs de equipos copiados');
            }).catch(() => {
                this.notify('No se pudo copiar', 'error');
            });
        }
    };
}
</script>
</body>
</html>
