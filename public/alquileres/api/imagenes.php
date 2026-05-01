<?php

require_once __DIR__ . '/common.php';

function smxAlqEnsurePropertyExists(PDO $pdo, string $db, int $idPropiedad): array
{
    $stmt = $pdo->prepare("SELECT * FROM `{$db}`.`alq_propiedades` WHERE id_propiedad = ? LIMIT 1");
    $stmt->execute([$idPropiedad]);
    $prop = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prop) {
        smxAlqJson(['ok' => false, 'error' => 'Propiedad no encontrada'], 404);
    }
    return $prop;
}

function smxAlqNextImageOrder(PDO $pdo, string $db, int $idPropiedad): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(orden), 0) FROM `{$db}`.`alq_propiedad_imagenes` WHERE id_propiedad = ?");
    $stmt->execute([$idPropiedad]);
    return max(1, ((int)($stmt->fetchColumn() ?: 0)) + 1);
}

function smxAlqImageRowById(PDO $pdo, string $db, int $idImagen): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM `{$db}`.`alq_propiedad_imagenes` WHERE id_imagen = ? LIMIT 1");
    $stmt->execute([$idImagen]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function smxAlqNormalizeImageSource(string $value): string
{
    $value = strtolower(trim($value));
    $allowed = ['local', 'google', 'bing', 'pixabay', 'pexels', 'web'];
    if (!in_array($value, $allowed, true)) {
        return 'web';
    }
    return $value;
}

function smxAlqImageLoadResource(string $path)
{
    $info = @getimagesize($path);
    if (!$info || empty($info['mime'])) {
        return null;
    }

    return match ($info['mime']) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/gif' => @imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : @imagecreatefromstring((string)@file_get_contents($path)),
        'image/bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : @imagecreatefromstring((string)@file_get_contents($path)),
        'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : @imagecreatefromstring((string)@file_get_contents($path)),
        default => null,
    };
}

function smxAlqImageSaveWebp($image, string $path, int $quality = 84): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    $dir = dirname($path);
    smxAlqEnsureDirectory($dir);
    $tmp = $path . '.tmp.' . uniqid('', true);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $ok = @imagewebp($image, $tmp, $quality);
    if (!$ok || !file_exists($tmp)) {
        @unlink($tmp);
        return false;
    }
    if (file_exists($path)) {
        @unlink($path);
    }
    if (!@rename($tmp, $path)) {
        if (!@copy($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @unlink($tmp);
    }
    @chmod($path, 0664);
    return true;
}

function smxAlqCreateCoverImage(string $sourcePath, string $destPath, int $width = 1280, int $height = 720): bool
{
    $src = smxAlqImageLoadResource($sourcePath);
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return false;
    }

    $targetRatio = $width / max(1, $height);
    $srcRatio = $srcW / max(1, $srcH);
    if ($srcRatio > $targetRatio) {
        $cropW = (int)round($srcH * $targetRatio);
        $cropH = $srcH;
        $srcX = (int)floor(($srcW - $cropW) / 2);
        $srcY = 0;
    } else {
        $cropW = $srcW;
        $cropH = (int)round($srcW / $targetRatio);
        $srcX = 0;
        $srcY = (int)floor(($srcH - $cropH) / 2);
    }

    $dst = imagecreatetruecolor($width, $height);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);
    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $width, $height, $cropW, $cropH);
    imagedestroy($src);

    $ok = smxAlqImageSaveWebp($dst, $destPath);
    imagedestroy($dst);
    return $ok;
}

function smxAlqReindexImageOrders(PDO $pdo, string $db, int $idPropiedad): void
{
    $stmt = $pdo->prepare("
        SELECT id_imagen
        FROM `{$db}`.`alq_propiedad_imagenes`
        WHERE id_propiedad = ?
        ORDER BY principal DESC, orden ASC, id_imagen ASC
    ");
    $stmt->execute([$idPropiedad]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $order = 1;
    $upd = $pdo->prepare("UPDATE `{$db}`.`alq_propiedad_imagenes` SET orden = ? WHERE id_imagen = ?");
    foreach ($rows as $idImagen) {
        $upd->execute([$order++, (int)$idImagen]);
    }
}

function smxAlqEnsureOnePrimary(PDO $pdo, string $db, int $idPropiedad): void
{
    $stmt = $pdo->prepare("
        SELECT id_imagen
        FROM `{$db}`.`alq_propiedad_imagenes`
        WHERE id_propiedad = ?
        ORDER BY principal DESC, orden ASC, id_imagen ASC
        LIMIT 1
    ");
    $stmt->execute([$idPropiedad]);
    $idImagen = (int)($stmt->fetchColumn() ?: 0);
    if ($idImagen <= 0) {
        return;
    }

    $pdo->prepare("UPDATE `{$db}`.`alq_propiedad_imagenes` SET principal = 0 WHERE id_propiedad = ?")->execute([$idPropiedad]);
    $pdo->prepare("UPDATE `{$db}`.`alq_propiedad_imagenes` SET principal = 1 WHERE id_imagen = ?")->execute([$idImagen]);
}

function smxAlqHandleUpload(PDO $pdo, string $db, int $idPropiedad, array $files): array
{
    $prop = smxAlqEnsurePropertyExists($pdo, $db, $idPropiedad);
    $targetDir = smxAlqPropertyImagesDir($db, $idPropiedad);
    smxAlqEnsureDirectory($targetDir);

    if (!is_writable($targetDir)) {
        return ['ok' => false, 'error' => 'No hay permisos para guardar imágenes'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = [];
    $hasPrimary = (int)($pdo->query("SELECT COUNT(*) FROM `{$db}`.`alq_propiedad_imagenes` WHERE id_propiedad = {$idPropiedad} AND principal = 1")->fetchColumn() ?: 0) > 0;
    $nextOrder = smxAlqNextImageOrder($pdo, $db, $idPropiedad);

    $list = [];
    if (isset($files['name']) && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $list[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
    } elseif (!empty($files['name'])) {
        $list[] = $files;
    }

    if (empty($list)) {
        return ['ok' => false, 'error' => 'No se recibieron archivos'];
    }

    $allow = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/avif' => 'avif',
    ];

    $pdo->beginTransaction();
    try {
        foreach ($list as $index => $file) {
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }
            $mime = (string)$finfo->file($tmp);
            if (!isset($allow[$mime])) {
                continue;
            }
            $ext = $allow[$mime];
            $nameBase = 'prop_' . $idPropiedad . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $destPath = $targetDir . $nameBase;
            if (!move_uploaded_file($tmp, $destPath)) {
                continue;
            }
            @chmod($destPath, 0664);

            $publicUrl = smxAlqPropertyImagePublicUrl($db, $idPropiedad, $nameBase);
            $coverName = pathinfo($nameBase, PATHINFO_FILENAME) . '_cover.webp';
            $coverPath = $targetDir . $coverName;
            $coverOk = smxAlqCreateCoverImage($destPath, $coverPath, 1280, 720);
            $coverUrl = $coverOk ? smxAlqPropertyImagePublicUrl($db, $idPropiedad, $coverName) : $publicUrl;
            $principal = $hasPrimary ? 0 : 1;
            $stmt = $pdo->prepare("
                INSERT INTO `{$db}`.`alq_propiedad_imagenes`
                    (id_propiedad, origen, fuente, titulo, url_original, url_preview, url_cover, archivo_local, principal, orden)
                VALUES
                    (?, 'local', 'local', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $idPropiedad,
                (string)($prop['nombre'] ?? 'Imagen de propiedad'),
                $publicUrl,
                $publicUrl,
                $coverUrl,
                $nameBase,
                $principal,
                $nextOrder++,
            ]);
            $saved[] = $nameBase;
            $hasPrimary = true;
        }
        if (empty($saved)) {
            throw new RuntimeException('No se pudo guardar ninguna imagen');
        }
        if ($saved) {
            smxAlqEnsureOnePrimary($pdo, $db, $idPropiedad);
        }
        $pdo->commit();
        return ['ok' => true, 'saved' => count($saved)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($saved as $fileName) {
            $path = $targetDir . $fileName;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

try {
    [$pdo, $db] = smxAlqDb();
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $idPropiedad = (int)($_GET['id_propiedad'] ?? 0);
        if ($idPropiedad <= 0) {
            smxAlqJson(['ok' => false, 'error' => 'Propiedad requerida'], 422);
        }
        smxAlqEnsurePropertyExists($pdo, $db, $idPropiedad);
        $stmt = $pdo->prepare("
            SELECT *,
                   COALESCE(url_cover, url_preview, url_original) AS image_url
            FROM `{$db}`.`alq_propiedad_imagenes`
            WHERE id_propiedad = ?
            ORDER BY principal DESC, orden ASC, id_imagen ASC
        ");
        $stmt->execute([$idPropiedad]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        smxAlqJson(['ok' => true, 'images' => $images]);
    }

    if ($action === 'attach') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idPropiedad = (int)($payload['id_propiedad'] ?? 0);
        $urlOriginal = trim((string)($payload['url_original'] ?? ''));
        $urlPreview = trim((string)($payload['url_preview'] ?? $urlOriginal));
        $origen = smxAlqNormalizeImageSource((string)($payload['origen'] ?? 'web'));
        $fuente = trim((string)($payload['fuente'] ?? $origen));
        $titulo = trim((string)($payload['titulo'] ?? 'Imagen de propiedad'));
        $principal = (int)($payload['principal'] ?? 0) === 1 ? 1 : 0;

        if ($idPropiedad <= 0) {
            smxAlqJson(['ok' => false, 'error' => 'Propiedad requerida'], 422);
        }
        if ($urlOriginal === '' || !preg_match('/^https?:\/\//i', $urlOriginal)) {
            smxAlqJson(['ok' => false, 'error' => 'URL inválida'], 422);
        }
        smxAlqEnsurePropertyExists($pdo, $db, $idPropiedad);

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM `{$db}`.`alq_propiedad_imagenes` WHERE id_propiedad = ?");
        $stmtCount->execute([$idPropiedad]);
        $hasAny = (int)($stmtCount->fetchColumn() ?: 0) > 0;
        $orden = smxAlqNextImageOrder($pdo, $db, $idPropiedad);

        $stmt = $pdo->prepare("
            INSERT INTO `{$db}`.`alq_propiedad_imagenes`
                (id_propiedad, origen, fuente, titulo, url_original, url_preview, url_cover, archivo_local, principal, orden)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
        ");
        $stmt->execute([
            $idPropiedad,
            $origen,
            $fuente !== '' ? substr($fuente, 0, 40) : $origen,
            $titulo !== '' ? $titulo : 'Imagen de propiedad',
            $urlOriginal,
            $urlPreview !== '' ? $urlPreview : $urlOriginal,
            $urlPreview !== '' ? $urlPreview : $urlOriginal,
            $principal || !$hasAny ? 1 : 0,
            $orden,
        ]);

        if ($principal || !$hasAny) {
            smxAlqEnsureOnePrimary($pdo, $db, $idPropiedad);
        }
        smxAlqJson(['ok' => true]);
    }

    if ($action === 'upload') {
        smxAlqRequirePost();
        $idPropiedad = (int)($_POST['id_propiedad'] ?? 0);
        if ($idPropiedad <= 0) {
            smxAlqJson(['ok' => false, 'error' => 'Propiedad requerida'], 422);
        }
        $files = $_FILES['images'] ?? $_FILES['file'] ?? null;
        if (!$files) {
            smxAlqJson(['ok' => false, 'error' => 'Seleccione una o más imágenes'], 422);
        }
        $result = smxAlqHandleUpload($pdo, $db, $idPropiedad, $files);
        smxAlqJson($result, $result['ok'] ? 200 : 500);
    }

    if ($action === 'set_primary') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idImagen = (int)($payload['id_imagen'] ?? 0);
        $image = smxAlqImageRowById($pdo, $db, $idImagen);
        if (!$image) {
            smxAlqJson(['ok' => false, 'error' => 'Imagen no encontrada'], 404);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE `{$db}`.`alq_propiedad_imagenes` SET principal = 0 WHERE id_propiedad = ?")
                ->execute([(int)$image['id_propiedad']]);
            $pdo->prepare("UPDATE `{$db}`.`alq_propiedad_imagenes` SET principal = 1, orden = 1 WHERE id_imagen = ?")
                ->execute([$idImagen]);
            smxAlqReindexImageOrders($pdo, $db, (int)$image['id_propiedad']);
            $pdo->commit();
            smxAlqJson(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    if ($action === 'move') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idImagen = (int)($payload['id_imagen'] ?? 0);
        $direction = strtolower(trim((string)($payload['direction'] ?? 'up')));
        $image = smxAlqImageRowById($pdo, $db, $idImagen);
        if (!$image) {
            smxAlqJson(['ok' => false, 'error' => 'Imagen no encontrada'], 404);
        }
        $idPropiedad = (int)$image['id_propiedad'];
        $stmt = $pdo->prepare("
            SELECT id_imagen, orden
            FROM `{$db}`.`alq_propiedad_imagenes`
            WHERE id_propiedad = ?
            ORDER BY principal DESC, orden ASC, id_imagen ASC
        ");
        $stmt->execute([$idPropiedad]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $index = null;
        foreach ($rows as $i => $row) {
            if ((int)$row['id_imagen'] === $idImagen) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            smxAlqJson(['ok' => false, 'error' => 'Imagen no ubicada'], 404);
        }
        $swapWith = null;
        if ($direction === 'up' && $index > 0) {
            $swapWith = $rows[$index - 1];
        }
        if ($direction === 'down' && $index < count($rows) - 1) {
            $swapWith = $rows[$index + 1];
        }
        if (!$swapWith) {
            smxAlqJson(['ok' => true]);
        }
        $pdo->beginTransaction();
        try {
            $stmtSwap = $pdo->prepare("UPDATE `{$db}`.`alq_propiedad_imagenes` SET orden = ? WHERE id_imagen = ?");
            $stmtSwap->execute([(int)$swapWith['orden'], $idImagen]);
            $stmtSwap->execute([(int)$image['orden'], (int)$swapWith['id_imagen']]);
            smxAlqReindexImageOrders($pdo, $db, $idPropiedad);
            $pdo->commit();
            smxAlqJson(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    if ($action === 'reorder') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idImagen = (int)($payload['id_imagen'] ?? 0);
        $targetId = (int)($payload['target_id'] ?? 0);
        if ($idImagen <= 0 || $targetId <= 0 || $idImagen === $targetId) {
            smxAlqJson(['ok' => false, 'error' => 'Parámetros inválidos'], 422);
        }
        $current = smxAlqImageRowById($pdo, $db, $idImagen);
        $target = smxAlqImageRowById($pdo, $db, $targetId);
        if (!$current || !$target || (int)$current['id_propiedad'] !== (int)$target['id_propiedad']) {
            smxAlqJson(['ok' => false, 'error' => 'Imágenes no compatibles'], 422);
        }

        $idPropiedad = (int)$current['id_propiedad'];
        $stmt = $pdo->prepare("
            SELECT id_imagen
            FROM `{$db}`.`alq_propiedad_imagenes`
            WHERE id_propiedad = ?
            ORDER BY principal DESC, orden ASC, id_imagen ASC
        ");
        $stmt->execute([$idPropiedad]);
        $orderedIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $orderedIds = array_values(array_filter($orderedIds, static fn($v) => (int)$v !== $idImagen));
        $targetIndex = array_search($targetId, $orderedIds, true);
        if ($targetIndex === false) {
            smxAlqJson(['ok' => false, 'error' => 'Objetivo no encontrado'], 404);
        }
        array_splice($orderedIds, (int)$targetIndex, 0, [$idImagen]);

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE `{$db}`.`alq_propiedad_imagenes` SET orden = ? WHERE id_imagen = ?");
            $order = 1;
            foreach ($orderedIds as $imgId) {
                $upd->execute([$order++, (int)$imgId]);
            }
            smxAlqEnsureOnePrimary($pdo, $db, $idPropiedad);
            $pdo->commit();
            smxAlqJson(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    if ($action === 'delete') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idImagen = (int)($payload['id_imagen'] ?? 0);
        $image = smxAlqImageRowById($pdo, $db, $idImagen);
        if (!$image) {
            smxAlqJson(['ok' => false, 'error' => 'Imagen no encontrada'], 404);
        }
        $idPropiedad = (int)$image['id_propiedad'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM `{$db}`.`alq_propiedad_imagenes` WHERE id_imagen = ?")->execute([$idImagen]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $localFile = trim((string)($image['archivo_local'] ?? ''));
        if ($localFile !== '') {
            $path = smxAlqPropertyImagesDir($db, $idPropiedad) . $localFile;
            if (is_file($path)) {
                @unlink($path);
            }
            $coverPath = smxAlqPropertyImagesDir($db, $idPropiedad) . pathinfo($localFile, PATHINFO_FILENAME) . '_cover.webp';
            if (is_file($coverPath)) {
                @unlink($coverPath);
            }
        }

        smxAlqEnsureOnePrimary($pdo, $db, $idPropiedad);
        smxAlqJson(['ok' => true]);
    }

    smxAlqJson(['ok' => false, 'error' => 'Acción no soportada'], 400);
} catch (Throwable $e) {
    smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
