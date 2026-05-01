<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function routeIconPriority(array $row): int
{
    $icon = strtolower(trim((string)($row['icono'] ?? '')));
    $genericIcons = [
        '',
        'app-window',
        'squares-2x2',
        'cube',
        'question-mark-circle',
    ];

    $score = in_array($icon, $genericIcons, true) ? 0 : 10;
    if (str_starts_with(trim((string)($row['icono_svg'] ?? '')), '<svg')) {
        $score += 5;
    }

    return $score * 10000 - (int)($row['orden'] ?? 9999);
}

$db = Database::getMasterConnection();
$stmt = $db->query("
    SELECT id_app, codigo, nombre, ruta_app, icono, COALESCE(icono_svg, '') AS icono_svg, color, orden
    FROM saas_apps_catalogo
    WHERE activo = 1
      AND COALESCE(ruta_app, '') <> ''
    ORDER BY ruta_app, orden ASC, id_app ASC
");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$byRoute = [];
foreach ($rows as $row) {
    $route = trim((string)$row['ruta_app']);
    if ($route === '') {
        continue;
    }
    $byRoute[$route][] = $row;
}

$update = $db->prepare("
    UPDATE saas_apps_catalogo
    SET icono = ?, icono_svg = ?, color = ?, updated_at = NOW()
    WHERE id_app = ?
");

$updated = 0;
foreach ($byRoute as $route => $items) {
    if (count($items) < 2) {
        continue;
    }

    usort($items, static function (array $a, array $b): int {
        return routeIconPriority($b) <=> routeIconPriority($a);
    });
    $best = $items[0];

    foreach (array_slice($items, 1) as $row) {
        $sameIcon = (string)$row['icono'] === (string)$best['icono'];
        $sameSvg = (string)$row['icono_svg'] === (string)$best['icono_svg'];
        $sameColor = (string)$row['color'] === (string)$best['color'];
        if ($sameIcon && $sameSvg && $sameColor) {
            continue;
        }

        $update->execute([
            (string)$best['icono'],
            (string)$best['icono_svg'],
            (string)$best['color'],
            (int)$row['id_app'],
        ]);
        $updated++;
    }
}

echo "updated={$updated}\n";
