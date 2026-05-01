<?php
require_once __DIR__ . '/common.php';

function tallerVehiculoFotosDir(int $idEmpresa, int $idVehiculo): string
{
    $base = dirname(__DIR__, 2) . '/_lib/file/img/taller_vehiculos';
    return $base . '/e' . $idEmpresa . '/v' . $idVehiculo;
}

function tallerVehiculoFotoUrl(int $idEmpresa, int $idVehiculo, string $fileName): string
{
    $safe = rawurlencode($fileName);
    return "/public/_lib/file/img/taller_vehiculos/e{$idEmpresa}/v{$idVehiculo}/{$safe}";
}

function tallerVehiculoFotoResolveUrl(int $idEmpresa, int $idVehiculo, array $foto): string
{
    $storage = strtolower(trim((string)($foto['storage'] ?? 'local')));
    $url = trim((string)($foto['url'] ?? ''));
    $ruta = trim((string)($foto['ruta'] ?? ''));

    if ($storage === 'r2' && $url !== '') {
        return $url;
    }
    if ($url !== '' && preg_match('~^https?://~i', $url)) {
        return $url;
    }
    if ($ruta === '') {
        return '';
    }

    return tallerVehiculoFotoUrl($idEmpresa, $idVehiculo, $ruta);
}

function tallerBinaryFromBase64(string $base64): array
{
    if (!preg_match('/^data:image\/([a-zA-Z0-9+.-]+);base64,/', $base64, $m)) {
        throw new Exception('Formato de imagen inválido');
    }
    $mimeExt = strtolower($m[1]);
    $ext = in_array($mimeExt, ['jpeg', 'jpg', 'png', 'webp'], true) ? ($mimeExt === 'jpeg' ? 'jpg' : $mimeExt) : 'jpg';
    $data = substr($base64, strpos($base64, ',') + 1);
    $bin = base64_decode($data, true);
    if ($bin === false || strlen($bin) < 64) {
        throw new Exception('Imagen vacía o corrupta');
    }

    return [
        'binary' => $bin,
        'ext' => $ext,
    ];
}

function tallerDescargarImagenVehiculo(string $imageUrl): array
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '' || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        throw new Exception('URL de imagen inválida');
    }

    if (!function_exists('curl_init')) {
        throw new Exception('cURL no disponible');
    }

    $imageUrl = tallerResolveImageUrlCandidate($imageUrl);
    [$bin, $httpCode, $contentType, $curlError] = tallerDownloadRemoteImageBinary($imageUrl);

    if (($httpCode === 200) && !empty($bin) && stripos((string)$contentType, 'text/html') !== false) {
        $embedded = tallerExtractImageUrlFromHtml((string)$bin, $imageUrl);
        if ($embedded) {
            $imageUrl = tallerResolveImageUrlCandidate($embedded);
            [$bin, $httpCode, $contentType, $curlError] = tallerDownloadRemoteImageBinary($imageUrl);
        }
    }

    if ($bin === false || $bin === '' || $httpCode >= 400) {
        throw new Exception($curlError !== '' ? $curlError : 'No se pudo descargar la imagen');
    }
    if (!$contentType || stripos($contentType, 'image/') !== 0) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $contentType = (string)finfo_buffer($finfo, $bin);
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
        throw new Exception('Tipo de archivo no permitido: ' . $mime);
    }

    $ext = $allowed[$mime] ?? 'jpg';

    return [
        'binary' => $bin,
        'ext' => $ext,
    ];
}

function tallerDownloadRemoteImageBinary(string $url): array
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
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Mobile; SistemaX Taller)',
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

function tallerResolveImageUrlCandidate(string $url): string
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

function tallerExtractImageUrlFromHtml(string $html, string $baseUrl = ''): ?string
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

function tallerPersistirFotoVehiculo(int $idEmpresa, string $db, int $idVehiculo, string $binary, string $ext = 'jpg'): array
{
    if ($binary === '' || strlen($binary) < 64) {
        throw new Exception('Imagen vacía o corrupta');
    }

    if (R2StorageService::isConfigured()) {
        $r2 = new R2StorageService();
        $variants = ImageVariantService::generateWebpVariantsFromBinary($binary);
        $baseKey = 'taller/vehiculos/e' . $idEmpresa . '/' . trim($db, '/') . '/v' . $idVehiculo . '/veh_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $uploadedUrls = [];
        foreach ($variants as $variantName => $variantData) {
            $variantKey = ImageVariantService::r2VariantKey($baseKey, $variantName);
            $upload = $r2->putObject($variantKey, (string)$variantData['binary'], 'image/webp', [
                'cache-control' => 'public, max-age=31536000, immutable',
            ]);
            $uploadedUrls[$variantName] = (string)$upload['url'];
        }

        return [
            'storage' => 'r2',
            'ruta' => basename($baseKey) . '_medium.webp',
            'file_id' => 'r2:' . $baseKey,
            'url' => (string)($uploadedUrls['medium'] ?? ($uploadedUrls['large'] ?? reset($uploadedUrls) ?: '')),
        ];
    }

    $dir = tallerVehiculoFotosDir($idEmpresa, $idVehiculo);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new Exception('No se pudo crear directorio de fotos');
    }

    $name = 'veh_' . $idVehiculo . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $full = $dir . '/' . $name;
    if (@file_put_contents($full, $binary) === false) {
        throw new Exception('No se pudo guardar la foto');
    }

    return [
        'storage' => 'local',
        'ruta' => $name,
        'file_id' => null,
        'url' => tallerVehiculoFotoUrl($idEmpresa, $idVehiculo, $name),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$idEmpresa = (int)(($method === 'GET' ? ($_GET['id_empresa'] ?? 0) : null) ?? ($_SESSION['id_empresa'] ?? 0));
if ($idEmpresa <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'id_empresa requerido']);
    exit;
}

try {
    $conn = tallerConn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $useContactos = tallerHasTable($pdo, $db, 'clientes')
        && tallerHasColumn($pdo, $db, 'clientes', 'id')
        && tallerHasColumn($pdo, $db, 'clientes', 'nombre');
    $joinClientes = $useContactos
        ? "LEFT JOIN {$db}.clientes c ON c.id = v.id_cliente"
        : "LEFT JOIN {$db}.taller_clientes c ON c.id_cliente = v.id_cliente";

    if ($method === 'GET') {
        $actionGet = (string)($_GET['action'] ?? '');
        if ($actionGet === 'fotos') {
            $idVehiculo = (int)($_GET['id_vehiculo'] ?? 0);
            if ($idVehiculo <= 0) {
                throw new Exception('Vehículo inválido');
            }
            $stmtFotos = $pdo->prepare("
                SELECT id_foto, id_vehiculo, ruta, storage, file_id, url, titulo, created_at
                FROM {$db}.taller_vehiculo_fotos
                WHERE id_vehiculo = :id
                ORDER BY id_foto DESC
            ");
            $stmtFotos->execute([':id' => $idVehiculo]);
            $rows = $stmtFotos->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['url'] = tallerVehiculoFotoResolveUrl($idEmpresa, $idVehiculo, $r);
            }
            unset($r);
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        $stmt = $pdo->query("SELECT v.*, COALESCE(NULLIF(v.chassis, ''), v.vin, '') AS chassis, c.nombre AS cliente
                            FROM {$db}.taller_vehiculos v
                            {$joinClientes}
                            WHERE v.activo = 1
                            ORDER BY v.chapa ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $input = tallerJsonInput();
    $action = (string)($input['action'] ?? '');
    $item = $input['vehiculo'] ?? [];

    if ($action === 'create') {
        tallerCan('priv_insert');
        $chapa = strtoupper(trim((string)($item['chapa'] ?? '')));
        if ($chapa === '') throw new Exception('Chapa requerida');
        $stmt = $pdo->prepare("INSERT INTO {$db}.taller_vehiculos (id_cliente, marca, modelo, anio, chapa, vin, chassis, color, km_actual, activo)
                               VALUES (:c,:m,:mo,:a,:ch,:vin,:chassis,:color,:km,1)");
        $stmt->execute([
            ':c' => (int)($item['id_cliente'] ?? 0),
            ':m' => trim((string)($item['marca'] ?? '')),
            ':mo' => trim((string)($item['modelo'] ?? '')),
            ':a' => trim((string)($item['anio'] ?? '')),
            ':ch' => $chapa,
            ':vin' => trim((string)($item['vin'] ?? '')),
            ':chassis' => trim((string)($item['chassis'] ?? $item['vin'] ?? '')),
            ':color' => trim((string)($item['color'] ?? '')),
            ':km' => (float)($item['km_actual'] ?? 0),
        ]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Vehiculo creado']);
        exit;
    }

    if ($action === 'update') {
        tallerCan('priv_update');
        $id = (int)($item['id_vehiculo'] ?? 0);
        if ($id <= 0) throw new Exception('ID inválido');
        $stmt = $pdo->prepare("UPDATE {$db}.taller_vehiculos
                               SET id_cliente=:c, marca=:m, modelo=:mo, anio=:a, chapa=:ch, vin=:vin, chassis=:chassis, color=:color, km_actual=:km
                               WHERE id_vehiculo=:id");
        $stmt->execute([
            ':id' => $id,
            ':c' => (int)($item['id_cliente'] ?? 0),
            ':m' => trim((string)($item['marca'] ?? '')),
            ':mo' => trim((string)($item['modelo'] ?? '')),
            ':a' => trim((string)($item['anio'] ?? '')),
            ':ch' => strtoupper(trim((string)($item['chapa'] ?? ''))),
            ':vin' => trim((string)($item['vin'] ?? '')),
            ':chassis' => trim((string)($item['chassis'] ?? $item['vin'] ?? '')),
            ':color' => trim((string)($item['color'] ?? '')),
            ':km' => (float)($item['km_actual'] ?? 0),
        ]);
        echo json_encode(['success' => true, 'message' => 'Vehiculo actualizado']);
        exit;
    }

    if ($action === 'add_foto') {
        tallerCan('priv_update');
        $idVehiculo = (int)($input['id_vehiculo'] ?? 0);
        $imageBase64 = (string)($input['image_base64'] ?? '');
        $titulo = trim((string)($input['titulo'] ?? ''));
        if ($idVehiculo <= 0) throw new Exception('Vehículo inválido');
        if ($imageBase64 === '') throw new Exception('Imagen requerida. Use Google Imágenes y Pegar Imagen.');

        $stVeh = $pdo->prepare("SELECT id_vehiculo FROM {$db}.taller_vehiculos WHERE id_vehiculo = :id AND activo = 1");
        $stVeh->execute([':id' => $idVehiculo]);
        if (!$stVeh->fetchColumn()) throw new Exception('Vehículo no encontrado');

        $imagePayload = tallerBinaryFromBase64($imageBase64);
        $stored = tallerPersistirFotoVehiculo($idEmpresa, $db, $idVehiculo, (string)$imagePayload['binary'], (string)($imagePayload['ext'] ?? 'jpg'));
        $stIns = $pdo->prepare("INSERT INTO {$db}.taller_vehiculo_fotos (id_vehiculo, ruta, storage, file_id, url, titulo, created_by) VALUES (:v,:r,:s,:f,:url,:t,:u)");
        $stIns->execute([
            ':v' => $idVehiculo,
            ':r' => (string)$stored['ruta'],
            ':s' => (string)$stored['storage'],
            ':f' => $stored['file_id'],
            ':url' => $stored['url'],
            ':t' => $titulo !== '' ? $titulo : null,
            ':u' => (int)($_SESSION['id_login'] ?? 0),
        ]);
        echo json_encode([
            'success' => true,
            'id_foto' => (int)$pdo->lastInsertId(),
            'url' => (string)$stored['url'],
            'message' => 'Foto agregada',
        ]);
        exit;
    }

    if ($action === 'delete_foto') {
        tallerCan('priv_delete');
        $idFoto = (int)($input['id_foto'] ?? 0);
        if ($idFoto <= 0) throw new Exception('Foto inválida');
        $stFoto = $pdo->prepare("SELECT id_foto, id_vehiculo, ruta, storage, file_id FROM {$db}.taller_vehiculo_fotos WHERE id_foto = :id");
        $stFoto->execute([':id' => $idFoto]);
        $foto = $stFoto->fetch(PDO::FETCH_ASSOC);
        if (!$foto) throw new Exception('Foto no encontrada');
        $pdo->prepare("DELETE FROM {$db}.taller_vehiculo_fotos WHERE id_foto = :id")->execute([':id' => $idFoto]);
        if (strtolower((string)($foto['storage'] ?? 'local')) === 'r2' && str_starts_with((string)($foto['file_id'] ?? ''), 'r2:') && R2StorageService::isConfigured()) {
            $r2 = new R2StorageService();
            $baseKey = ltrim(substr((string)$foto['file_id'], 3), '/');
            foreach (array_keys(ImageVariantService::sizes()) as $variantName) {
                $r2->deleteObject(ImageVariantService::r2VariantKey($baseKey, $variantName));
            }
        } else {
            $path = tallerVehiculoFotosDir($idEmpresa, (int)$foto['id_vehiculo']) . '/' . (string)$foto['ruta'];
            if (is_file($path)) @unlink($path);
        }
        echo json_encode(['success' => true, 'message' => 'Foto eliminada']);
        exit;
    }

    if ($action === 'delete') {
        tallerCan('priv_delete');
        $id = (int)($input['id_vehiculo'] ?? 0);
        if ($id <= 0) throw new Exception('ID inválido');
        $pdo->prepare("UPDATE {$db}.taller_vehiculos SET activo = 0 WHERE id_vehiculo = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Vehiculo suprimido']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Accion no valida']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
