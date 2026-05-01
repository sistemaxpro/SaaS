<?php
/**
 * Explorador de Productos - Search Landing Dark
 * Inspirado en layout de buscadores e-commerce tipo LIQUI MOLY
 */

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
$forceDesktop = isset($_GET['desktop']) || isset($_COOKIE['productos_search_dark']);

if ($isMobile && !$forceDesktop && !isset($_GET['no_redirect'])) {
    header('Location: /public/productos/search_dark.php?desktop=1&no_redirect=1');
    exit;
}

if (isset($_GET['desktop'])) {
    setcookie('productos_search_dark', '1', time() + 86400 * 30, '/');
}

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_mercaderias');

$id_empresa = (int)Session::get('id_empresa', 169);
$empresa = Session::get('empresa', 'SistemaX');
$currentLocale = SmxI18n::normalizeLocale(Session::get('locale', null)) ?? SmxI18n::getLocale();
$initialSearch = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));
$initialGroup = trim((string)($_GET['grupo'] ?? ''));
$initialBrand = trim((string)($_GET['marca'] ?? ''));
$initialSort = trim((string)($_GET['sort'] ?? 'relevance'));
$returnUrl = trim((string)($_GET['return_url'] ?? $_GET['origin'] ?? ''));
if ($returnUrl !== '' && (strpos($returnUrl, '/') !== 0 || strpos($returnUrl, '//') === 0)) {
    $returnUrl = '';
}
$httpReferer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
$sameOriginReferer = '';
if ($httpReferer !== '') {
    $refererHost = parse_url($httpReferer, PHP_URL_HOST) ?: '';
    $currentHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($refererHost !== '' && strcasecmp($refererHost, $currentHost) === 0) {
        $sameOriginReferer = (string)$httpReferer;
    }
}
$modalMode = ((int)($_GET['modal'] ?? 0) === 1);
$pageShellClass = $modalMode
    ? 'w-full max-w-none min-h-[99dvh] px-2 pb-2 pt-2 sm:px-3 lg:px-3'
    : 'mx-auto max-w-[1500px] px-4 pb-10 pt-4 sm:px-6 lg:px-8';
$headerRadiusClass = $modalMode ? 'rounded-[22px]' : 'rounded-[28px]';
$mainRadiusClass = $modalMode ? 'rounded-[22px]' : 'rounded-[28px]';
$mainMarginTopClass = $modalMode ? 'mt-3' : 'mt-6';
$mainMinHeightClass = $modalMode ? 'min-h-[calc(99dvh-220px)]' : '';
$uiMap = [
    'es' => ['page_title' => 'Buscador general', 'engine' => 'Motor de productos', 'hero' => 'Buscar', 'search_placeholder' => 'Buscar productos, marcas o categorías', 'search_button' => 'Buscar', 'results' => 'Resultados', 'current_sort' => 'Orden actual', 'view' => 'Vista', 'filter' => 'Filtro', 'clear_all' => 'Limpiar todo', 'sort_by' => 'Ordenar por', 'view_as' => 'Visto como', 'category' => 'Categoría', 'brand' => 'Marca', 'all_brands' => 'Todas las marcas', 'loading' => 'Cargando resultados...', 'empty_title' => 'No se encontraron productos', 'empty_desc' => 'Pruebe otra búsqueda o limpie los filtros actuales.', 'add_to_cart' => 'Agregar al carrito', 'view_details' => 'Ver detalles', 'code' => 'Código', 'stock' => 'Stock', 'showing' => 'Mostrando', 'page' => 'Página', 'of' => 'de', 'clear' => 'Limpiar', 'product_fallback' => 'Producto', 'unclassified' => 'Sin clasificar', 'sort_relevance' => 'Relevancia', 'sort_price_asc' => 'Precio ascendente', 'sort_price_desc' => 'Precio descendente'],
    'en' => ['page_title' => 'General search', 'engine' => 'Product engine', 'hero' => 'Search', 'search_placeholder' => 'Search products, brands or categories', 'search_button' => 'Search', 'results' => 'Results', 'current_sort' => 'Current sort', 'view' => 'View', 'filter' => 'Filter', 'clear_all' => 'Clear all', 'sort_by' => 'Sort by', 'view_as' => 'View as', 'category' => 'Category', 'brand' => 'Brand', 'all_brands' => 'All brands', 'loading' => 'Loading results...', 'empty_title' => 'No products found', 'empty_desc' => 'Try another search or clear the current filters.', 'add_to_cart' => 'Add to cart', 'view_details' => 'View details', 'code' => 'Code', 'stock' => 'Stock', 'showing' => 'Showing', 'page' => 'Page', 'of' => 'of', 'clear' => 'Clear', 'product_fallback' => 'Product', 'unclassified' => 'Unclassified', 'sort_relevance' => 'Relevance', 'sort_price_asc' => 'Price ascending', 'sort_price_desc' => 'Price descending'],
    'pt' => ['page_title' => 'Pesquisa geral', 'engine' => 'Motor de produtos', 'hero' => 'Pesquisar', 'search_placeholder' => 'Pesquisar produtos, marcas ou categorias', 'search_button' => 'Pesquisar', 'results' => 'Resultados', 'current_sort' => 'Ordenação atual', 'view' => 'Vista', 'filter' => 'Filtro', 'clear_all' => 'Limpar tudo', 'sort_by' => 'Ordenar por', 'view_as' => 'Visto como', 'category' => 'Categoria', 'brand' => 'Marca', 'all_brands' => 'Todas as marcas', 'loading' => 'Carregando resultados...', 'empty_title' => 'Nenhum produto encontrado', 'empty_desc' => 'Tente outra busca ou limpe os filtros atuais.', 'add_to_cart' => 'Adicionar ao carrinho', 'view_details' => 'Ver detalhes', 'code' => 'Código', 'stock' => 'Estoque', 'showing' => 'Mostrando', 'page' => 'Página', 'of' => 'de', 'clear' => 'Limpar', 'product_fallback' => 'Produto', 'unclassified' => 'Sem classificação', 'sort_relevance' => 'Relevância', 'sort_price_asc' => 'Preço crescente', 'sort_price_desc' => 'Preço decrescente'],
];
$ui = $uiMap[$currentLocale] ?? $uiMap['es'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?>" class="dark" x-data="searchLandingApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($ui['page_title'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        window.__SEARCH_DARK_UI__ = <?= json_encode($ui, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.__SEARCH_DARK_LOCALE__ = <?= json_encode($currentLocale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style>
        [x-cloak] { display: none !important; }
        html, body { background: #020617; color: #e5e7eb; }
        body {
            background:
                radial-gradient(circle at top, rgba(14, 165, 233, 0.10), transparent 28%),
                linear-gradient(180deg, #020617 0%, #07111f 100%);
        }
        .glass-dark {
            background: rgba(10, 15, 28, 0.88);
            border: 1px solid rgba(51, 65, 85, 0.92);
            box-shadow: 0 18px 40px rgba(2, 6, 23, 0.34);
        }
        .hero-shell {
            background: transparent;
            border: 0;
            box-shadow: none;
        }
        .top-nav-link {
            color: #cbd5e1;
            transition: color .18s ease;
        }
        .top-nav-link:hover { color: #f8fafc; }
        .facet-item {
            transition: all .18s ease;
        }
        .facet-item:hover { background: rgba(30, 41, 59, 0.9); }
        .product-card {
            background: transparent;
            border: 0;
            transition: transform .18s ease, opacity .18s ease;
        }
        .product-card:hover {
            transform: translateY(-2px);
            opacity: 0.92;
        }
        .search-hit {
            background: rgba(56, 189, 248, 0.18);
            color: #e0f2fe;
            border-radius: 0.35rem;
            padding: 0 0.16rem;
            box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.18);
        }
        .product-image {
            background:
                radial-gradient(circle at top, rgba(255,255,255,0.06), transparent 42%),
                linear-gradient(180deg, rgba(15, 23, 42, 0.55), rgba(2, 6, 23, 0.18));
        }
        .edit-image-fab {
            position: absolute;
            right: 0.7rem;
            top: 0.7rem;
            z-index: 3;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.6rem;
            height: 2.6rem;
            border-radius: 9999px;
            border: 1px solid rgba(125, 211, 252, 0.42);
            background: rgba(8, 47, 73, 0.92);
            color: #e0f2fe;
            box-shadow: 0 10px 22px rgba(2, 6, 23, 0.34);
            transition: transform .18s ease, background .18s ease, border-color .18s ease;
        }
        .edit-image-fab:hover {
            transform: translateY(-1px) scale(1.02);
            background: rgba(14, 116, 144, 0.96);
            border-color: rgba(103, 232, 249, 0.62);
        }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .search-hero-input {
            background: rgba(15, 23, 42, 0.86);
            border: 1px solid rgba(51, 65, 85, 0.9);
            color: #f8fafc;
        }
        .search-hero-input::placeholder {
            color: #94a3b8;
        }
        .soft-divider {
            border-color: rgba(51, 65, 85, 0.9);
        }
        .source-action-card {
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(51, 65, 85, 0.92);
            transition: transform .18s ease, border-color .18s ease, background .18s ease;
        }
        .source-action-card:hover {
            transform: translateY(-1px);
            border-color: rgba(56, 189, 248, 0.45);
            background: rgba(17, 24, 39, 0.96);
        }
        .bing-result-card {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(51, 65, 85, 0.9);
            transition: transform .18s ease, border-color .18s ease;
        }
        .bing-result-card:hover {
            transform: translateY(-1px);
            border-color: rgba(34, 211, 238, 0.42);
        }
        .detail-panel {
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(51, 65, 85, 0.92);
        }
    </style>
</head>
<body class="min-h-screen font-sans text-slate-100">
    <div class="<?= htmlspecialchars($pageShellClass, ENT_QUOTES, 'UTF-8') ?>">
        <header class="hero-shell overflow-hidden <?= htmlspecialchars($headerRadiusClass, ENT_QUOTES, 'UTF-8') ?>">
            <div class="px-3 py-4 sm:px-5">
                <div class="flex items-center justify-between text-xs uppercase tracking-[0.2em] text-slate-500">
                    <div class="flex items-center gap-3">
                        <button type="button"
                                @click="goBackOrClose()"
                                class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900/80 px-3 py-2 text-[11px] font-semibold tracking-[0.16em] text-slate-300 transition hover:border-slate-500 hover:text-white">
                            <i class="fas fa-arrow-left text-[10px]"></i>
                            <span>Volver</span>
                        </button>
                        <a href="/public/menu/menu.php" class="font-semibold text-slate-300 hover:text-white">SistemaX</a>
                    </div>
                    <div class="hidden md:flex items-center gap-5">
                        <template x-for="grupo in navGroups" :key="'nav-' + grupo.id">
                            <button type="button" class="top-nav-link" @click="selectGroup(grupo.id)" x-text="grupo.nombre"></button>
                        </template>
                    </div>
                </div>
                <div class="mx-auto mt-8 max-w-3xl text-center">
                    <h1 class="text-4xl font-black tracking-tight text-white sm:text-5xl"><?= htmlspecialchars($ui['hero'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="mx-auto mt-5 flex max-w-xl items-center gap-3 rounded-2xl search-hero-input px-4 py-3 shadow-sm">
                        <i class="fas fa-search text-sm text-slate-400"></i>
                        <input type="text"
                               x-model="query"
                               @keydown.enter.prevent="applyFilters()"
                               @input.debounce.300ms="applyFilters()"
                               placeholder="<?= htmlspecialchars($ui['search_placeholder'], ENT_QUOTES, 'UTF-8') ?>"
                               class="min-w-0 flex-1 bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-400">
                        <button x-show="query" @click="clearQuery()" class="rounded-full p-1 text-slate-400 transition hover:bg-slate-800 hover:text-slate-100">
                            <i class="fas fa-times text-xs"></i>
                        </button>
                        <button type="button" @click="applyFilters()" class="text-slate-400 transition hover:text-white">
                            <i class="fas fa-microphone text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="<?= htmlspecialchars(trim($mainMarginTopClass . ' ' . $mainMinHeightClass), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($mainRadiusClass, ENT_QUOTES, 'UTF-8') ?> glass-dark p-0">
            <div class="flex flex-col gap-4 border-y soft-divider px-3 py-4 sm:px-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-4 text-sm text-slate-400">
                    <span class="font-semibold text-slate-100"><?= htmlspecialchars($ui['filter'], ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" @click="clearAll()" class="inline-flex items-center gap-2 transition hover:text-white">
                        <i class="fas fa-sliders-h text-xs"></i>
                        <?= htmlspecialchars($ui['clear_all'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <span class="h-5 w-px bg-slate-700"></span>
                    <span class="font-semibold text-slate-100"><?= htmlspecialchars($ui['sort_by'], ENT_QUOTES, 'UTF-8') ?></span>
                    <select x-model="sort" @change="applyFilters()" class="rounded-xl border border-transparent bg-transparent px-1 py-2 text-sm text-slate-300 outline-none">
                        <option value="relevance"><?= htmlspecialchars($ui['sort_relevance'], ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="price_asc"><?= htmlspecialchars($ui['sort_price_asc'], ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="price_desc"><?= htmlspecialchars($ui['sort_price_desc'], ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>
                <div class="flex items-center gap-3 text-sm text-slate-500">
                    <span class="font-semibold text-slate-100"><?= htmlspecialchars($ui['view_as'], ENT_QUOTES, 'UTF-8') ?></span>
                    <button @click="view = 'list'" :class="view === 'list' ? 'text-white' : 'text-slate-500'" class="transition"><i class="fas fa-list-ul"></i></button>
                    <button @click="view = 'grid'" :class="view === 'grid' ? 'text-white' : 'text-slate-500'" class="transition"><i class="fas fa-border-all"></i></button>
                </div>
            </div>

            <div class="grid gap-0 lg:grid-cols-[240px,minmax(0,1fr)]">
                <aside class="border-r soft-divider px-3 py-5 sm:px-5">
                    <section class="pb-5">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-bold text-slate-100"><?= htmlspecialchars($ui['category'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <button @click="selectedGroup = ''; applyFilters()" class="text-xs font-semibold text-slate-500 transition hover:text-white"><?= htmlspecialchars($ui['clear'], ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                        <div class="mt-4 space-y-2">
                            <template x-for="facet in categoryFacets" :key="'facet-' + facet.id">
                                <button type="button"
                                        @click="selectGroup(facet.id)"
                                        class="facet-item flex w-full items-start justify-between gap-3 rounded-xl px-2 py-2 text-left"
                                        :class="String(selectedGroup || '') === String(facet.id) ? 'bg-slate-800' : ''">
                                    <span class="text-sm leading-6 text-slate-300" x-text="facet.nombre"></span>
                                    <span class="text-sm font-semibold text-slate-500" x-text="'(' + facet.count + ')'"></span>
                                </button>
                            </template>
                        </div>
                    </section>

                    <section class="border-t soft-divider pt-5">
                        <div class="text-sm font-bold text-slate-100"><?= htmlspecialchars($ui['brand'], ENT_QUOTES, 'UTF-8') ?></div>
                        <select x-model="selectedBrand" @change="applyFilters()" class="mt-3 w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-200 outline-none">
                            <option value=""><?= htmlspecialchars($ui['all_brands'], ENT_QUOTES, 'UTF-8') ?></option>
                            <template x-for="marca in brandOptions" :key="'marca-' + marca.id">
                                <option :value="String(marca.id)" x-text="marca.nombre"></option>
                            </template>
                        </select>
                    </section>
                </aside>

                <section class="px-4 py-5 sm:px-6">
                    <div x-show="loading" class="flex min-h-[320px] items-center justify-center">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin text-3xl text-slate-400"></i>
                            <div class="mt-3 text-sm text-slate-500"><?= htmlspecialchars($ui['loading'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <div x-show="!loading && products.length === 0" x-cloak class="flex min-h-[320px] items-center justify-center rounded-[24px] border border-slate-800 bg-slate-950/40">
                        <div class="text-center">
                            <div class="text-5xl text-slate-600"><i class="fas fa-box-open"></i></div>
                            <div class="mt-4 text-xl font-bold text-white"><?= htmlspecialchars($ui['empty_title'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mt-2 text-sm text-slate-500"><?= htmlspecialchars($ui['empty_desc'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <div x-show="!loading && products.length > 0 && view === 'grid'" x-cloak class="grid gap-x-8 gap-y-10 sm:grid-cols-2 xl:grid-cols-3">
                        <template x-for="product in products" :key="'grid-' + product.idproducto">
                            <article class="product-card">
                                <div class="product-image relative flex aspect-[4/4.8] items-center justify-center overflow-hidden rounded-[18px] p-2"
                                     @contextmenu.prevent.stop="openProductDetail(product)">
                                    <template x-if="product.foto_url">
                                        <img :src="product.foto_url" :alt="product.desproducto" class="h-full w-full object-contain">
                                    </template>
                                    <template x-if="!product.foto_url">
                                        <div class="flex h-full w-full items-center justify-center text-slate-600">
                                            <i class="fas fa-cube text-5xl"></i>
                                        </div>
                                    </template>
                                    <button type="button" class="edit-image-fab" @click.stop="openImageSourceMenu(product)" title="Editar imagen">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <div x-show="Number(imageCapturingId || 0) === Number(product.idproducto || 0)" x-cloak class="absolute inset-0 flex items-center justify-center bg-slate-950/55 text-cyan-200">
                                        <i class="fas fa-spinner fa-spin text-xl"></i>
                                    </div>
                                </div>
                                <div class="mt-4 space-y-2">
                                    <h3 class="line-clamp-2 text-[15px] font-medium leading-6 text-slate-100" x-html="highlightText(product.desproducto)"></h3>
                                    <div class="text-xs uppercase tracking-[0.14em] text-slate-500" x-html="highlightText(product.marca_nombre || product.grupo_nombre || (ui.product_fallback || 'Product'))"></div>
                                    <div class="pt-3">
                                        <button @click="handlePrimaryAction(product)" class="inline-flex rounded-xl border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:bg-slate-100 hover:text-slate-900">
                                            <span x-text="modalMode ? (ui.add_to_cart || 'Add to cart') : (ui.view_details || 'View details')"></span>
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </template>
                    </div>

                    <div x-show="!loading && products.length > 0 && view === 'list'" x-cloak class="space-y-4">
                        <template x-for="product in products" :key="'list-' + product.idproducto">
                            <article class="product-card flex flex-col gap-4 border-b soft-divider pb-4 sm:flex-row sm:items-center">
                                <div class="product-image relative flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-[16px] p-3"
                                     @contextmenu.prevent.stop="openProductDetail(product)">
                                    <template x-if="product.foto_url">
                                        <img :src="product.foto_url" :alt="product.desproducto" class="h-full w-full object-contain">
                                    </template>
                                    <template x-if="!product.foto_url">
                                        <i class="fas fa-cube text-4xl text-slate-600"></i>
                                    </template>
                                    <button type="button" class="edit-image-fab" @click.stop="openImageSourceMenu(product)" title="Editar imagen">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <div x-show="Number(imageCapturingId || 0) === Number(product.idproducto || 0)" x-cloak class="absolute inset-0 flex items-center justify-center bg-slate-950/55 text-cyan-200">
                                        <i class="fas fa-spinner fa-spin text-lg"></i>
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-xl font-semibold text-slate-100" x-html="highlightText(product.desproducto)"></h3>
                                    <div class="mt-2 text-sm text-slate-400" x-html="highlightText((ui.code || 'Code') + ': ' + (product.cve_producto || '-'))"></div>
                                    <div class="mt-1 text-sm text-slate-500" x-html="highlightText(product.marca_nombre || product.grupo_nombre || (ui.unclassified || 'Unclassified'))"></div>
                                </div>
                                <div class="flex flex-col items-start gap-2 sm:items-end">
                                    <div class="text-sm text-slate-400" x-text="(ui.stock || 'Stock') + ': ' + formatNumber(product.saldo || 0)"></div>
                                    <button @click="handlePrimaryAction(product)" class="rounded-xl border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:bg-slate-100 hover:text-slate-900">
                                        <span x-text="modalMode ? (ui.add_to_cart || 'Add to cart') : (ui.view_details || 'View details')"></span>
                                    </button>
                                </div>
                            </article>
                        </template>
                    </div>

                    <div x-show="!loading && totalPages > 1" x-cloak class="mt-8 flex flex-col gap-4 border-t soft-divider px-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-sm text-slate-500">
                            <?= htmlspecialchars($ui['showing'], ENT_QUOTES, 'UTF-8') ?> <span class="font-semibold text-slate-100" x-text="((page - 1) * perPage) + 1"></span>
                            -
                            <span class="font-semibold text-slate-100" x-text="Math.min(page * perPage, total)"></span>
                            <?= htmlspecialchars($ui['of'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="font-semibold text-slate-100" x-text="formatNumber(total)"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="prevPage()" :disabled="page <= 1" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 transition hover:border-slate-300 hover:text-white disabled:cursor-not-allowed disabled:opacity-40">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <div class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-semibold text-slate-100">
                                <?= htmlspecialchars($ui['page'], ENT_QUOTES, 'UTF-8') ?> <span x-text="page"></span> / <span x-text="totalPages"></span>
                            </div>
                            <button @click="nextPage()" :disabled="page >= totalPages" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 transition hover:border-slate-300 hover:text-white disabled:cursor-not-allowed disabled:opacity-40">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <div x-show="showImageSourceMenu" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/72 p-3 sm:items-center">
        <div @click.outside="closeImageSourceMenu()" class="w-full max-w-xl rounded-[28px] border border-slate-700 bg-slate-950/95 shadow-2xl shadow-black/40">
            <div class="flex items-start justify-between gap-4 border-b soft-divider px-5 py-4">
                <div class="min-w-0">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">Origen de imagen</div>
                    <div class="mt-2 text-lg font-bold text-white">Elegí cómo capturar la imagen</div>
                    <div class="mt-1 truncate text-sm text-slate-400" x-text="imageSourceProduct?.desproducto || ''"></div>
                </div>
                <button type="button" @click="closeImageSourceMenu()" class="rounded-full border border-slate-700 p-3 text-slate-400 transition hover:border-slate-500 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="grid gap-3 px-5 py-5 sm:grid-cols-2">
                <button type="button" @click="openBingCatalogPicker(imageSourceProduct)" class="source-action-card flex items-start gap-4 rounded-2xl px-4 py-4 text-left">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-cyan-500/14 text-cyan-300"><i class="fas fa-wand-magic-sparkles"></i></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-white">Catalogo Bing</span>
                        <span class="mt-1 block text-xs leading-5 text-slate-400">Busca varias opciones de catalogo y deja elegir antes de guardar.</span>
                    </span>
                </button>
                <button type="button" @click="openGoogleImageSearch(imageSourceProduct)" class="source-action-card flex items-start gap-4 rounded-2xl px-4 py-4 text-left">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-500/14 text-emerald-300"><i class="fab fa-google"></i></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-white">Google image</span>
                        <span class="mt-1 block text-xs leading-5 text-slate-400">Abre Google Imágenes con la búsqueda del producto en una pestaña nueva.</span>
                    </span>
                </button>
                <button type="button" @click="openDirectoryPicker()" class="source-action-card flex items-start gap-4 rounded-2xl px-4 py-4 text-left">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-amber-500/14 text-amber-300"><i class="fas fa-folder-open"></i></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-white">Directorio</span>
                        <span class="mt-1 block text-xs leading-5 text-slate-400">Elegí una imagen guardada en este equipo y súbila al producto.</span>
                    </span>
                </button>
                <button type="button" @click="openCameraPicker()" class="source-action-card flex items-start gap-4 rounded-2xl px-4 py-4 text-left">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-fuchsia-500/14 text-fuchsia-300"><i class="fas fa-camera"></i></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-white">Camara</span>
                        <span class="mt-1 block text-xs leading-5 text-slate-400">Captura una foto desde el teléfono o tablet y guárdala al instante.</span>
                    </span>
                </button>
            </div>
        </div>
    </div>

    <input x-ref="directoryInput" type="file" accept="image/*" class="hidden" @change="handleDirectoryFile($event)">
    <input x-ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="handleCameraFile($event)">

    <div x-show="showBingPicker" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/78 p-3 sm:items-center">
        <div @click.outside="closeBingPicker()" class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-[28px] border border-slate-700 bg-slate-950/95 shadow-2xl shadow-black/50">
            <div class="flex items-start justify-between gap-4 border-b soft-divider px-5 py-4">
                <div class="min-w-0">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">Catalogo Bing</div>
                    <div class="mt-2 text-lg font-bold text-white">Seleccioná una imagen del catalogo</div>
                    <div class="mt-1 truncate text-sm text-slate-400" x-text="imageSourceProduct?.desproducto || ''"></div>
                </div>
                <button type="button" @click="closeBingPicker()" class="rounded-full border border-slate-700 p-3 text-slate-400 transition hover:border-slate-500 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-5 py-5">
                <div x-show="bingSearching" class="flex min-h-[280px] items-center justify-center">
                    <div class="text-center text-slate-400">
                        <i class="fas fa-spinner fa-spin text-3xl"></i>
                        <div class="mt-3 text-sm">Buscando imagenes en Bing catalogo...</div>
                    </div>
                </div>
                <div x-show="!bingSearching && bingResults.length === 0" x-cloak class="flex min-h-[280px] items-center justify-center rounded-[22px] border border-slate-800 bg-slate-950/40 text-center">
                    <div>
                        <div class="text-4xl text-slate-600"><i class="fas fa-image"></i></div>
                        <div class="mt-3 text-base font-semibold text-white">No se encontraron candidatos</div>
                        <div class="mt-1 text-sm text-slate-500">Probá con Directorio o Google image para este producto.</div>
                    </div>
                </div>
                <div x-show="!bingSearching && bingResults.length > 0" x-cloak class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <template x-for="(image, index) in bingResults" :key="'bing-result-' + index">
                        <button type="button" class="bing-result-card overflow-hidden rounded-[22px] text-left" @click="selectBingCatalogImage(image)">
                            <div class="aspect-[4/3] overflow-hidden bg-slate-900">
                                <img :src="image.thumb_url || image.image_url" alt="" class="h-full w-full object-cover">
                            </div>
                            <div class="space-y-2 px-4 py-4">
                                <div class="text-sm font-semibold text-white">Usar esta imagen</div>
                                <div class="line-clamp-2 text-xs leading-5 text-slate-400" x-text="image.query || ''"></div>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showProductDetailModal" x-cloak class="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto bg-slate-950/82 p-4 pt-8 backdrop-blur-sm">
        <div x-show="showProductDetailModal"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95 translate-y-3"
             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
             x-transition:leave-end="opacity-0 scale-95 translate-y-3"
             @click.outside="closeProductDetail()"
             class="my-auto flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-[30px] border border-slate-700 bg-slate-950 shadow-2xl shadow-black/50">
            <div class="flex items-start justify-between gap-4 border-b border-slate-800 bg-gradient-to-r from-cyan-600 to-blue-700 px-6 py-5">
                <div class="min-w-0">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-100/80">Detalle TPV</div>
                    <h2 class="mt-2 truncate text-2xl font-black text-white" x-text="productDetail?.producto?.descripcion || 'Producto'"></h2>
                    <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-cyan-50/85">
                        <span x-text="'Cód: ' + (productDetail?.producto?.codigo || '-')"></span>
                        <span>•</span>
                        <span x-text="productDetail?.producto?.categoria || 'Sin categoría'"></span>
                    </div>
                </div>
                <button type="button" @click="closeProductDetail()" class="rounded-full border border-white/20 p-3 text-white/80 transition hover:border-white/40 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-5">
                <div x-show="loadingDetail" class="flex min-h-[320px] items-center justify-center">
                    <div class="text-center text-slate-400">
                        <i class="fas fa-spinner fa-spin text-4xl"></i>
                        <div class="mt-3 text-sm">Cargando detalle del producto...</div>
                    </div>
                </div>

                <div x-show="!loadingDetail && productDetail" x-cloak class="space-y-6">
                    <div class="grid gap-6 lg:grid-cols-[280px,minmax(0,1fr),320px]">
                        <div class="detail-panel rounded-[24px] p-4">
                            <div class="flex min-h-[240px] items-center justify-center overflow-hidden rounded-[22px] bg-slate-900/80 p-4">
                                <template x-if="getDetailSelectedImageUrl()">
                                    <img :src="getDetailSelectedImageUrl()" :alt="productDetail?.producto?.descripcion || 'Producto'" class="max-h-[220px] w-full object-contain">
                                </template>
                                <template x-if="!getDetailSelectedImageUrl()">
                                    <div class="text-slate-600"><i class="fas fa-cube text-6xl"></i></div>
                                </template>
                            </div>
                            <div x-show="getDetailGalleryImages().length > 1" class="mt-4 flex gap-2 overflow-x-auto pb-1">
                                <template x-for="(img, idx) in getDetailGalleryImages()" :key="'detail-img-' + idx">
                                    <button type="button"
                                            @click="selectDetailImage(img)"
                                            class="h-16 w-16 flex-shrink-0 overflow-hidden rounded-xl border bg-slate-900"
                                            :class="isSelectedDetailImage(img) ? 'border-cyan-400 ring-2 ring-cyan-400/35' : 'border-slate-700'">
                                        <img :src="withDetailImageVersion(img.thumb_url || img.url || '')" alt="" class="h-full w-full object-cover">
                                    </button>
                                </template>
                            </div>
                        </div>

                        <div class="detail-panel rounded-[24px] p-4">
                            <h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">Informacion</h3>
                            <div class="mt-4 grid gap-3 text-sm text-slate-200 sm:grid-cols-2">
                                <div class="flex justify-between gap-3 rounded-xl bg-slate-900/60 px-3 py-3"><span class="text-slate-400">Código</span><span class="font-mono text-cyan-300" x-text="productDetail?.producto?.codigo || '-'"></span></div>
                                <div class="flex justify-between gap-3 rounded-xl bg-slate-900/60 px-3 py-3"><span class="text-slate-400">IVA</span><span x-text="formatDetailIva(productDetail?.producto?.tasa_iva)"></span></div>
                                <div class="flex justify-between gap-3 rounded-xl bg-slate-900/60 px-3 py-3" x-show="productDetail?.producto?.marca_nombre"><span class="text-slate-400">Marca</span><span x-text="productDetail?.producto?.marca_nombre"></span></div>
                                <div class="flex justify-between gap-3 rounded-xl bg-slate-900/60 px-3 py-3" x-show="productDetail?.producto?.modelo_nombre"><span class="text-slate-400">Modelo</span><span x-text="productDetail?.producto?.modelo_nombre"></span></div>
                                <div class="flex justify-between gap-3 rounded-xl bg-slate-900/60 px-3 py-3"><span class="text-slate-400">Stock mín.</span><span x-text="formatNumber(productDetail?.producto?.stock_minimo || 0)"></span></div>
                                <div class="flex justify-between gap-3 rounded-xl bg-slate-900/60 px-3 py-3"><span class="text-slate-400">Stock máx.</span><span x-text="formatNumber(productDetail?.producto?.stock_maximo || 0)"></span></div>
                            </div>
                            <div x-show="productDetail?.codigos_barra?.length > 0" class="mt-4">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Códigos de barra</div>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="cb in productDetail?.codigos_barra || []" :key="'cb-' + cb">
                                        <span class="rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 font-mono text-xs text-slate-300" x-text="cb"></span>
                                    </template>
                                </div>
                            </div>
                            <div class="mt-5">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Precios habilitados</div>
                                <div class="space-y-2">
                                    <template x-for="precio in productDetail?.precios || []" :key="'precio-' + precio.tipo">
                                        <div class="flex items-center justify-between rounded-xl bg-slate-900/60 px-3 py-3 text-sm">
                                            <span x-text="precio.nombre_tipo || ('Precio ' + precio.tipo)"></span>
                                            <span class="font-bold text-emerald-300" x-text="formatNumber(precio.precio || 0) + ' Gs'"></span>
                                        </div>
                                    </template>
                                    <div x-show="!productDetail?.precios?.length" class="rounded-xl bg-slate-900/60 px-3 py-3 text-sm text-slate-500">Sin precios definidos</div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <div class="detail-panel rounded-[24px] p-4">
                                <div class="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">Stock por sucursal</div>
                                <div class="space-y-2">
                                    <template x-for="suc in productDetail?.stock_por_sucursal || []" :key="'suc-' + suc.id_sucursal">
                                        <div class="flex items-center justify-between rounded-xl bg-slate-900/60 px-3 py-3">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-semibold text-white" x-text="suc.nombre_sucursal"></div>
                                                <div class="text-xs text-slate-500" x-text="suc.ciudad || ''"></div>
                                            </div>
                                            <div class="text-lg font-bold" :class="Number(suc.stock || 0) > 0 ? 'text-cyan-300' : 'text-rose-300'" x-text="formatNumber(suc.stock || 0)"></div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="detail-panel rounded-[24px] p-4">
                                <div class="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">Productos equivalentes</div>
                                <div class="space-y-2">
                                    <template x-for="eq in productDetail?.equivalentes || []" :key="'eq-' + eq.id">
                                        <div class="rounded-xl bg-slate-900/60 px-3 py-3">
                                            <div class="line-clamp-2 text-sm font-semibold text-white" x-text="eq.descripcion"></div>
                                            <div class="mt-1 text-xs text-slate-500" x-text="'Cód: ' + (eq.codigo || '-')"></div>
                                            <div class="mt-2 flex items-center justify-between text-xs">
                                                <span class="text-slate-500" x-text="'Stock: ' + formatNumber(eq.stock || 0)"></span>
                                                <span class="font-bold text-emerald-300" x-text="formatNumber(eq.precio_venta || 0) + ' Gs'"></span>
                                            </div>
                                            <div class="mt-3 flex justify-end">
                                                <button type="button"
                                                        @click="handleEquivalentPrimaryAction(eq)"
                                                        class="rounded-xl bg-cyan-500 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-cyan-400">
                                                    <span x-text="ui.add_to_cart || 'Agregar al carrito'"></span>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="!productDetail?.equivalentes?.length" class="rounded-xl bg-slate-900/60 px-3 py-3 text-sm text-slate-500">No hay productos equivalentes</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="detail-panel rounded-[24px] p-4">
                        <div class="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">Histórico de venta</div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-xs uppercase tracking-[0.18em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2">Cliente</th>
                                        <th class="px-3 py-2 text-right">Fecha</th>
                                        <th class="px-3 py-2 text-right">Cant.</th>
                                        <th class="px-3 py-2 text-right">Precio</th>
                                        <th class="px-3 py-2 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="cli in productDetail?.clientes_compraron || []" :key="'cli-' + cli.id_cliente + '-' + cli.fecha">
                                        <tr class="border-t border-slate-800">
                                            <td class="px-3 py-3 align-top">
                                                <div class="font-semibold text-white" x-text="cli.cliente_nombre"></div>
                                                <div class="text-xs text-slate-500" x-text="cli.cliente_ruc"></div>
                                            </td>
                                            <td class="px-3 py-3 text-right text-slate-400" x-text="formatDate(cli.fecha)"></td>
                                            <td class="px-3 py-3 text-right text-slate-300" x-text="formatNumber(cli.cantidad || 0)"></td>
                                            <td class="px-3 py-3 text-right text-slate-300" x-text="formatNumber(cli.precio || 0)"></td>
                                            <td class="px-3 py-3 text-right font-semibold text-cyan-300" x-text="formatNumber(cli.importe || 0)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <div x-show="!productDetail?.clientes_compraron?.length" class="px-3 py-6 text-center text-sm text-slate-500">No hay historial de ventas</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-800 bg-slate-950/95 px-6 py-4">
                <button type="button" @click="closeProductDetail()" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cerrar</button>
                <button type="button" @click="handleDetailPrimaryAction()" class="rounded-xl bg-cyan-500 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-cyan-400">
                    <span x-text="modalMode ? (ui.add_to_cart || 'Agregar al carrito') : (ui.view_details || 'Ver detalles')"></span>
                </button>
            </div>
        </div>
    </div>

    <script>
    function searchLandingApp() {
        return {
            idEmpresa: <?= (int)$id_empresa ?>,
            query: <?= json_encode($initialSearch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            selectedGroup: <?= json_encode($initialGroup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            selectedBrand: <?= json_encode($initialBrand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            sort: <?= json_encode(in_array($initialSort, ['relevance', 'price_asc', 'price_desc'], true) ? $initialSort : 'relevance', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            modalMode: <?= $modalMode ? 'true' : 'false' ?>,
            returnUrl: <?= json_encode($returnUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            referrerUrl: <?= json_encode($sameOriginReferer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            ui: window.__SEARCH_DARK_UI__ || {},
            intlLocale: window.__SEARCH_DARK_LOCALE__ || 'es',
            view: 'grid',
            page: 1,
            perPage: 18,
            total: 0,
            totalPages: 1,
            loading: true,
            imageCapturingId: null,
            showImageSourceMenu: false,
            imageSourceProduct: null,
            showBingPicker: false,
            bingSearching: false,
            bingResults: [],
            showProductDetailModal: false,
            loadingDetail: false,
            productDetail: null,
            selectedDetailImage: null,
            currentProductId: 0,
            products: [],
            categoryFacets: [],
            groupOptions: [],
            brandOptions: [],
            navGroups: [],

            async init() {
                await this.loadCatalogs();
                await this.applyFilters();
            },

            goBackOrClose() {
                try {
                    if (window.parent && window.parent !== window && typeof window.parent.cerrarApp === 'function') {
                        window.parent.cerrarApp();
                        return;
                    }
                } catch (_) {}

                try {
                    if (window.opener && !window.opener.closed) {
                        window.opener.postMessage({ type: 'smx:search-dark:closing' }, window.location.origin);
                        try { window.opener.focus(); } catch (_) {}
                    }
                } catch (_) {}

                const fallback = String(this.returnUrl || this.referrerUrl || '/public/menu/menu.php').trim() || '/public/menu/menu.php';
                const closeTarget = window.location.href;
                try {
                    window.close();
                } catch (_) {}
                setTimeout(() => {
                    if (window.closed) return;
                    if (window.history.length > 1 && !this.modalMode) {
                        try {
                            window.history.back();
                            return;
                        } catch (_) {}
                    }
                    if (window.location.href === closeTarget) {
                        window.location.href = fallback;
                    }
                }, 120);
            },

            async loadCatalogs() {
                const [gruposRes, marcasRes] = await Promise.all([
                    fetch(`/public/productos/api/catalogos.php?action=list&tabla=grupos&id_empresa=${this.idEmpresa}`),
                    fetch(`/public/productos/api/catalogos.php?action=list&tabla=marcas&id_empresa=${this.idEmpresa}`)
                ]);
                const grupos = await gruposRes.json().catch(() => ({}));
                const marcas = await marcasRes.json().catch(() => ({}));
                this.groupOptions = Array.isArray(grupos?.data) ? grupos.data : [];
                this.brandOptions = Array.isArray(marcas?.data) ? marcas.data : [];
                this.navGroups = this.groupOptions.slice(0, 8);
            },

            async applyFilters() {
                this.loading = true;
                const sortMap = {
                    relevance: { sort_by: 'desproducto', sort_dir: 'ASC' },
                    price_asc: { sort_by: 'precio_venta', sort_dir: 'ASC' },
                    price_desc: { sort_by: 'precio_venta', sort_dir: 'DESC' }
                };
                const sortCfg = sortMap[this.sort] || sortMap.relevance;
                try {
                    const params = new URLSearchParams({
                        id_empresa: String(this.idEmpresa),
                        page: String(this.page),
                        per_page: String(this.perPage),
                        search: String(this.query || '').trim(),
                        grupo: String(this.selectedGroup || ''),
                        marca: String(this.selectedBrand || ''),
                        estado: '1',
                        fast: '1',
                        with_stats: this.modalMode ? '0' : '1',
                        sort_by: sortCfg.sort_by,
                        sort_dir: sortCfg.sort_dir
                    });
                    const res = await fetch(`/public/productos/api/list.php?${params.toString()}`, { cache: 'no-store' });
                    const data = await res.json();
                    this.products = Array.isArray(data?.data) ? data.data.map((p) => ({
                        ...p,
                        price_view: this.resolveProductPrice(p)
                    })) : [];
                    this.total = Number(data?.pagination?.total || 0);
                    this.totalPages = Number(data?.pagination?.total_pages || 1);
                    this.buildCategoryFacets(this.products);
                    this.syncUrl();
                } catch (error) {
                    console.error('Error loading products search landing:', error);
                    this.products = [];
                    this.total = 0;
                    this.totalPages = 1;
                    this.categoryFacets = [];
                } finally {
                    this.loading = false;
                }
            },

            openImageSourceMenu(product) {
                if (!product?.idproducto) return;
                this.imageSourceProduct = product;
                this.showImageSourceMenu = true;
            },

            closeImageSourceMenu() {
                this.showImageSourceMenu = false;
                this.imageSourceProduct = null;
            },

            async openBingCatalogPicker(product) {
                const descripcion = String(product?.desproducto || '').trim();
                if (!product?.idproducto || !descripcion) return;
                this.showImageSourceMenu = false;
                this.showBingPicker = true;
                this.bingSearching = true;
                this.bingResults = [];
                try {
                    const params = new URLSearchParams({
                        action: 'search_bing_catalog',
                        q: descripcion,
                        id_empresa: String(this.idEmpresa),
                        limit: '12'
                    });
                    const res = await fetch(`/public/pos/api/imagen_proxy.php?${params.toString()}`, { cache: 'no-store' });
                    const data = await res.json().catch(() => ({}));
                    this.bingResults = Array.isArray(data?.images) ? data.images : [];
                } catch (error) {
                    console.error('openBingCatalogPicker error:', error);
                    this.bingResults = [];
                } finally {
                    this.bingSearching = false;
                }
            },

            closeBingPicker() {
                this.showBingPicker = false;
                this.bingSearching = false;
                this.bingResults = [];
            },

            async openProductDetail(product) {
                const idProducto = Number(product?.idproducto || product?.id || 0);
                if (!idProducto) return;
                this.showProductDetailModal = true;
                this.loadingDetail = true;
                this.productDetail = null;
                this.selectedDetailImage = null;
                this.currentProductId = idProducto;
                try {
                    const res = await fetch(`/public/pos/api/producto_detalle.php?id=${encodeURIComponent(idProducto)}&id_empresa=${encodeURIComponent(this.idEmpresa)}`, { cache: 'no-store' });
                    const data = await res.json().catch(() => ({}));
                    if (!data?.success && !data?.producto) {
                        throw new Error(data?.error || 'No se pudo cargar el detalle del producto');
                    }
                    this.productDetail = data;
                    this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                } catch (error) {
                    console.error('openProductDetail error:', error);
                    this.productDetail = null;
                } finally {
                    this.loadingDetail = false;
                }
            },

            closeProductDetail() {
                this.showProductDetailModal = false;
                this.loadingDetail = false;
                this.productDetail = null;
                this.selectedDetailImage = null;
                this.currentProductId = 0;
            },

            openDirectoryPicker() {
                if (!this.imageSourceProduct?.idproducto) return;
                this.$refs.directoryInput?.click();
            },

            openCameraPicker() {
                if (!this.imageSourceProduct?.idproducto) return;
                this.$refs.cameraInput?.click();
            },

            async handleDirectoryFile(event) {
                await this.handlePickedFile(event, false);
            },

            async handleCameraFile(event) {
                await this.handlePickedFile(event, true);
            },

            async handlePickedFile(event, closeMenuAfterPick) {
                const input = event?.target;
                const file = input?.files?.[0] || null;
                if (!file || !this.imageSourceProduct?.idproducto) {
                    if (input) input.value = '';
                    return;
                }
                try {
                    await this.uploadPickedImage(this.imageSourceProduct, file);
                    if (closeMenuAfterPick) {
                        this.closeImageSourceMenu();
                    }
                } finally {
                    if (input) input.value = '';
                }
            },

            async uploadPickedImage(product, file) {
                const idProducto = Number(product?.idproducto || 0);
                if (!idProducto || !(file instanceof File)) return;
                this.imageCapturingId = idProducto;
                try {
                    const formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('idproducto', String(idProducto));
                    formData.append('id_empresa', String(this.idEmpresa));
                    formData.append('imagen', file, file.name || `producto_${idProducto}.jpg`);
                    const saveRes = await fetch('/public/productos/api/imagen.php', {
                        method: 'POST',
                        body: formData
                    });
                    const saveData = await saveRes.json();
                    if (!saveData?.success) {
                        throw new Error(saveData?.error || 'No se pudo guardar la imagen');
                    }
                    this.applyProductImageUpdate(idProducto, saveData?.data || {});
                    this.closeImageSourceMenu();
                } catch (error) {
                    console.error('uploadPickedImage error:', error);
                } finally {
                    this.imageCapturingId = null;
                }
            },

            openGoogleImageSearch(product) {
                const descripcion = String(product?.desproducto || '').trim();
                if (!descripcion) return;
                const searchUrl = `https://www.google.com/search?tbm=isch&q=${encodeURIComponent(descripcion)}`;
                window.open(searchUrl, '_blank', 'noopener');
            },

            async selectBingCatalogImage(image) {
                const product = this.imageSourceProduct;
                const imageUrl = String(image?.image_url || '').trim();
                if (!product?.idproducto || !imageUrl) return;
                await this.saveProductImageFromUrl(product, imageUrl);
                this.closeBingPicker();
            },

            async captureFromBingCatalog(product) {
                const idProducto = Number(product?.idproducto || 0);
                const descripcion = String(product?.desproducto || '').trim();
                if (!idProducto || !descripcion) return;
                const proxyPath = `/public/pos/api/imagen_proxy.php?id=${encodeURIComponent(idProducto)}&q=${encodeURIComponent(descripcion)}&prefer=bing_catalog&refresh=1`;
                const imageUrl = `${window.location.origin}${proxyPath}`;
                await this.saveProductImageFromUrl(product, imageUrl);
            },

            async saveProductImageFromUrl(product, imageUrl) {
                const idProducto = Number(product?.idproducto || 0);
                if (!idProducto || !imageUrl) return;
                this.imageCapturingId = idProducto;
                try {
                    this.showImageSourceMenu = false;
                    const saveRes = await fetch('/public/productos/api/imagen.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'upload_url',
                            idproducto: idProducto,
                            id_empresa: this.idEmpresa,
                            image_url: imageUrl
                        })
                    });
                    const saveData = await saveRes.json();
                    if (!saveData?.success) {
                        throw new Error(saveData?.error || 'No se pudo guardar la imagen');
                    }
                    this.applyProductImageUpdate(idProducto, saveData?.data || {});
                } catch (error) {
                    console.error('saveProductImageFromUrl error:', error);
                } finally {
                    this.imageCapturingId = null;
                }
            },

            applyProductImageUpdate(idProducto, data) {
                const variants = data?.variants || {};
                const nextUrl = variants.small || variants.medium || data.url || '';
                const nextMedium = variants.medium || data.url || nextUrl;
                const nextThumb = variants.thumb || nextUrl || nextMedium;
                this.products = (Array.isArray(this.products) ? this.products : []).map((item) => {
                    if (Number(item?.idproducto || 0) !== Number(idProducto || 0)) return item;
                    return {
                        ...item,
                        foto_url: nextUrl || item.foto_url,
                        foto_thumb_url: nextThumb || item.foto_thumb_url || nextUrl,
                        foto_small_url: variants.small || item.foto_small_url || nextUrl,
                        foto_medium_url: nextMedium || item.foto_medium_url || nextUrl,
                        foto_large_url: variants.large || item.foto_large_url || nextMedium || nextUrl,
                    };
                });
            },

            getDetailGalleryImages() {
                const producto = this.productDetail?.producto || {};
                const list = Array.isArray(producto.imagenes) ? producto.imagenes : [];
                if (list.length) return list;
                const fallback = producto.imagen || producto.imagen_url || '';
                return fallback ? [{ url: fallback, thumb_url: fallback, principal: 1 }] : [];
            },

            getDetailSelectedImageUrl() {
                const selected = this.selectedDetailImage || this.getDetailGalleryImages()[0] || null;
                return this.withDetailImageVersion(selected?.url || selected?.thumb_url || '', this.productDetail?.producto?.imagen_updated || '');
            },

            withDetailImageVersion(url, version = '') {
                const baseUrl = String(url || '').trim();
                if (!baseUrl) return '';
                const sep = baseUrl.includes('?') ? '&' : '?';
                return `${baseUrl}${sep}v=${encodeURIComponent(version || '')}`;
            },

            selectDetailImage(image) {
                this.selectedDetailImage = image || null;
            },

            isSelectedDetailImage(image) {
                const current = this.selectedDetailImage || this.getDetailGalleryImages()[0] || null;
                if (!current || !image) return false;
                return String(current.url || current.thumb_url || '') === String(image.url || image.thumb_url || '');
            },

            formatDetailIva(value) {
                const rate = Number(value || 0);
                if (rate <= 0) return 'Exento';
                if (rate === 5 || rate === 10) return `${rate}%`;
                return `${rate}%`;
            },

            handleDetailPrimaryAction() {
                const producto = this.productDetail?.producto || null;
                if (!producto) return;
                const mapped = {
                    idproducto: Number(producto.idproducto || producto.id || this.currentProductId || 0),
                    id: Number(producto.idproducto || producto.id || this.currentProductId || 0),
                    cve_producto: String(producto.codigo || ''),
                    desproducto: String(producto.descripcion || 'Producto'),
                    price_view: Number(this.productDetail?.precios?.[0]?.precio || producto.precio_venta || 0),
                    precio_venta: Number(this.productDetail?.precios?.[0]?.precio || producto.precio_venta || 0),
                    saldo: Number(producto.stock_global || 0),
                    iva: Number(producto.tasa_iva || 0),
                    controla_stock: Number(producto.controla_stock ?? 1),
                    vende_sin_stock: Number(producto.vende_sin_stock ?? 0),
                    precio_compra: Number(producto.costo || 0),
                    edita_precio: Number(producto.edita_precio || 0),
                    editable: Number(producto.editable || 0),
                    usaserial: Number(producto.usaserial || 0),
                    foto_url: this.getDetailSelectedImageUrl(),
                    imagen_updated: String(producto.imagen_updated || '')
                };
                this.closeProductDetail();
                this.handlePrimaryAction(mapped);
            },

            handleEquivalentPrimaryAction(eq) {
                if (!eq) return;
                const mapped = {
                    idproducto: Number(eq.id || eq.idproducto || 0),
                    id: Number(eq.id || eq.idproducto || 0),
                    cve_producto: String(eq.codigo || eq.cve_producto || ''),
                    desproducto: String(eq.descripcion || eq.desproducto || 'Producto'),
                    price_view: Number(eq.precio_venta || eq.precio || 0),
                    precio_venta: Number(eq.precio_venta || eq.precio || 0),
                    saldo: Number(eq.stock || eq.saldo || 0),
                    iva: Number(this.productDetail?.producto?.tasa_iva || this.productDetail?.producto?.iva || 3),
                    controla_stock: Number(this.productDetail?.producto?.controla_stock ?? 1),
                    vende_sin_stock: Number(this.productDetail?.producto?.vende_sin_stock ?? 0),
                    precio_compra: Number(eq.precio_compra || this.productDetail?.producto?.costo || 0),
                    edita_precio: Number(this.productDetail?.producto?.edita_precio || 0),
                    editable: Number(this.productDetail?.producto?.editable || 0),
                    usaserial: Number(this.productDetail?.producto?.usaserial || 0),
                    foto_url: '',
                    imagen_updated: ''
                };
                this.closeProductDetail();
                this.handlePrimaryAction(mapped);
            },

            buildCategoryFacets(products) {
                const currentNameById = new Map(this.groupOptions.map((g) => [String(g.id), g.nombre || g.grupo || '']));
                const counts = new Map();
                (Array.isArray(products) ? products : []).forEach((product) => {
                    const id = String(product.grupo_id || product.grupo || product.id_grupo || '');
                    const name = product.grupo_nombre || currentNameById.get(id) || 'Sin categoria';
                    const key = id || name;
                    const current = counts.get(key) || { id: id || key, nombre: name, count: 0 };
                    current.count += 1;
                    counts.set(key, current);
                });
                this.categoryFacets = Array.from(counts.values()).sort((a, b) => b.count - a.count || String(a.nombre).localeCompare(String(b.nombre)));
            },

            selectGroup(id) {
                this.selectedGroup = id ? String(id) : '';
                this.page = 1;
                this.applyFilters();
            },

            clearQuery() {
                this.query = '';
                this.page = 1;
                this.applyFilters();
            },

            clearAll() {
                this.query = '';
                this.selectedGroup = '';
                this.selectedBrand = '';
                this.sort = 'relevance';
                this.page = 1;
                this.applyFilters();
            },

            prevPage() {
                if (this.page <= 1) return;
                this.page -= 1;
                this.applyFilters();
            },

            nextPage() {
                if (this.page >= this.totalPages) return;
                this.page += 1;
                this.applyFilters();
            },

            resolveProductPrice(product) {
                const priceList = Array.isArray(product?.precios) ? product.precios : [];
                if (priceList.length > 0) {
                    const values = priceList.map((p) => Number(p?.precio || 0)).filter((v) => v > 0);
                    if (values.length) return values[0];
                }
                return Number(product?.precio_venta || product?.precio || 0);
            },

            sortLabel() {
                if (this.sort === 'price_asc') return this.ui.sort_price_asc || 'Price ascending';
                if (this.sort === 'price_desc') return this.ui.sort_price_desc || 'Price descending';
                return this.ui.sort_relevance || 'Relevance';
            },

            openProduct(product) {
                const id = Number(product?.idproducto || product?.id || 0);
                if (!id) return;
                window.open(`/public/productos/index.php?edit_id=${encodeURIComponent(id)}&desktop=1`, '_blank', 'noopener');
            },

            handlePrimaryAction(product) {
                if (this.modalMode) {
                    this.sendProductToParent(product);
                    return;
                }
                this.openProduct(product);
            },

            sendProductToParent(product) {
                const payload = {
                    id: Number(product?.idproducto || product?.id || 0),
                    codigo: String(product?.cve_producto || product?.codigo || ''),
                    descripcion: String(product?.desproducto || product?.descripcion || 'Producto'),
                    precio: Number(product?.price_view || product?.precio_venta || product?.precio || 0),
                    stock: Number(product?.saldo || 0),
                    tasa_iva: Number(product?.iva || product?.tasa_iva || 3),
                    controla_stock: Number(product?.controla_stock ?? 1),
                    vende_sin_stock: Number(product?.vende_sin_stock ?? 0),
                    precio_min: Number(product?.precio_compra || 0),
                    edita_precio: Number(product?.edita_precio || 0),
                    editable: Number(product?.editable || 0),
                    usaserial: Number(product?.usaserial || 0),
                    imagen: String(product?.foto_url || product?.imagen || ''),
                    imagen_updated: String(product?.imagen_updated || '')
                };
                try {
                    window.opener?.postMessage({ type: 'smx:add-product-to-cart', product: payload }, window.location.origin);
                    try { window.opener?.focus?.(); } catch (focusError) { /* ignore */ }
                    setTimeout(() => {
                        window.close();
                    }, 120);
                } catch (error) {
                    console.error('Error sending product to parent:', error);
                }
            },

            syncUrl() {
                try {
                    const params = new URLSearchParams(window.location.search);
                    if (String(this.query || '').trim()) params.set('search', String(this.query || '').trim());
                    else params.delete('search');
                    if (this.selectedGroup) params.set('grupo', this.selectedGroup);
                    else params.delete('grupo');
                    if (this.selectedBrand) params.set('marca', this.selectedBrand);
                    else params.delete('marca');
                    if (this.sort !== 'relevance') params.set('sort', this.sort);
                    else params.delete('sort');
                    params.set('desktop', '1');
                    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
                } catch (_) {}
            },

            formatMoney(value) {
                const localeMap = { es: 'es-PY', en: 'en-US', pt: 'pt-BR' };
                return new Intl.NumberFormat(localeMap[this.intlLocale] || 'es-PY').format(Number(value || 0));
            },

            formatNumber(value) {
                const localeMap = { es: 'es-PY', en: 'en-US', pt: 'pt-BR' };
                return new Intl.NumberFormat(localeMap[this.intlLocale] || 'es-PY').format(Number(value || 0));
            },

            formatDate(value) {
                const raw = String(value || '').trim();
                if (!raw) return '-';
                const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
                const date = new Date(normalized);
                if (Number.isNaN(date.getTime())) return raw;
                const localeMap = { es: 'es-PY', en: 'en-US', pt: 'pt-BR' };
                return new Intl.DateTimeFormat(localeMap[this.intlLocale] || 'es-PY', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                }).format(date);
            },

            escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            },

            escapeRegExp(value) {
                return String(value ?? '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            },

            queryTokens() {
                return String(this.query || '')
                    .trim()
                    .split(/\s+/u)
                    .map((token) => token.trim())
                    .filter((token, index, arr) => token.length > 0 && arr.indexOf(token) === index)
                    .slice(0, 8);
            },

            highlightText(value) {
                const raw = String(value ?? '');
                const safe = this.escapeHtml(raw);
                const tokens = this.queryTokens()
                    .map((token) => this.escapeRegExp(this.escapeHtml(token)))
                    .filter(Boolean)
                    .sort((a, b) => b.length - a.length);
                if (!safe || !tokens.length) {
                    return safe;
                }
                const rx = new RegExp(`(${tokens.join('|')})`, 'giu');
                return safe.replace(rx, '<mark class="search-hit">$1</mark>');
            }
        };
    }
    </script>
</body>
</html>
