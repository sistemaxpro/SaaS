<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function loadIconMapFromJs(string $path, string $varName): array
{
    if (!is_file($path)) {
        return [];
    }

    $content = (string)file_get_contents($path);
    $pattern = '/window\.' . preg_quote($varName, '/') . '\s*=\s*(\{.*\})\s*;/s';
    if (!preg_match($pattern, $content, $matches)) {
        return [];
    }

    $decoded = json_decode($matches[1], true);
    return is_array($decoded) ? $decoded : [];
}

function resolveCatalogSvg(string $icon, array $heroicons, array $tabler, array $huge, array $material, array $unicons): ?string
{
    $icon = trim($icon);
    if ($icon === '') {
        return null;
    }

    $faAliases = [
        'fa-file-signature' => 'clipboard-document-list',
        'fa-dolly' => 'truck',
        'fa-truck' => 'truck',
        'fa-shopping-cart' => 'shopping-cart',
        'fa-store' => 'building-storefront',
        'fa-cash-register' => 'building-storefront',
        'fa-boxes' => 'cube',
        'fa-box' => 'cube',
        'fa-cube' => 'cube',
        'fa-user-tie' => 'users',
        'fa-users' => 'users',
        'fa-folder' => 'folder-open',
        'fa-folder-open' => 'folder-open',
        'fa-chart-bar' => 'chart-bar',
        'fa-chart-line' => 'chart-bar',
        'fa-gas-pump' => 'fire',
        'fa-cogs' => 'cog-6-tooth',
        'fa-cog' => 'cog-6-tooth',
        'fa-sign-out-alt' => 'arrow-right-on-rectangle',
        'fa-sign-out' => 'arrow-right-on-rectangle',
        'fa-credit-card' => 'credit-card',
        'fa-wrench' => 'wrench-screwdriver',
    ];

    if (str_contains($icon, 'fa-')) {
        $parts = preg_split('/\s+/', $icon) ?: [];
        foreach ($parts as $part) {
            if (str_starts_with($part, 'fa-') && isset($faAliases[$part])) {
                $icon = $faAliases[$part];
                break;
            }
        }
    }

    $aliases = [
        'app-window' => 'squares-2x2',
        'apps-grid' => 'squares-2x2',
        'boxes-stacked' => 'cube',
        'building' => 'building-office',
        'building-community' => 'building-office',
        'warehouse' => 'building-office',
        'file-signature' => 'clipboard-document-list',
        'file-invoice-dollar' => 'credit-card',
        'receipt' => 'credit-card',
        'printer' => 'printer',
        'android' => 'device-phone-mobile',
        'bluetooth' => 'device-phone-mobile',
        'bug' => 'shield-exclamation',
        'map-marker-check' => 'map-pin',
        'map-marker-multiple' => 'map-pin',
        'map' => 'map-pin',
        'images' => 'photo',
        'document-text' => 'clipboard-document-list',
        'document-duplicate' => 'clipboard-document-list',
        'clipboard-document-check' => 'clipboard-document-list',
        'calendar-days' => 'clipboard-document-list',
        'clock' => 'chart-bar',
        'bell' => 'shield-check',
        'magnifying-glass' => 'chart-bar',
        'funnel' => 'chart-bar',
        'tag' => 'credit-card',
        'qr-code' => 'squares-2x2',
        'globe-alt' => 'building-office',
        'map-pin' => 'building-office',
        'phone' => 'user-group',
        'envelope' => 'clipboard-document-list',
        'camera' => 'photo',
        'photo' => 'photo',
        'ticket' => 'credit-card',
        'receipt-percent' => 'credit-card',
        'building-storefront' => 'building-office',
        'chart-pie' => 'chart-bar',
        'presentation-chart-line' => 'chart-bar',
        'user' => 'users',
        'user-circle' => 'users',
        'archive-box' => 'cube',
        'cube-transparent' => 'cube',
        'wrench-screwdriver' => 'wrench-screwdriver',
        'shield-exclamation' => 'shield-check',
        'lock-closed' => 'shield-check',
        'key' => 'key',
        'cog-8-tooth' => 'cog-6-tooth',
        'server-stack' => 'server-stack',
        'cpu-chip' => 'cpu-chip',
        'cloud-arrow-up' => 'cloud-arrow-up',
        'cloud-arrow-down' => 'cloud-arrow-down',
        'arrow-path' => 'arrow-path',
        'arrows-right-left' => 'arrows-right-left',
        'play' => 'play',
        'pause' => 'pause',
        'sparkles' => 'sparkles',
        'bolt' => 'bolt',
    ];

    $candidates = array_values(array_unique(array_filter([
        $icon,
        $aliases[$icon] ?? null,
    ])));

    foreach ($candidates as $candidate) {
        if (isset($tabler[$candidate])) {
            return (string)$tabler[$candidate];
        }
        if (isset($huge[$candidate])) {
            return (string)$huge[$candidate];
        }
        if (isset($material[$candidate])) {
            return (string)$material[$candidate];
        }
        if (isset($unicons[$candidate])) {
            return (string)$unicons[$candidate];
        }
        if (isset($heroicons[$candidate])) {
            return (string)$heroicons[$candidate];
        }
    }

    return null;
}

$heroicons = loadIconMapFromJs(__DIR__ . '/../public/assets/js/heroicons-outline-map.js', 'HEROICONS_OUTLINE_MAP');
$tabler = loadIconMapFromJs(__DIR__ . '/../public/assets/js/tabler-icons-map.js', 'TABLER_ICONS_MAP');
$huge = loadIconMapFromJs(__DIR__ . '/../public/assets/js/hugeicons-map.js', 'HUGEICONS_MAP');
$material = loadIconMapFromJs(__DIR__ . '/../public/assets/js/material-symbols-map.js', 'MATERIAL_SYMBOLS_MAP');
$unicons = loadIconMapFromJs(__DIR__ . '/../public/assets/js/unicons-map.js', 'UNICONS_MAP');

$db = Database::getMasterConnection();
$stmt = $db->query('SELECT id_app, codigo, icono, COALESCE(icono_svg, "") AS icono_svg FROM saas_apps_catalogo');
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$updated = 0;
$skipped = 0;
$unresolved = [];
$updateStmt = $db->prepare('UPDATE saas_apps_catalogo SET icono_svg = ?, updated_at = NOW() WHERE id_app = ?');

foreach ($rows as $row) {
    $currentSvg = trim((string)($row['icono_svg'] ?? ''));
    if ($currentSvg !== '' && str_starts_with($currentSvg, '<svg')) {
        $skipped++;
        continue;
    }

    $resolvedSvg = resolveCatalogSvg((string)($row['icono'] ?? ''), $heroicons, $tabler, $huge, $material, $unicons);
    if ($resolvedSvg === null || $resolvedSvg === '') {
        $unresolved[] = [
            'codigo' => (string)($row['codigo'] ?? ''),
            'icono' => (string)($row['icono'] ?? ''),
        ];
        continue;
    }

    $updateStmt->execute([$resolvedSvg, (int)$row['id_app']]);
    $updated++;
}

echo "updated={$updated}\n";
echo "skipped={$skipped}\n";
if ($unresolved !== []) {
    echo "unresolved:\n";
    foreach ($unresolved as $item) {
        echo $item['codigo'] . "\t" . $item['icono'] . "\n";
    }
}
