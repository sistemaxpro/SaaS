<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

function supportAdminNormalizeSettings(array $settings): array
{
    $defaults = [
        'support_remote_vendor' => 'SistemaX Assist',
        'support_remote_server_url' => '/public/soporte/index.php',
        'support_remote_access_url' => '/public/soporte/assist_admin.php',
    ];
    $vendor = trim((string)($settings['support_remote_vendor'] ?? ''));
    if ($vendor === '' || preg_match('/dwservice/i', $vendor)) {
        $settings['support_remote_vendor'] = $defaults['support_remote_vendor'];
    }
    foreach (['support_remote_server_url', 'support_remote_access_url'] as $key) {
        $value = trim((string)($settings[$key] ?? ''));
        if ($value === '' || preg_match('/dwservice/i', $value)) {
            $settings[$key] = $defaults[$key];
        }
    }
    if (preg_match('/dwservice/i', trim((string)($settings['support_remote_host_label'] ?? '')))) {
        $settings['support_remote_host_label'] = '';
    }
    return $settings;
}

$idEmpresa = (int)Session::getIdEmpresa();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
$allowed = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true);
$supportDesktopSettings = [];
try {
    $pdoSupportCfg = Database::getMasterConnection();
    $stmtSupportCfg = $pdoSupportCfg->query("SELECT setting_key, setting_value FROM " . MASTER_DB . ".smx_support_desktop_settings");
    foreach (($stmtSupportCfg->fetchAll(PDO::FETCH_ASSOC) ?: []) as $rowCfg) {
        $supportDesktopSettings[(string)$rowCfg['setting_key']] = (string)($rowCfg['setting_value'] ?? '');
    }
} catch (Throwable $e) {
    $supportDesktopSettings = [];
}
$supportDesktopSettings = supportAdminNormalizeSettings($supportDesktopSettings);
$supportHostLabel = trim((string)($supportDesktopSettings['support_remote_host_label'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_HOST_LABEL'));
$supportServerUrl = trim((string)($supportDesktopSettings['support_remote_server_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_SERVER_URL'));
$supportVendor = trim((string)($supportDesktopSettings['support_remote_vendor'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_VENDOR')) ?: 'SistemaX Assist';
$supportAccessUrl = trim((string)($supportDesktopSettings['support_remote_access_url'] ?? '')) ?: trim((string)getenv('SISTEMAX_SUPPORT_REMOTE_ACCESS_URL'));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mesa <?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="supportDesktopAdmin()" x-init="init()">
<div class="max-w-7xl mx-auto p-4 md:p-6">
    <div class="flex items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold">Mesa <?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · #<?= (int)$idEmpresa ?> · Usuario <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Cerrar</button>
    </div>

    <?php if (!$allowed): ?>
        <div class="rounded-2xl border border-red-800 bg-red-950/40 p-5 text-red-200">Acceso reservado a soporte técnico de empresas <?= htmlspecialchars(implode(', ', SISTEMAX_SUPPORT_COMPANIES), ENT_QUOTES, 'UTF-8') ?>.</div>
    <?php else: ?>
    <div class="space-y-4">
        <?php if ($supportHostLabel !== '' || $supportServerUrl !== '' || $supportAccessUrl !== ''): ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div>
                <h2 class="text-lg font-semibold">Host Self-Hosted</h2>
                <p class="text-sm text-slate-400">Configuración central usada por cliente y mesa de soporte.</p>
            </div>
            <?php
            $supportCopyBundle = trim(
                "Host: " . ($supportHostLabel !== '' ? $supportHostLabel : '-') . "\n" .
                "Servidor: " . ($supportServerUrl !== '' ? $supportServerUrl : '-') . "\n" .
                "Consola: " . ($supportAccessUrl !== '' ? $supportAccessUrl : '-')
            );
            ?>
            <div class="rounded-xl border border-slate-800 bg-slate-950 p-4 text-sm text-slate-300">
                <div class="font-semibold text-slate-100">Checklist de conexión</div>
                <div class="mt-2">1. Confirmá host y consola de acceso.</div>
                <div>2. Buscá al cliente en `Equipos Registrados` o `Solicitudes`.</div>
                <div>3. Pulsá `Conectar ahora` o `Abrir <?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?>` y completá el acceso si hace falta.</div>
                <button @click="copyToClipboard(<?= json_encode($supportCopyBundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'Datos completos copiados')" class="mt-3 px-3 py-2 rounded-xl bg-sky-700 hover:bg-sky-600 text-xs font-semibold">Copiar datos completos</button>
            </div>
            <div class="grid gap-3 md:grid-cols-3 text-sm">
                <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                    <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Host</div>
                    <div class="text-slate-200 break-all"><?= htmlspecialchars($supportHostLabel ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($supportHostLabel !== ''): ?>
                    <button @click="copyToClipboard(<?= json_encode($supportHostLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'Host copiado')" class="mt-3 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs">Copiar host</button>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950 p-3 md:col-span-2">
                    <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Servidor</div>
                    <div class="text-slate-200 break-all"><?= htmlspecialchars($supportServerUrl ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($supportServerUrl !== ''): ?>
                    <button @click="copyToClipboard(<?= json_encode($supportServerUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'Servidor copiado')" class="mt-3 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs">Copiar servidor</button>
                    <?php endif; ?>
                </div>
                <?php if ($supportAccessUrl !== ''): ?>
                <div class="rounded-xl border border-slate-800 bg-slate-950 p-3 md:col-span-3">
                    <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Consola</div>
                    <div class="text-slate-200 break-all"><?= htmlspecialchars($supportAccessUrl, ENT_QUOTES, 'UTF-8') ?></div>
                    <button @click="copyToClipboard(<?= json_encode($supportAccessUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'Consola copiada')" class="mt-3 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs">Copiar consola</button>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Usuarios Cliente</h2>
                    <p class="text-sm text-slate-400">Lista operativa desde `sec_users` con estado del último equipo registrado en SistemaX Assist.</p>
                </div>
                <button @click="loadSupportUsers()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Actualizar usuarios</button>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="block md:col-span-2">
                    <span class="text-xs text-slate-400">Buscar usuario / empresa / ID</span>
                    <input type="text" x-model.trim="userSearch" class="mt-1 w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Empresa, usuario, login, host o ID remoto">
                </label>
                <div class="flex items-end">
                    <button @click="userSearch = ''" class="w-full px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Limpiar</button>
                </div>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-800">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-950 text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-left">Empresa</th>
                            <th class="px-4 py-3 text-left">Usuario</th>
                            <th class="px-4 py-3 text-left">Estado</th>
                            <th class="px-4 py-3 text-left">Equipo</th>
                            <th class="px-4 py-3 text-left">Alias / Device ID</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800 bg-slate-900">
                        <template x-for="user in filteredSupportUsers()" :key="'user-' + user.id_empresa + '-' + user.id_login">
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-100" x-text="user.company_name || ('Empresa #' + user.id_empresa)"></div>
                                    <div class="text-xs text-slate-500" x-text="'#' + user.id_empresa"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-100" x-text="user.user_name || '-'"></div>
                                    <div class="text-xs text-slate-500" x-text="'@' + (user.login_name || '-')"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-[11px] px-2 py-1 rounded-full"
                                          :class="user.agent_online ? 'bg-emerald-500/20 text-emerald-300' : (user.endpoint_id ? 'bg-amber-500/20 text-amber-300' : 'bg-slate-800 text-slate-300')"
                                          x-text="user.status_label"></span>
                                    <div class="text-xs text-slate-500 mt-1" x-text="user.last_seen ? ('Última vista: ' + user.last_seen) : 'Sin registro'"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-200" x-text="user.host_name || '-'"></div>
                                    <div class="text-xs text-slate-500" x-text="user.platform || '-'"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-200" x-text="user.remote_id || '-'"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button @click="connectNow(user)"
                                                :disabled="!user.remote_id"
                                                class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 disabled:opacity-40 disabled:cursor-not-allowed text-xs font-semibold">
                                            Abrir Assist
                                        </button>
                                        <button @click="openRemoteConsole(user)"
                                                class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs">
                                            Abrir consola nativa
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filteredSupportUsers().length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-slate-500">No hay usuarios con ese filtro.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Equipos Registrados</h2>
                    <p class="text-sm text-slate-400">PCs que ya instalaron el agente y registraron su acceso nativo de <?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?>.</p>
                </div>
                <button @click="loadEndpoints()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Actualizar equipos</button>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="block">
                    <span class="text-xs text-slate-400">Filtrar empresa</span>
                    <input type="text" x-model.trim="endpointCompanyFilter" class="mt-1 w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Nombre o #empresa">
                </label>
                <label class="block">
                    <span class="text-xs text-slate-400">Filtrar usuario</span>
                    <input type="text" x-model.trim="endpointUserFilter" class="mt-1 w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Nombre o @login">
                </label>
                <div class="flex items-end">
                    <button @click="clearEndpointFilters()" class="w-full px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Limpiar filtros</button>
                </div>
            </div>
            <div class="grid gap-3">
                <template x-for="endpoint in filteredEndpoints()" :key="'endpoint-' + endpoint.id">
                    <article class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[11px] px-2 py-1 rounded-full" :class="endpoint.agent_online ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'" x-text="endpoint.agent_online ? 'Online' : 'Offline'"></span>
                                    <span class="text-[11px] px-2 py-1 rounded-full" :class="endpoint.remote_installed || endpoint.rustdesk_installed ? 'bg-sky-500/20 text-sky-300' : 'bg-slate-800 text-slate-300'" x-text="endpoint.remote_installed || endpoint.rustdesk_installed ? '<?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?> listo' : '<?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?> pendiente'"></span>
                                    <span class="text-[11px] px-2 py-1 rounded-full" :class="endpoint.permissions_screen && endpoint.permissions_accessibility ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'" x-text="endpoint.permissions_screen && endpoint.permissions_accessibility ? 'Permisos completos' : 'Faltan permisos'"></span>
                                </div>
                                <div class="mt-2 text-lg font-semibold" x-text="endpoint.company_name || ('Empresa #' + endpoint.id_empresa)"></div>
                                <div class="text-sm text-slate-400" x-text="(endpoint.user_name || endpoint.login_name || 'Usuario') + ' · @' + (endpoint.login_name || '')"></div>
                                <div class="text-xs text-slate-500 mt-1" x-text="'Equipo: ' + (endpoint.host_name || '-') + ' · ' + (endpoint.platform || '-')"></div>
                            </div>
                            <div class="text-right text-xs text-slate-500">
                                <div x-text="'Última prueba: ' + (endpoint.install_verified_at || '-')"></div>
                                <div x-text="'Última vista: ' + (endpoint.last_seen || '-')"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 mt-4 text-sm">
                            <div class="rounded-xl border border-slate-800 bg-slate-900 p-3">
                                <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2"><?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-slate-200" x-text="endpoint.remote_id || endpoint.rustdesk_id || '-'"></div>
                                <div class="text-xs text-slate-500 mt-1" x-text="'Versión: ' + (endpoint.remote_version || endpoint.rustdesk_version || '-')"></div>
                            </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-900 p-3">
                                <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Clave</div>
                                <div class="text-slate-200" x-text="endpoint.remote_password || endpoint.rustdesk_password || '-'"></div>
                                <div class="text-xs text-slate-500 mt-1 break-all" x-text="endpoint.remote_host || endpoint.rustdesk_host || 'Host por defecto'"></div>
                            </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-900 p-3">
                                <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Agent</div>
                                <div class="text-slate-200" x-text="endpoint.agent_version || '-'"></div>
                                <div class="text-xs text-slate-500 mt-1" x-text="endpoint.remote_running || endpoint.rustdesk_running ? '<?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?> en ejecución' : '<?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?> sin verificación local'"></div>
                            </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-900 p-3">
                                <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Acciones</div>
                                <div class="flex flex-wrap gap-2">
                                    <button @click="connectNow(endpoint)" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-xs font-semibold">Abrir Assist</button>
                                    <button @click="copyToClipboard(endpoint.remote_id || endpoint.rustdesk_id, 'Alias copiado')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs">Copiar alias</button>
                                    <button @click="openRemoteConsole(endpoint)" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold">Abrir consola nativa</button>
                                </div>
                            </div>
                        </div>
                    </article>
                </template>
                <div x-show="filteredEndpoints().length === 0" class="rounded-2xl border border-slate-800 bg-slate-950 p-8 text-center text-slate-500">
                    No hay equipos registrados todavía.
                </div>
            </div>
        </section>

        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4 flex flex-wrap items-center gap-2">
            <button @click="setStatus('open')" :class="status==='open' ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-2 rounded-xl text-sm">Abiertas</button>
            <button @click="setStatus('all')" :class="status==='all' ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-2 rounded-xl text-sm">Todas</button>
            <button @click="setStatus('resolved')" :class="status==='resolved' ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-2 rounded-xl text-sm">Resueltas</button>
            <button @click="setStatus('rejected')" :class="status==='rejected' ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-2 rounded-xl text-sm">Rechazadas</button>
            <button @click="loadItems()" class="ml-auto px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Actualizar</button>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4 grid gap-3 md:grid-cols-3">
            <label class="block">
                <span class="text-xs text-slate-400">Filtrar empresa</span>
                <input type="text" x-model.trim="requestCompanyFilter" class="mt-1 w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Nombre o #empresa">
            </label>
            <label class="block">
                <span class="text-xs text-slate-400">Filtrar usuario</span>
                <input type="text" x-model.trim="requestUserFilter" class="mt-1 w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm" placeholder="Nombre o @login">
            </label>
            <div class="flex items-end">
                <button @click="clearRequestFilters()" class="w-full px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Limpiar filtros</button>
            </div>
        </div>

        <div class="grid gap-4">
            <template x-for="item in filteredItems()" :key="'req-' + item.id">
                <article class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[11px] px-2 py-1 rounded-full bg-sky-900/40 text-sky-200" x-text="'#' + item.id"></span>
                                <span class="text-[11px] px-2 py-1 rounded-full"
                                      :class="badgeClass(item.status)"
                                      x-text="item.status"></span>
                                <span class="text-[11px] px-2 py-1 rounded-full bg-slate-800 text-slate-300" x-text="item.platform || 'unknown'"></span>
                            </div>
                            <h2 class="text-lg font-semibold mt-2" x-text="item.empresa || ('Empresa #' + item.id_empresa)"></h2>
                            <p class="text-sm text-slate-400" x-text="(item.user_name || item.login_name || 'Usuario') + ' · @' + (item.login_name || '')"></p>
                        </div>
                        <div class="text-right text-xs text-slate-500">
                            <div x-text="item.created_at"></div>
                            <div x-show="item.app_version" x-text="'Versión: ' + item.app_version"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mt-4 text-sm">
                        <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                            <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Solicitud</div>
                            <div class="text-slate-200 whitespace-pre-wrap" x-text="item.notes || 'Sin notas'"></div>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                            <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Tomado por</div>
                            <div class="text-slate-200" x-text="item.taken_by_name || '-'"></div>
                            <div class="text-xs text-slate-500 mt-1" x-text="item.taken_at || '-'"></div>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950 p-3">
                            <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Resuelto por</div>
                            <div class="text-slate-200" x-text="item.resolved_by_name || '-'"></div>
                            <div class="text-xs text-slate-500 mt-1" x-text="item.resolved_at || '-'"></div>
                            <div class="text-xs text-slate-400 mt-2 whitespace-pre-wrap" x-text="item.resolution_notes || ''"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 mt-4 text-sm">
                        <div class="rounded-xl border border-slate-800 bg-slate-950 p-3 lg:col-span-2">
                            <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-3">Acceso <?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <label class="block">
                                    <span class="text-xs text-slate-400">Alias interno / device ID</span>
                                    <input type="text" x-model="item.remote_id" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="Ej: caja-central-mqz">
                                </label>
                                <label class="block">
                                    <span class="text-xs text-slate-400">Nota operativa</span>
                                    <input type="text" x-model="item.remote_password" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="Opcional">
                                </label>
                                <label class="block md:col-span-2">
                                    <span class="text-xs text-slate-400">Ruta interna opcional</span>
                                    <input type="text" x-model="item.remote_host" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-sm" placeholder="/public/soporte/assist_admin.php">
                                </label>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button @click="saveRemoteAccess(item)" class="px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-sm font-semibold">Guardar acceso</button>
                                <button @click="connectNow(item)" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold">Abrir Assist</button>
                                <button @click="copyToClipboard(item.remote_id, 'Alias copiado')" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Copiar alias</button>
                                <button @click="openRemoteConsole(item)" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold">Abrir consola nativa</button>
                            </div>
                            <div class="mt-2 text-xs text-slate-500">Abrir la consola te lleva a la mesa nativa de SistemaX Assist.</div>
                        </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-950 p-3 lg:col-span-2">
                                <div class="text-xs text-slate-500 uppercase tracking-[0.25em] mb-2">Resumen de conexión</div>
                                <div class="space-y-2 text-sm">
                                    <div><span class="text-slate-500">Herramienta:</span> <span class="text-slate-200" x-text="item.remote_tool || '<?= htmlspecialchars($supportVendor, ENT_QUOTES, 'UTF-8') ?>'"></span></div>
                                    <div><span class="text-slate-500">ID guardado:</span> <span class="text-slate-200" x-text="item.remote_id || '-'"></span></div>
                                    <div><span class="text-slate-500">Clave guardada:</span> <span class="text-slate-200" x-text="item.remote_password || '-'"></span></div>
                                    <div><span class="text-slate-500">Host:</span> <span class="text-slate-200 break-all" x-text="item.remote_host || '-'"></span></div>
                                    <div class="pt-2 border-t border-slate-800"><span class="text-slate-500">Acción:</span> <span class="text-slate-200">Abrí la consola nativa, buscá el dispositivo del cliente y creá la sesión desde Assist Console.</span></div>
                                </div>
                            </div>
                    </div>

                    <div class="flex flex-wrap gap-2 mt-4" x-show="item.status === 'pending' || item.status === 'taken'">
                        <button @click="take(item)" class="px-3 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-sm font-semibold">Tomar</button>
                        <button @click="resolve(item, 'resolved')" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold">Resolver</button>
                        <button @click="resolve(item, 'rejected')" class="px-3 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-sm font-semibold">Rechazar</button>
                    </div>
                </article>
            </template>

            <div x-show="filteredItems().length === 0" class="rounded-2xl border border-slate-800 bg-slate-900 p-10 text-center text-slate-500">
                Sin solicitudes en este filtro.
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
function supportDesktopAdmin() {
    return {
        supportVendor: <?= json_encode($supportVendor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        supportAccessUrl: <?= json_encode($supportAccessUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        status: 'open',
        items: [],
        endpoints: [],
        supportUsers: [],
        userSearch: '',
        endpointCompanyFilter: '',
        endpointUserFilter: '',
        requestCompanyFilter: '',
        requestUserFilter: '',

        async init() {
            await this.loadSupportUsers();
            await this.loadEndpoints();
            await this.loadItems();
        },

        setStatus(next) {
            this.status = next;
            this.loadItems();
        },

        normalizedText(value) {
            return String(value || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim();
        },

        filteredEndpoints() {
            const companyNeedle = this.normalizedText(this.endpointCompanyFilter);
            const userNeedle = this.normalizedText(this.endpointUserFilter);
            return (this.endpoints || []).filter((endpoint) => {
                const companyText = this.normalizedText((endpoint.company_name || '') + ' #' + (endpoint.id_empresa || ''));
                const userText = this.normalizedText((endpoint.user_name || '') + ' @' + (endpoint.login_name || ''));
                const companyOk = !companyNeedle || companyText.includes(companyNeedle);
                const userOk = !userNeedle || userText.includes(userNeedle);
                return companyOk && userOk;
            });
        },

        filteredSupportUsers() {
            const needle = this.normalizedText(this.userSearch);
            return (this.supportUsers || []).filter((user) => {
                if (!needle) return true;
                const haystack = this.normalizedText([
                    user.company_name,
                    user.id_empresa,
                    user.user_name,
                    user.login_name,
                    user.host_name,
                    user.remote_id,
                    user.status_label
                ].join(' '));
                return haystack.includes(needle);
            });
        },

        filteredItems() {
            const companyNeedle = this.normalizedText(this.requestCompanyFilter);
            const userNeedle = this.normalizedText(this.requestUserFilter);
            return (this.items || []).filter((item) => {
                const companyText = this.normalizedText((item.empresa || '') + ' #' + (item.id_empresa || ''));
                const userText = this.normalizedText((item.user_name || '') + ' @' + (item.login_name || ''));
                const companyOk = !companyNeedle || companyText.includes(companyNeedle);
                const userOk = !userNeedle || userText.includes(userNeedle);
                return companyOk && userOk;
            });
        },

        clearEndpointFilters() {
            this.endpointCompanyFilter = '';
            this.endpointUserFilter = '';
        },

        clearRequestFilters() {
            this.requestCompanyFilter = '';
            this.requestUserFilter = '';
        },

        badgeClass(status) {
            if (status === 'pending') return 'bg-amber-500/20 text-amber-300';
            if (status === 'taken') return 'bg-sky-500/20 text-sky-300';
            if (status === 'resolved') return 'bg-emerald-500/20 text-emerald-300';
            if (status === 'rejected') return 'bg-rose-500/20 text-rose-300';
            return 'bg-slate-800 text-slate-300';
        },

        async loadItems() {
            const res = await fetch('/public/soporte/api.php?action=admin_list&status=' + encodeURIComponent(this.status), { credentials: 'same-origin' });
            const data = await res.json();
            this.items = data?.items || [];
        },

        async loadSupportUsers() {
            const res = await fetch('/public/soporte/api.php?action=admin_users_list', { credentials: 'same-origin' });
            const data = await res.json();
            this.supportUsers = data?.items || [];
        },

        async loadEndpoints() {
            const res = await fetch('/public/soporte/api.php?action=admin_endpoints', { credentials: 'same-origin' });
            const data = await res.json();
            this.endpoints = data?.items || [];
        },

        async take(item) {
            const res = await fetch('/public/soporte/api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'admin_take', id: Number(item.id) })
            });
            const data = await res.json();
            if (!data?.ok) {
                alert(data?.error || 'No se pudo tomar la solicitud');
                return;
            }
            await this.loadItems();
        },

        async saveRemoteAccess(item) {
            const res = await fetch('/public/soporte/api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'admin_save_remote_access',
                    id: Number(item.id),
                    remote_tool: this.supportVendor,
                    remote_id: item.remote_id || '',
                    remote_password: item.remote_password || '',
                    remote_host: item.remote_host || ''
                })
            });
            const data = await res.json();
            if (!data?.ok) {
                alert(data?.error || 'No se pudo guardar el acceso remoto');
                return;
            }
            await this.loadEndpoints();
            await this.loadItems();
        },

        async copyToClipboard(value, okText) {
            const text = String(value || '').trim();
            if (!text) {
                alert('No hay dato para copiar');
                return;
            }
            try {
                await navigator.clipboard.writeText(text);
                alert(okText || 'Copiado');
            } catch (_) {
                alert('No se pudo copiar automáticamente');
            }
        },

        buildAgentOpenUrl(payload) {
            const url = new URL('http://127.0.0.1:17890/apps/open');
            Object.entries(payload || {}).forEach(([key, value]) => {
                if (value !== null && value !== undefined && String(value).trim() !== '') {
                    url.searchParams.set(key, String(value));
                }
            });
            url.searchParams.set('_ts', String(Date.now()));
            return url.toString();
        },

        async openAgentApp(payload) {
            const popup = window.open('about:blank', '_blank', 'popup,width=160,height=120');
            try {
                const res = await fetch('http://127.0.0.1:17890/apps/open', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload || {})
                });
                const data = await res.json();
                if (popup && !popup.closed) popup.close();
                return data;
            } catch (err) {
                if (popup && !popup.closed) {
                    popup.location.href = this.buildAgentOpenUrl(payload);
                } else {
                    window.open(this.buildAgentOpenUrl(payload), '_blank', 'noopener,noreferrer');
                }
                return { ok: true, fallback: true };
            }
        },

        async openRemoteConsole(item) {
            const target = String(item.remote_host || this.supportAccessUrl || '/public/soporte/assist_admin.php').trim();
            if (!target) {
                alert('Falta configurar la consola nativa de ' + this.supportVendor + '.');
                return;
            }
            window.open(target, '_blank', 'noopener,noreferrer');
            return;
        },

        async connectNow(item) {
            const remoteId = String(item.remote_id || item.rustdesk_id || '').trim();
            const remoteHost = String(item.remote_host || item.rustdesk_host || '').trim();
            if (!remoteId) {
                alert('Este cliente todavía no tiene alias o device ID cargado.');
                return;
            }
            if (item.id && String(item.status || '').toLowerCase() === 'pending') {
                try {
                    await this.take(item);
                } catch (_) {
                }
            }
            try {
                await navigator.clipboard.writeText(remoteId);
            } catch (_) {
            }
            await this.openRemoteConsole({
                remote_id: remoteId,
                remote_host: remoteHost
            });
        },

        async resolve(item, status) {
            const notes = window.prompt(status === 'resolved' ? 'Notas de resolución:' : 'Motivo de rechazo:', item.resolution_notes || '');
            if (notes === null) return;
            const res = await fetch('/public/soporte/api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'admin_resolve', id: Number(item.id), resolution: status, notes })
            });
            const data = await res.json();
            if (!data?.ok) {
                alert(data?.error || 'No se pudo actualizar la solicitud');
                return;
            }
            await this.loadItems();
        }
    };
}
</script>
</body>
</html>
