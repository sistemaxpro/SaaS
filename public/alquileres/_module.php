<?php

require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');

function smxAlqCurrentCompanyName(): string
{
    return (string)($_SESSION['empresa_nombre'] ?? $_SESSION['empresa'] ?? 'Empresa');
}

function smxAlqCurrentUserName(): string
{
    return (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? 'Usuario');
}

function smxAlqMenuItems(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Panel', 'icon' => 'chart-line', 'href' => '/public/alquileres/index.php'],
        ['key' => 'propiedades', 'label' => 'Propiedades', 'icon' => 'building', 'href' => '/public/alquileres/propiedades.php'],
        ['key' => 'imagenes', 'label' => 'Imágenes', 'icon' => 'image', 'href' => '/public/alquileres/imagenes.php'],
        ['key' => 'gastos', 'label' => 'Gastos', 'icon' => 'receipt', 'href' => '/public/alquileres/gastos.php'],
        ['key' => 'contratos', 'label' => 'Contratos', 'icon' => 'file-signature', 'href' => '/public/alquileres/contratos.php'],
        ['key' => 'facturacion', 'label' => 'Facturación', 'icon' => 'file-invoice-dollar', 'href' => '/public/alquileres/facturacion.php'],
        ['key' => 'avisos', 'label' => 'Avisos', 'icon' => 'bell', 'href' => '/public/alquileres/avisos.php'],
    ];
}

function smxAlqRenderHead(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        alq: {
                            ink: '#102033',
                            panel: '#17263a',
                            line: '#31455f',
                            accent: '#0f766e',
                            warm: '#c0841a'
                        }
                    }
                }
            }
        };
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        html { font-size: 13px; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 13px;
            font-weight: 300;
            line-height: 1.35;
        }
        body, body * {
            font-weight: 300 !important;
        }
        body :where(i.fa, i.fas, i.far, i.fal, i.fab, i.fad, [class^="fa-"], [class*=" fa-"]) {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands" !important;
        }
        body :where(.fa-solid, .fas, .fa-classic) { font-weight: 900 !important; }
        body :where(.fa-regular, .far, .fa-brands, .fab) { font-weight: 400 !important; }
        body :where(.fa-light, .fal, .fa-thin) { font-weight: 300 !important; }
        .alq-card {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.72);
            box-shadow: 0 16px 44px rgba(15, 23, 42, 0.18);
        }
        .alq-card-light {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .dark .dark\:alq-card {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.72);
            box-shadow: 0 16px 44px rgba(15, 23, 42, 0.18);
        }
        .alq-input, .alq-select, .alq-textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(255, 255, 255, 0.86);
            padding: 9px 11px;
            color: #0f172a;
        }
        .dark .alq-input, .dark .alq-select, .dark .alq-textarea {
            border-color: rgba(71, 85, 105, 0.9);
            background: rgba(15, 23, 42, 0.88);
            color: #e2e8f0;
        }
        .alq-btn {
            border-radius: 14px;
            padding: 9px 13px;
            transition: all .18s ease;
        }
        .alq-btn:hover { transform: translateY(-1px); }
        .alq-badge {
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 12px;
        }
    </style>
</head>
<body class="min-h-full bg-[radial-gradient(circle_at_top_left,_rgba(15,118,110,.18),_transparent_26%),linear-gradient(135deg,#f4f8fb_0%,#eef3f9_45%,#e5ecf5_100%)] text-slate-900 dark:bg-[radial-gradient(circle_at_top_left,_rgba(15,118,110,.24),_transparent_24%),linear-gradient(135deg,#0b1220_0%,#111c2c_48%,#162235_100%)] dark:text-slate-100">
    <?php
}

function smxAlqRenderTopbar(string $current, string $title, string $subtitle = ''): void
{
    $menuItems = smxAlqMenuItems();
    ?>
    <div class="min-h-screen">
        <header class="sticky top-0 z-30 border-b border-white/20 bg-white/70 backdrop-blur-xl dark:border-slate-700/70 dark:bg-slate-950/70">
            <div class="mx-auto flex w-full max-w-none items-center justify-between gap-4 px-4 py-3">
                <div class="flex items-center gap-3">
                    <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                            class="alq-btn flex h-10 w-10 items-center justify-center border border-rose-200 bg-rose-50 text-rose-600 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="alq-badge bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200">Inmobiliaria Paraguay</span>
                        </div>
                        <h1 class="mt-1 text-[18px] text-slate-900 dark:text-white"><i class="fas fa-key text-teal-600 dark:text-teal-300"></i> <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($subtitle !== '' ? $subtitle : smxAlqCurrentCompanyName(), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[11px] text-slate-500 dark:text-slate-400">Operador</div>
                    <div><?= htmlspecialchars(smxAlqCurrentUserName(), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
            <nav class="mx-auto flex w-full max-w-none gap-2 overflow-x-auto px-4 pb-3">
                <?php foreach ($menuItems as $item): ?>
                    <?php $active = $item['key'] === $current; ?>
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                       class="alq-btn inline-flex items-center gap-2 whitespace-nowrap border <?= $active ? 'border-teal-500 bg-teal-600 text-white dark:bg-teal-500 dark:text-slate-950' : 'border-slate-300 bg-white/80 text-slate-700 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-200' ?>">
                        <i class="fas fa-<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </header>
        <main class="mx-auto w-full max-w-none px-4 py-5">
    <?php
}

function smxAlqRenderFoot(): void
{
    ?>
        </main>
    </div>
</body>
</html>
    <?php
}

function smxAlqRenderLegalBox(): void
{
    ?>
    <section class="alq-card-light dark:alq-card p-4 text-[11px] text-slate-600 dark:text-slate-300">
        <div class="mb-2 flex items-center gap-2 text-slate-900 dark:text-white">
            <i class="fas fa-scale-balanced text-amber-500"></i>
            <span>Base legal configurable</span>
        </div>
        <ul class="space-y-1">
            <li>Código Civil paraguayo, régimen de locación y restitución del inmueble.</li>
            <li>Ley 5638/2016 para alquiler de viviendas y registro/ejecución conforme corresponda.</li>
            <li>Ley 7593/2025 sobre datos personales: consentimiento para WhatsApp y tratamiento de datos del inquilino.</li>
            <li>DNIT e-Kuatia/SIFEN: la factura electrónica requiere habilitación del emisor, certificado y remisión válida.</li>
        </ul>
        <p class="mt-2">El contrato privado y las cláusulas salen editables para revisión del abogado de la inmobiliaria antes de firmar.</p>
    </section>
    <?php
}

function smxAlqRenderSpaHead(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        alq: {
                            ink: '#102033',
                            panel: '#17263a',
                            line: '#31455f',
                            accent: '#0f766e',
                            warm: '#c0841a'
                        }
                    }
                }
            }
        };
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        html { font-size: 13px; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 13px;
            font-weight: 300;
            line-height: 1.35;
        }
        body, body * {
            font-weight: 300 !important;
        }
        body :where(i.fa, i.fas, i.far, i.fal, i.fab, i.fad, [class^="fa-"], [class*=" fa-"]) {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands" !important;
        }
        body :where(.fa-solid, .fas, .fa-classic) { font-weight: 900 !important; }
        body :where(.fa-regular, .far, .fa-brands, .fab) { font-weight: 400 !important; }
        body :where(.fa-light, .fal, .fa-thin) { font-weight: 300 !important; }
        .alq-card {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.72);
            box-shadow: 0 16px 44px rgba(15, 23, 42, 0.18);
        }
        .alq-card-light {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .dark .dark\:alq-card {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.72);
            box-shadow: 0 16px 44px rgba(15, 23, 42, 0.18);
        }
        .alq-input, .alq-select, .alq-textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(255, 255, 255, 0.86);
            padding: 9px 11px;
            color: #0f172a;
        }
        .dark .alq-input, .dark .alq-select, .dark .alq-textarea {
            border-color: rgba(71, 85, 105, 0.9);
            background: rgba(15, 23, 42, 0.88);
            color: #e2e8f0;
        }
        .alq-btn {
            border-radius: 14px;
            padding: 9px 13px;
            transition: all .18s ease;
        }
        .alq-btn:hover { transform: translateY(-1px); }
        .alq-badge {
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 12px;
        }
        .alq-tab-active {
            border-color: rgb(20, 184, 166) !important;
            background-color: rgb(6, 132, 120) !important;
            color: white !important;
        }
    </style>
</head>
<body class="min-h-full bg-[radial-gradient(circle_at_top_left,_rgba(15,118,110,.18),_transparent_26%),linear-gradient(135deg,#f4f8fb_0%,#eef3f9_45%,#e5ecf5_100%)] text-slate-900 dark:bg-[radial-gradient(circle_at_top_left,_rgba(15,118,110,.24),_transparent_24%),linear-gradient(135deg,#0b1220_0%,#111c2c_48%,#162235_100%)] dark:text-slate-100">
    <?php
}

function smxAlqRenderSpaNav(): void
{
    $menuItems = smxAlqMenuItems();
    ?>
    <div class="min-h-screen">
        <header class="sticky top-0 z-30 border-b border-white/20 bg-white/70 backdrop-blur-xl dark:border-slate-700/70 dark:bg-slate-950/70">
            <div class="mx-auto flex w-full max-w-none items-center justify-between gap-4 px-4 py-3">
                <div class="flex items-center gap-3">
                    <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                            class="alq-btn flex h-10 w-10 items-center justify-center border border-rose-200 bg-rose-50 text-rose-600 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="alq-badge bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200">Inmobiliaria Paraguay</span>
                        </div>
                        <h1 class="mt-1 text-[18px] text-slate-900 dark:text-white"><i class="fas fa-key text-teal-600 dark:text-teal-300"></i> <span x-text="tabTitle" id="tabTitle">Gestión de Alquileres</span></h1>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400" x-text="tabSubtitle" id="tabSubtitle"><?= htmlspecialchars(smxAlqCurrentCompanyName(), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[11px] text-slate-500 dark:text-slate-400">Operador</div>
                    <div><?= htmlspecialchars(smxAlqCurrentUserName(), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
            <nav class="mx-auto flex w-full max-w-none gap-2 overflow-x-auto px-4 pb-3">
                <template x-for="item in tabs" :key="item.key">
                    <button @click="setTab(item.key)"
                       :class="{
                           'alq-tab-active': activeTab === item.key,
                           'border-slate-300 bg-white/80 text-slate-700 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-200': activeTab !== item.key
                       }"
                       class="alq-btn inline-flex items-center gap-2 whitespace-nowrap border">
                        <i :class="'fas fa-' + item.icon"></i>
                        <span x-text="item.label"></span>
                    </button>
                </template>
            </nav>
        </header>
        <main class="mx-auto w-full max-w-none px-4 py-5">
    <?php
}

function smxAlqRenderSpaFoot(): void
{
    ?>
        </main>
    </div>
</body>
</html>
    <?php
}
