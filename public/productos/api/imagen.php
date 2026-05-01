<?php
/**
 * Productos API — Gestión de Imágenes (R2)
 *
 * GET:    ?action=list&idproducto={id}&id_empresa={id}
 * GET:    ?action=check&id_empresa={id}
 * POST:   action=upload  + multipart file  + idproducto + id_empresa
 * POST:   action=upload_url + JSON { image_url, idproducto, id_empresa }
 * POST:   action=delete  + JSON { file_id, idproducto, id_empresa }
 * POST:   action=set_principal + JSON { file_id, idproducto, id_empresa }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../src/Services/R2StorageService.php';
require_once __DIR__ . '/../../../src/Services/ImageVariantService.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    $idProducto = (int)($_GET['idproducto'] ?? 0);
    $id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
} else {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'multipart/form-data') !== false) {
        $action = $_POST['action'] ?? 'upload';
        $idProducto = (int)($_POST['idproducto'] ?? 0);
        $id_empresa = (int)($_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
    } else {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
        $idProducto = (int)($input['idproducto'] ?? 0);
        $id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
    }
}

if ($id_empresa <= 0) {
    echo json_encode(['success' => false, 'error' => 'id_empresa requerido']);
    exit;
}

$r2Configured = R2StorageService::isConfigured();

if ($action === 'check') {
    echo json_encode([
        'success' => true,
        'configured' => $r2Configured,
        'message' => $r2Configured
            ? 'R2 configurado correctamente'
            : 'R2 no está configurado',
    ]);
    exit;
}

if (!$r2Configured) {
    if ($action === 'list') {
        echo json_encode([
            'success' => true,
            'configured' => false,
            'data' => [],
            'message' => 'R2 no está configurado',
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'configured' => false,
        'error' => 'R2 no está configurado. Defina variables R2_* en el servidor.',
    ]);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $r2 = new R2StorageService();
    $r2Cfg = require __DIR__ . '/../../../config/r2.php';

    ensureImagenesTable($pdo, $db);

    switch ($action) {
        case 'list':
            handleList($pdo, $db, $idProducto);
            break;
        case 'upload':
            handleUpload($r2, $r2Cfg, $pdo, $db, $idProducto, $id_empresa);
            break;
        case 'upload_url':
            $imageUrl = trim((string)($input['image_url'] ?? ''));
            handleUploadFromUrl($r2, $r2Cfg, $pdo, $db, $idProducto, $id_empresa, $imageUrl);
            break;
        case 'delete':
            $fileId = (string)($input['file_id'] ?? '');
            handleDelete($r2, $pdo, $db, $idProducto, $fileId);
            break;
        case 'set_principal':
            $fileId = (string)($input['file_id'] ?? '');
            handleSetPrincipal($pdo, $db, $idProducto, $fileId);
            break;
        default:
            echo json_encode(['success' => false, 'error' => "Acción no válida: {$action}"]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function handleList(PDO $pdo, string $db, int $idProducto): void
{
    if ($idProducto <= 0) {
        echo json_encode(['success' => true, 'data' => []]);
        return;
    }

    $stmt = $pdo->prepare("\n        SELECT id, idproducto, drive_file_id, url, filename, orden, principal, created_at\n        FROM {$db}.producto_imagenes\n        WHERE idproducto = :id\n        ORDER BY principal DESC, orden ASC\n    ");
    $stmt->execute([':id' => $idProducto]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($images as &$img) {
        enrichImageRowWithVariants($img);
    }
    unset($img);

    echo json_encode(['success' => true, 'data' => $images]);
}

function handleUpload(R2StorageService $r2, array $r2Cfg, PDO $pdo, string $db, int $idProducto, int $id_empresa): void
{
    if ($idProducto <= 0) {
        respondValidationError('idproducto requerido');
        return;
    }

    if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['imagen']['error'] ?? -1;
        respondValidationError("Error de upload (código: {$code})");
        return;
    }

    $maxBytes = max(1, (int)($r2Cfg['max_image_mb'] ?? 8)) * 1024 * 1024;
    if ((int)($_FILES['imagen']['size'] ?? 0) > $maxBytes) {
        respondValidationError('La imagen excede el tamaño máximo permitido');
        return;
    }

    $file = $_FILES['imagen'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string)finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/avif' => 'avif',
    ];
    if (!isset($allowed[$mime])) {
        respondValidationError("Tipo de archivo no permitido: {$mime}");
        return;
    }

    $maxPer = max(1, (int)($r2Cfg['max_per_product'] ?? 5));
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$db}.producto_imagenes WHERE idproducto = :id");
    $stmt->execute([':id' => $idProducto]);
    $count = (int)($stmt->fetch()['c'] ?? 0);

    if ($count >= $maxPer) {
        respondValidationError("Máximo {$maxPer} imágenes por producto");
        return;
    }

    $binary = (string)file_get_contents($file['tmp_name']);
    if ($binary === '') {
        respondValidationError('No se pudo leer el archivo subido');
        return;
    }

    $baseKey = buildR2ObjectBaseKey($id_empresa, $db, $idProducto);
    $variants = ImageVariantService::generateWebpVariantsFromBinary($binary);
    $uploadedUrls = [];
    foreach ($variants as $variantName => $variantData) {
        $variantKey = ImageVariantService::r2VariantKey($baseKey, $variantName);
        $upload = $r2->putObject($variantKey, (string)$variantData['binary'], 'image/webp', [
            'cache-control' => 'public, max-age=31536000, immutable',
        ]);
        $uploadedUrls[$variantName] = (string)$upload['url'];
    }
    $result = [
        'id' => 'r2:' . $baseKey,
        'name' => basename($baseKey) . '_medium.webp',
        'url' => $uploadedUrls['medium'] ?? ($uploadedUrls['large'] ?? ''),
        'variants' => $uploadedUrls,
    ];

    saveUploadedImageRecord($pdo, $db, $idProducto, $result, $count);
}

function handleUploadFromUrl(R2StorageService $r2, array $r2Cfg, PDO $pdo, string $db, int $idProducto, int $id_empresa, string $imageUrl): void
{
    if ($idProducto <= 0) {
        respondValidationError('idproducto requerido');
        return;
    }

    if (empty($imageUrl) || !preg_match('/^https?:\/\//i', $imageUrl)) {
        respondValidationError('URL de imagen inválida');
        return;
    }

    $maxPer = max(1, (int)($r2Cfg['max_per_product'] ?? 5));
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$db}.producto_imagenes WHERE idproducto = :id");
    $stmt->execute([':id' => $idProducto]);
    $count = (int)($stmt->fetch()['c'] ?? 0);

    if ($count >= $maxPer) {
        respondValidationError("Máximo {$maxPer} imágenes por producto");
        return;
    }

    $imageUrl = resolveImageUrlCandidate($imageUrl);
    [$binary, $httpCode, $contentType, $curlErr] = downloadRemoteImageBinary($imageUrl);

    if (($httpCode === 200) && !empty($binary) && stripos((string)$contentType, 'text/html') !== false) {
        $html = (string)$binary;
        $embedded = extractImageUrlFromHtml($html, $imageUrl);
        if ($embedded) {
            $imageUrl = resolveImageUrlCandidate($embedded);
            [$binary, $httpCode, $contentType, $curlErr] = downloadRemoteImageBinary($imageUrl);
        }
    }

    if ($httpCode !== 200 || empty($binary)) {
        respondValidationError('No se pudo descargar la imagen remota' . ($curlErr ? ": {$curlErr}" : ''));
        return;
    }

    if (!$contentType || stripos($contentType, 'image/') !== 0) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $contentType = (string)finfo_buffer($finfo, $binary);
        finfo_close($finfo);
    }

    $mime = strtolower(trim(explode(';', (string)$contentType)[0] ?? ''));
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/avif' => 'avif',
    ];

    if (!isset($allowed[$mime])) {
        respondValidationError("Tipo de archivo no permitido: {$mime}");
        return;
    }

    $maxBytes = max(1, (int)($r2Cfg['max_image_mb'] ?? 8)) * 1024 * 1024;
    if (strlen($binary) > $maxBytes) {
        respondValidationError('La imagen remota excede el tamaño máximo permitido');
        return;
    }

    $baseKey = buildR2ObjectBaseKey($id_empresa, $db, $idProducto);
    $variants = ImageVariantService::generateWebpVariantsFromBinary($binary);
    $uploadedUrls = [];
    foreach ($variants as $variantName => $variantData) {
        $variantKey = ImageVariantService::r2VariantKey($baseKey, $variantName);
        $upload = $r2->putObject($variantKey, (string)$variantData['binary'], 'image/webp', [
            'cache-control' => 'public, max-age=31536000, immutable',
        ]);
        $uploadedUrls[$variantName] = (string)$upload['url'];
    }
    $result = [
        'id' => 'r2:' . $baseKey,
        'name' => basename($baseKey) . '_medium.webp',
        'url' => $uploadedUrls['medium'] ?? ($uploadedUrls['large'] ?? ''),
        'variants' => $uploadedUrls,
    ];

    saveUploadedImageRecord($pdo, $db, $idProducto, $result, $count);
}

function downloadRemoteImageBinary(string $url): array
{
    $ch = curl_init();
    if ($ch === false) {
        return ['', 0, '', 'No se pudo inicializar cURL'];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Mobile; SistemaXPro)',
        CURLOPT_HTTPHEADER => [
            'Accept: image/webp,image/png,image/jpeg,image/*',
            'Referer: https://www.google.com/',
        ],
    ]);

    $binary = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [$binary, $httpCode, $contentType, $curlErr];
}

function resolveImageUrlCandidate(string $url): string
{
    $url = trim($url);
    if (!preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    foreach (['imgurl', 'mediaurl', 'url'] as $k) {
        $v = trim((string)($query[$k] ?? ''));
        if (preg_match('/^https?:\/\//i', $v)) {
            return $v;
        }
    }

    return $url;
}

function extractImageUrlFromHtml(string $html, string $baseUrl = ''): ?string
{
    if ($html === '') {
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!@$dom->loadHTML($html)) {
        return null;
    }

    $xp = new DOMXPath($dom);
    $nodes = $xp->query("//meta[@property='og:image' or @name='og:image' or @name='twitter:image' or @property='twitter:image']");
    if (!$nodes || $nodes->length === 0) {
        return null;
    }

    $content = trim((string)$nodes->item(0)->getAttribute('content'));
    if ($content === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $content)) {
        return $content;
    }

    if ($baseUrl && str_starts_with($content, '/')) {
        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        if ($host !== '') {
            return $scheme . '://' . $host . $content;
        }
    }

    return null;
}

function saveUploadedImageRecord(PDO $pdo, string $db, int $idProducto, array $result, int $currentCount): void
{
    $isPrincipal = 1;

    $pdo->prepare("UPDATE {$db}.producto_imagenes SET principal = 0 WHERE idproducto = :id")
        ->execute([':id' => $idProducto]);

    $stmt = $pdo->prepare("\n        INSERT INTO {$db}.producto_imagenes (idproducto, drive_file_id, url, filename, orden, principal, created_at)\n        VALUES (:id, :fid, :url, :fname, :orden, :principal, NOW())\n    ");
    $stmt->execute([
        ':id' => $idProducto,
        ':fid' => (string)$result['id'],
        ':url' => (string)$result['url'],
        ':fname' => (string)$result['name'],
        ':orden' => $currentCount + 1,
        ':principal' => $isPrincipal,
    ]);

    $insertId = (int)$pdo->lastInsertId();

    updateFotoUrl($pdo, $db, $idProducto, (string)$result['url']);

    echo json_encode([
        'success' => true,
        'message' => 'Imagen subida correctamente',
        'data' => [
            'id' => $insertId,
            'drive_file_id' => (string)$result['id'],
            'url' => (string)$result['url'],
            'variants' => (array)($result['variants'] ?? []),
            'filename' => (string)$result['name'],
            'principal' => $isPrincipal,
        ],
    ]);
}

function handleDelete(R2StorageService $r2, PDO $pdo, string $db, int $idProducto, string $fileId): void
{
    if ($fileId === '') {
        respondValidationError('file_id requerido');
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$db}.producto_imagenes WHERE drive_file_id = :fid AND idproducto = :id");
    $stmt->execute([':fid' => $fileId, ':id' => $idProducto]);
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$img) {
        respondValidationError('Imagen no encontrada');
        return;
    }

    try {
        $baseKey = parseR2BaseFromFileId((string)$fileId);
        if ($baseKey !== null) {
            foreach (array_keys(ImageVariantService::sizes()) as $variantName) {
                $r2->deleteObject(ImageVariantService::r2VariantKey($baseKey, $variantName));
            }
        } else {
            $legacyKey = parseR2KeyFromFileId((string)$fileId);
            if ($legacyKey !== null) {
                $r2->deleteObject($legacyKey);
            }
        }
    } catch (Throwable $e) {
        error_log('R2 delete warning: ' . $e->getMessage());
    }

    $pdo->prepare("DELETE FROM {$db}.producto_imagenes WHERE drive_file_id = :fid AND idproducto = :id")
        ->execute([':fid' => $fileId, ':id' => $idProducto]);

    if ((int)($img['principal'] ?? 0) === 1) {
        $stmt = $pdo->prepare("\n            SELECT drive_file_id, url FROM {$db}.producto_imagenes\n            WHERE idproducto = :id ORDER BY orden ASC LIMIT 1\n        ");
        $stmt->execute([':id' => $idProducto]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($next) {
            $pdo->prepare("UPDATE {$db}.producto_imagenes SET principal = 1 WHERE drive_file_id = :fid AND idproducto = :id")
                ->execute([':fid' => $next['drive_file_id'], ':id' => $idProducto]);
            updateFotoUrl($pdo, $db, $idProducto, (string)$next['url']);
        } else {
            updateFotoUrl($pdo, $db, $idProducto, null);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Imagen eliminada']);
}

function handleSetPrincipal(PDO $pdo, string $db, int $idProducto, string $fileId): void
{
    if ($fileId === '' || $idProducto <= 0) {
        respondValidationError('file_id e idproducto requeridos');
        return;
    }

    $stmt = $pdo->prepare("SELECT url FROM {$db}.producto_imagenes WHERE drive_file_id = :fid AND idproducto = :id");
    $stmt->execute([':fid' => $fileId, ':id' => $idProducto]);
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$img) {
        respondValidationError('Imagen no encontrada');
        return;
    }

    $pdo->prepare("UPDATE {$db}.producto_imagenes SET principal = 0 WHERE idproducto = :id")
        ->execute([':id' => $idProducto]);

    $pdo->prepare("UPDATE {$db}.producto_imagenes SET principal = 1 WHERE drive_file_id = :fid AND idproducto = :id")
        ->execute([':fid' => $fileId, ':id' => $idProducto]);

    updateFotoUrl($pdo, $db, $idProducto, (string)$img['url']);

    echo json_encode(['success' => true, 'message' => 'Imagen principal actualizada']);
}

function updateFotoUrl(PDO $pdo, string $db, int $idProducto, ?string $url): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$db}`.`tblproductos` LIKE 'foto_url'");
        if (!$stmt || !$stmt->fetch()) {
            return;
        }

        $pdo->prepare("UPDATE {$db}.tblproductos SET foto_url = :url WHERE idproducto = :id")
            ->execute([':url' => $url, ':id' => $idProducto]);
    } catch (Throwable $e) {
        error_log('updateFotoUrl error: ' . $e->getMessage());
    }
}

function ensureImagenesTable(PDO $pdo, string $db): void
{
    static $done = [];
    if (isset($done[$db])) {
        return;
    }
    $done[$db] = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`producto_imagenes` (\n            `id` INT NOT NULL AUTO_INCREMENT,\n            `idproducto` INT NOT NULL,\n            `drive_file_id` VARCHAR(255) NOT NULL,\n            `url` VARCHAR(500) NOT NULL,\n            `filename` VARCHAR(255) NULL,\n            `orden` INT NOT NULL DEFAULT 1,\n            `principal` TINYINT(1) NOT NULL DEFAULT 0,\n            `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,\n            PRIMARY KEY (`id`),\n            INDEX `idx_producto` (`idproducto`),\n            INDEX `idx_drive_file` (`drive_file_id`)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ensureImagenesTable: ' . $e->getMessage());
    }
}

function parseR2KeyFromFileId(string $fileId): ?string
{
    $fileId = trim($fileId);
    if ($fileId === '') {
        return null;
    }

    if (str_starts_with($fileId, 'r2:')) {
        return ltrim(substr($fileId, 3), '/');
    }

    return null;
}

function parseR2BaseFromFileId(string $fileId): ?string
{
    $key = parseR2KeyFromFileId($fileId);
    if ($key === null || $key === '') {
        return null;
    }
    if (preg_match('/_(thumb|small|medium|large)\.webp$/i', $key)) {
        return preg_replace('/_(thumb|small|medium|large)\.webp$/i', '', $key);
    }
    // Nuevo formato: file_id almacena base sin sufijo.
    if (!preg_match('/\.[a-z0-9]+$/i', $key)) {
        return $key;
    }
    return null;
}

function buildR2ObjectBaseKey(int $idEmpresa, string $db, int $idProducto): string
{
    $dbSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $db);
    $unique = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
    return 'e' . $idEmpresa . '/p/' . $dbSafe . '/' . $idProducto . '/' . $unique;
}

function enrichImageRowWithVariants(array &$img): void
{
    $variants = ImageVariantService::deriveVariantUrls(
        (string)($img['drive_file_id'] ?? ''),
        (string)($img['url'] ?? '')
    );
    $img['variants'] = $variants;
    $img['thumb_url'] = $variants['thumb'] ?? ($img['url'] ?? '');
    $img['small_url'] = $variants['small'] ?? ($img['url'] ?? '');
    $img['medium_url'] = $variants['medium'] ?? ($img['url'] ?? '');
    $img['large_url'] = $variants['large'] ?? ($img['url'] ?? '');
    if (isset($variants['medium']) && $variants['medium'] !== '') {
        $img['url'] = $variants['medium'];
    }
}

function respondValidationError(string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => false,
        'error' => $message,
        'validation_error' => true,
    ], $extra));
}
