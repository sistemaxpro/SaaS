<?php
/**
 * API para guardar imagen de producto (R2 + fallback local)
 *
 * POST:
 *  - id_producto
 *  - imagen_url o imagen_base64
 *
 * DELETE:
 *  - id_producto
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../src/Services/R2StorageService.php';
require_once __DIR__ . '/../../../src/Services/ImageVariantService.php';

define('WEBP_QUALITY', 85);
define('IMAGE_SIZE', 600);

function saveBase64AsWebp(string $base64Data, string $destPath): array
{
    if (preg_match('/^data:image\/[^;]+;base64,/', $base64Data)) {
        $base64Data = (string)preg_replace('/^data:image\/[^;]+;base64,/', '', $base64Data);
    }

    $imageData = base64_decode($base64Data, true);
    if ($imageData === false || $imageData === '') {
        return ['success' => false, 'error' => 'Datos base64 inválidos'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->buffer($imageData);

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'], true)) {
        return ['success' => false, 'error' => "Tipo de archivo no soportado: $mimeType"];
    }

    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if (file_exists($destPath)) {
        @chmod($destPath, 0666);
        @unlink($destPath);
    }

    $sourceImage = @imagecreatefromstring($imageData);
    if (!$sourceImage) {
        return ['success' => false, 'error' => 'No se pudo procesar la imagen'];
    }

    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);

    $maxSize = IMAGE_SIZE;
    if ($width > $maxSize || $height > $maxSize) {
        if ($width > $height) {
            $newWidth = $maxSize;
            $newHeight = (int)($height * ($maxSize / $width));
        } else {
            $newHeight = $maxSize;
            $newWidth = (int)($width * ($maxSize / $height));
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($sourceImage);
        $sourceImage = $resized;
    }

    $tempPath = $destPath . '.tmp.' . uniqid('', true);
    $result = imagewebp($sourceImage, $tempPath, WEBP_QUALITY);
    imagedestroy($sourceImage);

    if (!$result || !file_exists($tempPath)) {
        @unlink($tempPath);
        return ['success' => false, 'error' => 'Error guardando imagen WebP'];
    }

    if (file_exists($destPath)) {
        @chmod($destPath, 0666);
        @unlink($destPath);
    }

    if (!rename($tempPath, $destPath)) {
        if (copy($tempPath, $destPath)) {
            @unlink($tempPath);
        } else {
            @unlink($tempPath);
            return ['success' => false, 'error' => 'Error moviendo archivo final'];
        }
    }

    @chmod($destPath, 0664);

    if (!file_exists($destPath)) {
        return ['success' => false, 'error' => 'Archivo no creado'];
    }

    return ['success' => true, 'size' => filesize($destPath)];
}

function downloadAndSaveAsWebp(string $url, string $destPath): array
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

    if (!preg_match('/^https?:\/\//i', $url)) {
        return ['success' => false, 'error' => 'URL debe empezar con http:// o https://'];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL) && !preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
        return ['success' => false, 'error' => 'URL no válida: ' . substr($url, 0, 100)];
    }

    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: image/webp,image/png,image/jpeg,image/*',
            'Referer: https://www.google.com/',
        ],
    ]);

    $imageData = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($imageData)) {
        return ['success' => false, 'error' => "Error descargando imagen: HTTP $httpCode - $error"];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->buffer($imageData);

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'], true)) {
        return ['success' => false, 'error' => "Tipo de archivo no soportado: $mimeType"];
    }

    $sourceImage = @imagecreatefromstring($imageData);
    if (!$sourceImage) {
        return ['success' => false, 'error' => 'No se pudo procesar la imagen'];
    }

    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);

    $maxSize = IMAGE_SIZE;
    if ($width > $maxSize || $height > $maxSize) {
        if ($width > $height) {
            $newWidth = $maxSize;
            $newHeight = (int)($height * ($maxSize / $width));
        } else {
            $newHeight = $maxSize;
            $newWidth = (int)($width * ($maxSize / $height));
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($sourceImage);
        $sourceImage = $resized;
    }

    $tempPath = $destPath . '.tmp.' . uniqid('', true);
    $result = imagewebp($sourceImage, $tempPath, WEBP_QUALITY);
    imagedestroy($sourceImage);

    if (!$result || !file_exists($tempPath)) {
        @unlink($tempPath);
        return ['success' => false, 'error' => 'Error guardando imagen WebP'];
    }

    if (file_exists($destPath)) {
        @chmod($destPath, 0666);
        @unlink($destPath);
    }

    if (!rename($tempPath, $destPath)) {
        if (copy($tempPath, $destPath)) {
            @unlink($tempPath);
        } else {
            @unlink($tempPath);
            return ['success' => false, 'error' => 'Error moviendo archivo final'];
        }
    }

    @chmod($destPath, 0664);

    if (!file_exists($destPath)) {
        return ['success' => false, 'error' => 'Archivo no creado'];
    }

    return ['success' => true, 'size' => filesize($destPath)];
}

function ensureProductoImagenesTable(PDO $pdo, string $dbName): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`producto_imagenes` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `idproducto` INT NOT NULL,
        `drive_file_id` VARCHAR(255) NOT NULL,
        `url` VARCHAR(500) NOT NULL,
        `filename` VARCHAR(255) NULL,
        `orden` INT NOT NULL DEFAULT 1,
        `principal` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_producto` (`idproducto`),
        INDEX `idx_drive_file` (`drive_file_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function updateFotoUrl(PDO $pdo, int $idProducto, ?string $fotoUrl): void
{
    $stmtCol = $pdo->query("SHOW COLUMNS FROM `tblproductos` LIKE 'foto_url'");
    if (!$stmtCol || !$stmtCol->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $stmt = $pdo->prepare("UPDATE tblproductos SET foto_url = :url WHERE idproducto = :id");
    $stmt->execute([
        ':url' => $fotoUrl,
        ':id' => $idProducto,
    ]);
}

function syncProductoImagenPrincipal(PDO $pdo, string $dbName, int $idProducto, string $fileId, string $url, string $filename): void
{
    ensureProductoImagenesTable($pdo, $dbName);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM {$dbName}.producto_imagenes WHERE idproducto = :id")
            ->execute([':id' => $idProducto]);

        $pdo->prepare("INSERT INTO {$dbName}.producto_imagenes (idproducto, drive_file_id, url, filename, orden, principal, created_at)
            VALUES (:idproducto, :file_id, :url, :filename, 1, 1, NOW())")
            ->execute([
                ':idproducto' => $idProducto,
                ':file_id' => $fileId,
                ':url' => $url,
                ':filename' => $filename,
            ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function extractR2KeyFromFileId(string $fileId): ?string
{
    $fileId = trim($fileId);
    if ($fileId === '' || !str_starts_with($fileId, 'r2:')) {
        return null;
    }
    return ltrim(substr($fileId, 3), '/');
}

function buildPrincipalR2Key(int $idEmpresa, string $dbName, int $idProducto): string
{
    $dbSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dbName);
    return 'e' . $idEmpresa . '/p/' . $dbSafe . '/' . $idProducto . '/principal';
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $id_empresa = (int)($_SESSION['id_empresa'] ?? 169);

    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

    $cacheDir = dirname(__DIR__, 2) . "/_lib/file/img/productos_cache/{$dbName}/";
    $productosDir = dirname(__DIR__, 2) . "/_lib/file/img/productos/{$dbName}/";

    if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
    if (!is_dir($productosDir)) mkdir($productosDir, 0777, true);

    $r2Configured = R2StorageService::isConfigured();
    $r2 = null;
    if ($r2Configured) {
        try {
            $r2 = new R2StorageService();
        } catch (Throwable $e) {
            error_log('R2 init failed, usando fallback local: ' . $e->getMessage());
            $r2Configured = false;
        }
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $idProducto = (int)($input['id_producto'] ?? $_POST['id_producto'] ?? 0);
        $imagenUrl = trim((string)($input['imagen_url'] ?? $_POST['imagen_url'] ?? ''));
        $imagenBase64 = trim((string)($input['imagen_base64'] ?? $_POST['imagen_base64'] ?? ''));

        if ($idProducto <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de producto requerido']);
            exit;
        }

        if ($imagenUrl === '' && $imagenBase64 === '') {
            echo json_encode(['success' => false, 'error' => 'Se requiere URL de imagen o imagen pegada']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT idproducto, descripcion FROM tblproductos WHERE idproducto = ?");
        $stmt->execute([$idProducto]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$producto) {
            echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
            exit;
        }

        $filename = $idProducto . '_1.webp';
        $destPath = $productosDir . $filename;

        if (!is_writable($productosDir)) {
            echo json_encode(['success' => false, 'error' => 'Sin permisos de escritura en directorio de imágenes']);
            exit;
        }

        $existingFiles = glob($productosDir . $idProducto . '_*');
        foreach ($existingFiles as $file) {
            if (file_exists($file)) {
                @chmod($file, 0666);
                @unlink($file);
            }
        }

        if (!empty($imagenBase64)) {
            $result = saveBase64AsWebp($imagenBase64, $destPath);
        } else {
            $result = downloadAndSaveAsWebp($imagenUrl, $destPath);
        }

        if (!$result['success']) {
            echo json_encode($result);
            exit;
        }

        $cacheFile = $cacheDir . $idProducto . '.webp';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        $localPath = "/_lib/file/img/productos/{$dbName}/{$filename}";
        $publicUrl = $localPath;
        $fileId = 'local:' . $localPath;

        $variantUrls = [];
        if ($r2Configured && $r2 instanceof R2StorageService) {
            $webpBinary = (string)file_get_contents($destPath);
            if ($webpBinary !== '') {
                $baseKey = buildPrincipalR2Key($id_empresa, $dbName, $idProducto);
                $variants = ImageVariantService::generateWebpVariantsFromBinary($webpBinary);
                foreach ($variants as $variantName => $variantData) {
                    $variantKey = ImageVariantService::r2VariantKey($baseKey, $variantName);
                    $upload = $r2->putObject($variantKey, (string)$variantData['binary'], 'image/webp', [
                        'cache-control' => 'public, max-age=31536000, immutable',
                    ]);
                    $variantUrls[$variantName] = (string)$upload['url'];
                }
                $publicUrl = (string)($variantUrls['medium'] ?? ($variantUrls['large'] ?? $publicUrl));
                $fileId = 'r2:' . $baseKey;
            }
        }

        updateFotoUrl($pdo, $idProducto, $publicUrl);

        try {
            syncProductoImagenPrincipal($pdo, $dbName, $idProducto, $fileId, $publicUrl, $filename);
        } catch (Throwable $e) {
            error_log('syncProductoImagenPrincipal warning: ' . $e->getMessage());
        }

        $response = [
            'success' => true,
            'message' => $r2Configured ? 'Imagen guardada en R2 correctamente' : 'Imagen guardada localmente (R2 no configurado)',
            'imagen' => $publicUrl,
            'variants' => $variantUrls,
            'filename' => $filename,
            'size_kb' => round(((int)$result['size']) / 1024, 2),
            'storage' => $r2Configured ? 'r2' : 'local',
        ];

        if (ob_get_level() > 0) {
            ob_clean();
        }
        echo json_encode($response);
        exit;
    }

    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $idProducto = (int)($input['id_producto'] ?? $_GET['id_producto'] ?? 0);

        if ($idProducto <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de producto requerido']);
            exit;
        }

        $existingFiles = glob($productosDir . $idProducto . '_*');
        foreach ($existingFiles as $file) {
            @unlink($file);
        }

        $cacheFile = $cacheDir . $idProducto . '.webp';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        if ($r2Configured && $r2 instanceof R2StorageService) {
            try {
                ensureProductoImagenesTable($pdo, $dbName);
                $stmtImg = $pdo->prepare("SELECT drive_file_id FROM {$dbName}.producto_imagenes WHERE idproducto = :id ORDER BY principal DESC, id ASC LIMIT 1");
                $stmtImg->execute([':id' => $idProducto]);
                $imgRow = $stmtImg->fetch(PDO::FETCH_ASSOC);
                if ($imgRow && !empty($imgRow['drive_file_id'])) {
                    $key = extractR2KeyFromFileId((string)$imgRow['drive_file_id']);
                    if ($key !== null) {
                        if (!preg_match('/\.[a-z0-9]+$/i', $key)) {
                            foreach (array_keys(ImageVariantService::sizes()) as $variantName) {
                                $r2->deleteObject(ImageVariantService::r2VariantKey($key, $variantName));
                            }
                        } else {
                            $r2->deleteObject($key);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('R2 delete warning: ' . $e->getMessage());
            }
        }

        try {
            ensureProductoImagenesTable($pdo, $dbName);
            $pdo->prepare("DELETE FROM {$dbName}.producto_imagenes WHERE idproducto = :id")
                ->execute([':id' => $idProducto]);
        } catch (Throwable $e) {
            error_log('delete producto_imagenes warning: ' . $e->getMessage());
        }

        updateFotoUrl($pdo, $idProducto, null);

        echo json_encode([
            'success' => true,
            'message' => 'Imagen eliminada correctamente',
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
