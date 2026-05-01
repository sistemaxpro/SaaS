<?php
/**
 * Proxy de avatares — genera SVG determinístico local.
 * No requiere sesión: avatares genéricos no son datos sensibles.
 * Sin fetch externo: sin bloqueos ni dependencias de red.
 */
require_once __DIR__ . '/../../../config/bootstrap.php';

$kind = strtolower(trim((string)($_GET['kind'] ?? 'avatar')));
$seed = abs((int)($_GET['seed'] ?? 0));

// Paletas por tipo
$palettes = [
    'avatar_female' => [
        ['bg' => '#db2777', 'fg' => '#fce7f3'],
        ['bg' => '#7c3aed', 'fg' => '#ede9fe'],
        ['bg' => '#0891b2', 'fg' => '#cffafe'],
    ],
    'avatar_male' => [
        ['bg' => '#1d4ed8', 'fg' => '#dbeafe'],
        ['bg' => '#065f46', 'fg' => '#d1fae5'],
        ['bg' => '#92400e', 'fg' => '#fef3c7'],
    ],
    'company' => [
        ['bg' => '#1e3a5f', 'fg' => '#bfdbfe'],
        ['bg' => '#14532d', 'fg' => '#bbf7d0'],
        ['bg' => '#3b0764', 'fg' => '#e9d5ff'],
    ],
    'avatar' => [
        ['bg' => '#334155', 'fg' => '#cbd5e1'],
        ['bg' => '#1e40af', 'fg' => '#bfdbfe'],
        ['bg' => '#166534', 'fg' => '#bbf7d0'],
        ['bg' => '#9f1239', 'fg' => '#fce7f3'],
        ['bg' => '#7c2d12', 'fg' => '#fed7aa'],
    ],
];

$pool   = $palettes[$kind] ?? $palettes['avatar'];
$colors = $pool[$seed % count($pool)];
$bg     = $colors['bg'];
$fg     = $colors['fg'];

// Inicial / ícono según tipo
$initials = match ($kind) {
    'avatar_female' => ['♀', 'F', '♀'][($seed % 3)],
    'avatar_male'   => ['♂', 'M', '♂'][($seed % 3)],
    'company'       => ['⬡', '◈', '⬢'][($seed % 3)],
    default         => chr(65 + ($seed % 26)),
};

// Forma de fondo: círculo o cuadrado redondeado alternando por seed
$isCircle = ($seed % 2 === 0);
$shape = $isCircle
    ? '<circle cx="64" cy="64" r="60" fill="' . $bg . '"/>'
    : '<rect x="4" y="4" width="120" height="120" rx="28" fill="' . $bg . '"/>';

// Patrón decorativo sutil (puntos en esquinas)
$dot = fn(int $cx, int $cy) => '<circle cx="' . $cx . '" cy="' . $cy . '" r="5" fill="' . $fg . '" opacity="0.25"/>';
$dots = $dot(20, 20) . $dot(108, 20) . $dot(20, 108) . $dot(108, 108);

$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">
  $shape
  $dots
  <text x="64" y="64" text-anchor="middle" dominant-baseline="central"
        font-family="system-ui, Arial, sans-serif"
        font-size="52" font-weight="700"
        fill="$fg">$initials</text>
</svg>
SVG;

// Cache local para consistencia entre recargas
$cacheDir = dirname(__DIR__, 3) . '/public/uploads/cache/avatars';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/' . $kind . '_' . $seed . '.svg';
if (!is_file($cacheFile)) {
    @file_put_contents($cacheFile, $svg, LOCK_EX);
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
echo $svg;
