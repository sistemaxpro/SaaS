<?php
/**
 * Central de Aplicaciones
 * Catálogo de apps disponibles — muestra qué tiene activo la empresa y qué puede agregar.
 */
require_once __DIR__ . '/../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$id_empresa  = (int)(Session::getIdEmpresa() ?? 0);
$id_login    = (int)(Session::getIdLogin() ?? 0);
$usr_name    = $_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '';

if ($id_empresa <= 0) {
    header('Location: /public/login.php');
    exit;
}

require_once __DIR__ . '/../src/Modules/Empresas/SuscripcionController.php';

// ---------- Datos ----------
$db = Database::getMasterConnection();

// Apps activas de la empresa
$appsActivasResult = SuscripcionController::getAppsEmpresa($id_empresa, 0);
$appsActivas = $appsActivasResult['data'] ?? [];
$codigosActivos = array_flip(array_map('strtolower', array_column($appsActivas, 'codigo')));
$rutasActivas   = array_flip(array_filter(array_map('strtolower', array_column($appsActivas, 'app'))));

// Todas las apps del catálogo (activas, no en desarrollo)
$stmtCatalogo = $db->query("
    SELECT id_app, codigo, nombre, descripcion, ruta_app,
           icono, icono_source, icono_svg, color,
           precio_mensual, orden,
           COALESCE(NULLIF(modulo,''), 'General') AS modulo,
           COALESCE(NULLIF(negocio,''), 'General') AS negocio
    FROM saas_apps_catalogo
    WHERE activo = 1
      AND en_desarrollo = 0
      AND COALESCE(ruta_app,'') <> ''
    ORDER BY COALESCE(orden,9999) ASC, nombre ASC
");
$todasApps = $stmtCatalogo ? $stmtCatalogo->fetchAll(PDO::FETCH_ASSOC) : [];

// Resolver icono a URL para usar en <img> o devolver inline SVG
function resolveAppIcon(array $app): string
{
    $iconoSvg  = trim((string)($app['icono_svg'] ?? ''));
    $icono     = trim((string)($app['icono'] ?? ''));
    $source    = strtolower(trim((string)($app['icono_source'] ?? '')));

    if ($iconoSvg !== '') {
        $iconoSvg = ltrim($iconoSvg, '/');
        if (!str_starts_with($iconoSvg, 'public/')) {
            $iconoSvg = 'public/' . $iconoSvg;
        }
        $disk = dirname(__DIR__) . '/' . $iconoSvg;
        if (is_file($disk)) {
            return '<img src="/' . htmlspecialchars($iconoSvg, ENT_QUOTES) . '" class="w-full h-full object-contain" alt="">';
        }
    }

    if ($icono !== '') {
        $iconoSvg2 = __DIR__ . '/assets/images/icons_v2/' . $icono . '.svg';
        if (is_file($iconoSvg2)) {
            return '<img src="/public/assets/images/icons_v2/' . htmlspecialchars($icono, ENT_QUOTES) . '.svg" class="w-full h-full object-contain" alt="">';
        }

        if ($source === 'tabler') {
            $path = dirname(__DIR__) . '/node_modules/tabler-icons/icons/' . $icono . '.svg';
            if (is_file($path)) {
                return trim((string)file_get_contents($path));
            }
        }

        $path = dirname(__DIR__) . '/node_modules/heroicons/24/outline/' . $icono . '.svg';
        if (is_file($path)) {
            return trim((string)file_get_contents($path));
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>';
}

function isAppActiva(array $app, array $codigosActivos, array $rutasActivas): bool
{
    $codigo = strtolower((string)($app['codigo'] ?? ''));
    $ruta   = strtolower((string)($app['ruta_app'] ?? ''));
    return isset($codigosActivos[$codigo]) || ($ruta !== '' && isset($rutasActivas[$ruta]));
}

function colorClass(string $color): string
{
    $map = [
        'red' => 'from-red-500 to-rose-600',
        'orange' => 'from-orange-500 to-amber-600',
        'amber' => 'from-amber-500 to-yellow-600',
        'yellow' => 'from-yellow-500 to-amber-600',
        'green' => 'from-green-500 to-emerald-600',
        'emerald' => 'from-emerald-500 to-teal-600',
        'teal' => 'from-teal-500 to-cyan-600',
        'cyan' => 'from-cyan-500 to-sky-600',
        'sky' => 'from-sky-500 to-blue-600',
        'blue' => 'from-blue-500 to-indigo-600',
        'indigo' => 'from-indigo-500 to-violet-600',
        'violet' => 'from-violet-500 to-purple-600',
        'purple' => 'from-purple-500 to-fuchsia-600',
        'pink' => 'from-pink-500 to-rose-600',
        'rose' => 'from-rose-500 to-red-600',
    ];
    return $map[strtolower(trim($color))] ?? 'from-blue-500 to-indigo-600';
}

// Separar en activas y disponibles para la empresa
$appsActivasCards    = [];
$appsDisponiblesCards = [];
foreach ($todasApps as $app) {
    $activa = isAppActiva($app, $codigosActivos, $rutasActivas);
    if ($activa) {
        $appsActivasCards[] = $app;
    } else {
        $appsDisponiblesCards[] = $app;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<script>(function(){document.documentElement.classList.add('dark');document.documentElement.style.colorScheme='dark';})();</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Central de Aplicaciones - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{}}}</script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [x-cloak]{display:none!important}
        html,body{height:100%;overflow:hidden;background:#020617;color:#e2e8f0}
        .app-container{height:100vh;display:flex;flex-direction:column;overflow:hidden;background:radial-gradient(1200px 700px at 10% -10%,rgba(30,64,175,.22),transparent 60%),radial-gradient(900px 600px at 100% 0%,rgba(15,23,42,.35),transparent 65%),linear-gradient(180deg,#0b1220 0%,#020617 100%)}
        .app-content{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
        .app-content::-webkit-scrollbar{width:4px}
        .app-content::-webkit-scrollbar-track{background:transparent}
        .app-content::-webkit-scrollbar-thumb{background:rgba(148,163,184,.2);border-radius:4px}
        .app-card{background:rgba(15,23,42,.75);border:1px solid rgba(255,255,255,.07);transition:border-color .2s,background .2s}
        .app-card:hover{background:rgba(30,41,59,.9);border-color:rgba(255,255,255,.14)}
        .app-card.is-active{border-color:rgba(52,211,153,.25);background:rgba(5,46,22,.35)}
        .icon-wrap{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
        .icon-wrap svg{width:24px;height:24px;display:block}
        .icon-wrap img{width:28px;height:28px;object-fit:contain}
        .badge-activo{font-size:.65rem;padding:2px 8px;border-radius:99px;background:rgba(52,211,153,.15);color:#6ee7b7;border:1px solid rgba(52,211,153,.3);letter-spacing:.03em}
        .section-title{font-size:.7rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:rgba(148,163,184,.5)}
        .precio{font-size:.78rem;color:rgba(148,163,184,.7)}
        .search-input{background:rgba(15,23,42,.8);border:1px solid rgba(255,255,255,.1);color:#e2e8f0;border-radius:10px;padding:10px 16px 10px 40px;width:100%;font-size:.9rem;outline:none;transition:border-color .2s}
        .search-input:focus{border-color:rgba(99,102,241,.5)}
        .search-input::placeholder{color:rgba(148,163,184,.4)}
    </style>
</head>
<body class="text-white">
<div x-data="centralAppsApp()" x-cloak class="app-container">

    <!-- Header -->
    <header class="bg-slate-900/80 backdrop-blur-xl border-b border-white/10 flex-shrink-0 sticky top-0 z-40">
        <div class="px-4 py-3 flex items-center gap-3">
            <a href="/public/menu/menu.php" class="w-9 h-9 flex items-center justify-center rounded-xl bg-white/5 hover:bg-white/10 transition-colors flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
            </a>
            <div class="flex items-center gap-2 flex-1 min-w-0">
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                </div>
                <div class="min-w-0">
                    <h1 class="text-sm font-semibold leading-tight truncate">Central de Aplicaciones</h1>
                    <p class="text-xs text-slate-400 leading-tight"><?= htmlspecialchars($usr_name, ENT_QUOTES) ?></p>
                </div>
            </div>
            <!-- Stats -->
            <div class="flex-shrink-0 text-right">
                <p class="text-xs text-emerald-400 font-medium"><?= count($appsActivasCards) ?> activas</p>
                <p class="text-xs text-slate-500"><?= count($todasApps) ?> disponibles</p>
            </div>
        </div>

        <!-- Tabs + Search -->
        <div class="px-4 pb-3 flex flex-col gap-2">
            <div class="flex gap-1 bg-slate-800/60 rounded-xl p-1">
                <button @click="tab='activas'" :class="tab==='activas' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-400 hover:text-white'"
                        class="flex-1 py-1.5 px-3 rounded-lg text-xs font-medium transition-all">
                    Mis Apps <span class="ml-1 text-emerald-400">(<?= count($appsActivasCards) ?>)</span>
                </button>
                <button @click="tab='disponibles'" :class="tab==='disponibles' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-400 hover:text-white'"
                        class="flex-1 py-1.5 px-3 rounded-lg text-xs font-medium transition-all">
                    Catálogo <span class="ml-1 text-slate-500">(<?= count($appsDisponiblesCards) ?>)</span>
                </button>
            </div>
            <div class="relative">
                <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                <input x-model="search" type="search" placeholder="Buscar aplicación..." class="search-input">
            </div>
        </div>
    </header>

    <!-- Content -->
    <main class="app-content">
        <div class="px-4 py-4 pb-24">

            <!-- Mis Apps activas -->
            <div x-show="tab==='activas'">
                <?php if (empty($appsActivasCards)): ?>
                <div class="text-center py-16 text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-12 h-12 mx-auto mb-3 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                    <p class="text-sm">No tenés apps activas</p>
                    <p class="text-xs mt-1">Explorá el catálogo para agregar apps</p>
                </div>
                <?php else: ?>
                <div class="grid gap-2">
                    <?php foreach ($appsActivasCards as $app):
                        $iconHtml  = resolveAppIcon($app);
                        $gradClass = colorClass($app['color'] ?? 'blue');
                        $nombre    = htmlspecialchars($app['nombre'] ?? '', ENT_QUOTES);
                        $desc      = htmlspecialchars(mb_strimwidth($app['descripcion'] ?? '', 0, 80, '…'), ENT_QUOTES);
                        $ruta      = htmlspecialchars($app['ruta_app'] ?? '', ENT_QUOTES);
                    ?>
                    <div x-show="!search || '<?= addslashes(strtolower($app['nombre'])) ?>'.includes(search.toLowerCase())"
                         class="app-card is-active rounded-2xl p-3 flex items-center gap-3 cursor-pointer"
                         onclick="window.location='/<?= $ruta ?>'">
                        <div class="icon-wrap bg-gradient-to-br <?= $gradClass ?>"><?= $iconHtml ?></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium leading-tight truncate"><?= $nombre ?></p>
                                <span class="badge-activo flex-shrink-0">activa</span>
                            </div>
                            <?php if ($desc): ?>
                            <p class="text-xs text-slate-400 leading-tight mt-0.5 truncate"><?= $desc ?></p>
                            <?php endif; ?>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-slate-600 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Catálogo completo (no activas) -->
            <div x-show="tab==='disponibles'">
                <?php if (empty($appsDisponiblesCards)): ?>
                <div class="text-center py-16 text-slate-500">
                    <p class="text-sm">Tenés todas las apps disponibles activas</p>
                </div>
                <?php else: ?>
                <div class="grid gap-2">
                    <?php foreach ($appsDisponiblesCards as $app):
                        $iconHtml  = resolveAppIcon($app);
                        $gradClass = colorClass($app['color'] ?? 'blue');
                        $nombre    = htmlspecialchars($app['nombre'] ?? '', ENT_QUOTES);
                        $desc      = htmlspecialchars(mb_strimwidth($app['descripcion'] ?? '', 0, 80, '…'), ENT_QUOTES);
                        $precio    = (float)($app['precio_mensual'] ?? 0);
                        $precioStr = $precio > 0 ? 'Gs. ' . number_format($precio, 0, ',', '.') . '/mes' : 'Incluida';
                    ?>
                    <div x-show="!search || '<?= addslashes(strtolower($app['nombre'])) ?>'.includes(search.toLowerCase())"
                         class="app-card rounded-2xl p-3 flex items-center gap-3">
                        <div class="icon-wrap bg-gradient-to-br <?= $gradClass ?> opacity-60"><?= $iconHtml ?></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight truncate"><?= $nombre ?></p>
                            <?php if ($desc): ?>
                            <p class="text-xs text-slate-500 leading-tight mt-0.5 truncate"><?= $desc ?></p>
                            <?php endif; ?>
                            <p class="precio mt-1"><?= htmlspecialchars($precioStr, ENT_QUOTES) ?></p>
                        </div>
                        <div class="flex-shrink-0 text-xs text-slate-600 font-medium">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-slate-700"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<script>
function centralAppsApp() {
    return {
        tab: 'activas',
        search: '',
    };
}
</script>
</body>
</html>
