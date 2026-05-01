<?php

/**
 * POS API - Proxy de Imágenes Ilustrativas
 * 
 * Busca imágenes en servicios gratuitos (Unsplash, Pexels, Picsum) usando
 * palabras clave extraídas de la descripción del producto.
 * Cachea localmente en WebP con límite de 100MB por empresa.
 * 
 * Modos de operación:
 *   ?id={id}&q={keywords}           - Obtener/cachear imagen de producto
 *   ?id={id}&q={keywords}&refresh=1 - Forzar nueva descarga
 *   ?action=clear_old&days=30       - Limpiar caché antiguo (admin)
 *   ?action=stats                   - Estadísticas de caché
 */

header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

// Configuración
define('CACHE_MAX_BYTES', 104857600); // 100MB por empresa
define('WEBP_QUALITY', 80);
define('IMAGE_SIZE', 400); // Tamaño de imagen a solicitar

// API Key de Pixabay (gratis: 5000 req/hora)
// Obtén tu key en: https://pixabay.com/api/docs/
define('PIXABAY_API_KEY', '54541717-3563ce276d45c226152a49d0b');

// Palabras a ignorar al extraer keywords (artículos, preposiciones, unidades)
$STOP_WORDS = [
    // Artículos y preposiciones
    'el',
    'la',
    'los',
    'las',
    'un',
    'una',
    'unos',
    'unas',
    'de',
    'del',
    'al',
    'a',
    'en',
    'con',
    'por',
    'para',
    'y',
    'o',
    'e',
    // Unidades de medida
    'ml',
    'lt',
    'lts',
    'litro',
    'litros',
    'kg',
    'kgs',
    'gr',
    'grs',
    'gramos',
    'gramo',
    'mg',
    'cc',
    'cm',
    'mm',
    'm',
    'mt',
    'mts',
    'unid',
    'unidad',
    'unidades',
    'paq',
    'paquete',
    'caja',
    'cajas',
    'pack',
    'docena',
    'par',
    'pares',
    // Números y patrones comunes
    'x',
    'c',
    'u',
    's',
    'n',
    'tipo',
    'mod',
    'modelo',
    'ref',
    'cod',
    'codigo',
    'art',
    'articulo',
    // Otros
    'sin',
    'con',
    'mas',
    'menos',
    'grande',
    'chico',
    'mediano',
    'pequeño',
    'nuevo',
    'nueva'
];

/**
 * Extrae 2-3 palabras clave relevantes de la descripción del producto
 */
function extractKeywords($descripcion, $stopWords)
{
    if (empty($descripcion)) {
        return 'producto';
    }

    // Limpiar y normalizar
    $text = mb_strtolower(trim($descripcion), 'UTF-8');
    $text = preg_replace('/[^a-záéíóúñü\s]/u', ' ', $text); // Solo letras y espacios
    $text = preg_replace('/\s+/', ' ', $text); // Múltiples espacios a uno

    $words = explode(' ', $text);
    $keywords = [];

    foreach ($words as $word) {
        $word = trim($word);
        // Ignorar palabras cortas, números y stop words
        if (strlen($word) < 3) continue;
        if (is_numeric($word)) continue;
        if (in_array($word, $stopWords)) continue;

        $keywords[] = $word;

        // Máximo 3 palabras clave
        if (count($keywords) >= 3) break;
    }

    // Si no hay keywords válidos, usar primera palabra larga
    if (empty($keywords)) {
        foreach ($words as $word) {
            if (strlen($word) >= 3) {
                $keywords[] = $word;
                break;
            }
        }
    }

    return !empty($keywords) ? implode(' ', $keywords) : 'producto';
}

/**
 * Descarga imagen desde URL y la convierte a WebP
 */
function downloadAndCacheAsWebp($url, $destPath, $timeout = 10)
{
    // Crear directorio si no existe
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Descargar imagen con curl (mejor manejo de redirects)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ImageProxy/1.0)',
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($imageData)) {
        return false;
    }

    // Crear imagen desde datos descargados
    $sourceImage = @imagecreatefromstring($imageData);
    if (!$sourceImage) {
        return false;
    }

    // Obtener dimensiones originales
    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);

    // Redimensionar si es necesario (máximo 400x400, manteniendo proporción)
    $maxSize = IMAGE_SIZE;
    if ($width > $maxSize || $height > $maxSize) {
        if ($width > $height) {
            $newWidth = $maxSize;
            $newHeight = intval($height * ($maxSize / $width));
        } else {
            $newHeight = $maxSize;
            $newWidth = intval($width * ($maxSize / $height));
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        // Preservar transparencia para PNG
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($sourceImage);
        $sourceImage = $resized;
    }

    // Guardar como WebP
    $result = imagewebp($sourceImage, $destPath, WEBP_QUALITY);
    imagedestroy($sourceImage);

    return $result && file_exists($destPath);
}

function saveImageBlobAsWebp(string $imageData, string $destPath): bool
{
    if ($imageData === '') {
        return false;
    }

    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $sourceImage = @imagecreatefromstring($imageData);
    if (!$sourceImage) {
        return false;
    }

    $width = imagesx($sourceImage);
    $height = imagesy($sourceImage);
    $maxSize = IMAGE_SIZE;

    if ($width > $maxSize || $height > $maxSize) {
        if ($width > $height) {
            $newWidth = $maxSize;
            $newHeight = intval($height * ($maxSize / $width));
        } else {
            $newHeight = $maxSize;
            $newWidth = intval($width * ($maxSize / $height));
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($sourceImage);
        $sourceImage = $resized;
    }

    $result = imagewebp($sourceImage, $destPath, WEBP_QUALITY);
    imagedestroy($sourceImage);

    return $result && file_exists($destPath);
}

/**
 * Verifica límite de caché y elimina archivos antiguos si excede
 */
function checkCacheLimit($cacheDir, $maxBytes = CACHE_MAX_BYTES)
{
    if (!is_dir($cacheDir)) return;

    $files = glob($cacheDir . '/*.webp');
    if (empty($files)) return;

    // Calcular tamaño total
    $totalSize = 0;
    $fileInfo = [];
    foreach ($files as $file) {
        $size = filesize($file);
        $mtime = filemtime($file);
        $totalSize += $size;
        $fileInfo[] = ['path' => $file, 'size' => $size, 'mtime' => $mtime];
    }

    // Si no excede el límite, no hacer nada
    if ($totalSize <= $maxBytes) return;

    // Ordenar por fecha de modificación (más antiguos primero)
    usort($fileInfo, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

    // Eliminar hasta estar bajo el límite
    foreach ($fileInfo as $info) {
        if ($totalSize <= $maxBytes) break;
        if (@unlink($info['path'])) {
            $totalSize -= $info['size'];
        }
    }
}

/**
 * Limpia archivos de caché más antiguos que N días
 */
function clearOldCache($cacheDir, $days)
{
    if (!is_dir($cacheDir)) {
        return ['deleted' => 0, 'freed_mb' => 0, 'error' => 'Directorio no existe'];
    }

    $files = glob($cacheDir . '/*.webp');
    $cutoff = time() - ($days * 86400);
    $deleted = 0;
    $freedBytes = 0;

    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            $size = filesize($file);
            if (@unlink($file)) {
                $deleted++;
                $freedBytes += $size;
            }
        }
    }

    return [
        'deleted' => $deleted,
        'freed_mb' => round($freedBytes / 1048576, 2),
        'remaining' => count(glob($cacheDir . '/*.webp'))
    ];
}

/**
 * Obtiene estadísticas del caché
 */
function getCacheStats($cacheDir)
{
    if (!is_dir($cacheDir)) {
        return ['error' => 'Directorio no existe', 'total_files' => 0, 'total_mb' => 0];
    }

    $files = glob($cacheDir . '/*.webp');
    $totalSize = 0;
    $oldest = null;
    $newest = null;

    foreach ($files as $file) {
        $totalSize += filesize($file);
        $mtime = filemtime($file);
        if ($oldest === null || $mtime < $oldest) $oldest = $mtime;
        if ($newest === null || $mtime > $newest) $newest = $mtime;
    }

    return [
        'total_files' => count($files),
        'total_mb' => round($totalSize / 1048576, 2),
        'limit_mb' => round(CACHE_MAX_BYTES / 1048576, 2),
        'usage_percent' => round(($totalSize / CACHE_MAX_BYTES) * 100, 1),
        'oldest_date' => $oldest ? date('Y-m-d H:i:s', $oldest) : null,
        'newest_date' => $newest ? date('Y-m-d H:i:s', $newest) : null
    ];
}

/**
 * Busca imagen en Pixabay API
 */
function searchPixabay($keywords, $destPath)
{
    $apiKey = PIXABAY_API_KEY;
    if (empty($apiKey)) {
        return false;
    }

    $query = urlencode($keywords);
    $url = "https://pixabay.com/api/?key={$apiKey}&q={$query}&image_type=photo&per_page=3&safesearch=true&min_width=" . IMAGE_SIZE;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return false;
    }

    $data = json_decode($response, true);
    if (empty($data['hits'])) {
        return false;
    }

    // Tomar primera imagen disponible
    foreach ($data['hits'] as $hit) {
        // Preferir webformatURL (640px) o previewURL (150px)
        $imageUrl = $hit['webformatURL'] ?? $hit['previewURL'] ?? null;
        if ($imageUrl && downloadAndCacheAsWebp($imageUrl, $destPath)) {
            return true;
        }
    }

    return false;
}

/**
 * Busca imagen en Bing Images orientado a catalogo de producto
 */
function searchBingCatalogImage($keywords, $destPath)
{
    $baseKeywords = trim((string)$keywords);
    if ($baseKeywords === '') {
        return false;
    }

    $queries = [
        $baseKeywords . ' producto',
        $baseKeywords . ' foto producto',
        $baseKeywords . ' envase producto',
        $baseKeywords . ' catalogo producto',
        $baseKeywords,
    ];

    $isLikelyDocumentUrl = static function (string $url): bool {
        $normalized = mb_strtolower(rawurldecode($url), 'UTF-8');
        $blocked = [
            '.pdf',
            '.svg',
            'datasheet',
            'data-sheet',
            'ficha',
            'catalog',
            'catalogo',
            'manual',
            'sds',
            'msds',
            'hoja-tecnica',
            'spec',
            'document',
        ];
        foreach ($blocked as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    };

    foreach ($queries as $query) {
        foreach (fetchBingCatalogCandidates($query, 8) as $candidate) {
            $imageUrl = trim((string)($candidate['image_url'] ?? ''));
            if ($imageUrl !== '' && downloadAndCacheAsWebp($imageUrl, $destPath)) {
                return true;
            }
        }
    }

    return false;
}

function fetchBingCatalogCandidates(string $keywords, int $limit = 12): array
{
    $baseKeywords = trim((string)$keywords);
    if ($baseKeywords === '') {
        return [];
    }

    $queries = [
        $baseKeywords . ' producto',
        $baseKeywords . ' foto producto',
        $baseKeywords . ' envase producto',
        $baseKeywords . ' catalogo producto',
        $baseKeywords,
    ];

    $isLikelyDocumentUrl = static function (string $url): bool {
        $normalized = mb_strtolower(rawurldecode($url), 'UTF-8');
        $blocked = [
            '.pdf', '.svg', 'datasheet', 'data-sheet', 'ficha', 'catalog', 'catalogo',
            'manual', 'sds', 'msds', 'hoja-tecnica', 'spec', 'document',
        ];
        foreach ($blocked as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    };

    $results = [];
    $seen = [];

    foreach ($queries as $query) {
        $url = 'https://www.bing.com/images/search?' . http_build_query([
            'q' => trim($query),
            'form' => 'HDRSC3',
            'first' => 1,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept-Language: es-ES,es;q=0.9,en;q=0.8'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            continue;
        }

        $decoded = html_entity_decode($response, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $imageMatches = [];
        $thumbMatches = [];

        preg_match_all('/"murl"\s*:\s*"([^"]+)"/i', $decoded, $imageMatches);
        preg_match_all('/"turl"\s*:\s*"([^"]+)"/i', $decoded, $thumbMatches);

        $imageUrls = $imageMatches[1] ?? [];
        $thumbUrls = $thumbMatches[1] ?? [];

        foreach ($imageUrls as $index => $candidate) {
            $imageUrl = trim((string)$candidate);
            if ($imageUrl === '' || isset($seen[$imageUrl])) {
                continue;
            }
            $seen[$imageUrl] = true;
            if (!preg_match('/^https?:\/\//i', $imageUrl)) {
                continue;
            }
            if ($isLikelyDocumentUrl($imageUrl)) {
                continue;
            }
            $results[] = [
                'image_url' => $imageUrl,
                'thumb_url' => trim((string)($thumbUrls[$index] ?? $imageUrl)),
                'query' => $query,
            ];
            if (count($results) >= $limit) {
                break 2;
            }
        }
    }

    return $results;
}

/**
 * Busca imagen en DuckDuckGo Images (más relevante que Unsplash/Picsum)
 */
function searchDuckDuckGoImage($keywords, $destPath)
{
    // Obtener token VQD
    $vqdUrl = "https://duckduckgo.com/?" . http_build_query(['q' => $keywords, 'iar' => 'images']);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $vqdUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (!preg_match('/vqd=([^&"\']+)/', $response, $matches)) {
        return false;
    }
    
    $vqd = $matches[1];
    
    // Buscar imágenes
    $searchUrl = "https://duckduckgo.com/i.js?" . http_build_query([
        'l' => 'us-en',
        'o' => 'json',
        'q' => $keywords,
        'vqd' => $vqd,
        'f' => ',,,',
        'p' => '1'
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Referer: https://duckduckgo.com/'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['results']) || empty($data['results'])) {
        return false;
    }
    
    // Intentar descargar la primera imagen válida
    foreach (array_slice($data['results'], 0, 5) as $result) {
        $imageUrl = $result['image'] ?? '';
        if (!empty($imageUrl) && downloadAndCacheAsWebp($imageUrl, $destPath)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Busca imagen en servicios externos con múltiples fallbacks
 * Orden: DuckDuckGo → Pixabay → Unsplash Source
 */
function searchAndCacheImage($keywords, $destPath, $idProducto, $preferSource = '')
{
    global $STOP_WORDS;

    $prefer = strtolower(trim((string)$preferSource));

    if ($prefer === 'bing_catalog') {
        if (searchBingCatalogImage($keywords, $destPath)) {
            return ['success' => true, 'source' => 'bing_catalog', 'keywords' => $keywords];
        }
        if (searchDuckDuckGoImage($keywords, $destPath)) {
            return ['success' => true, 'source' => 'duckduckgo', 'keywords' => $keywords];
        }
        if (searchPixabay($keywords, $destPath)) {
            return ['success' => true, 'source' => 'pixabay', 'keywords' => $keywords];
        }
        if (searchUnsplashSource($keywords, $destPath)) {
            return ['success' => true, 'source' => 'unsplash', 'keywords' => $keywords];
        }
    }

    if ($prefer === 'pixabay') {
        if (searchPixabay($keywords, $destPath)) {
            return ['success' => true, 'source' => 'pixabay', 'keywords' => $keywords];
        }
        if (searchDuckDuckGoImage($keywords, $destPath)) {
            return ['success' => true, 'source' => 'duckduckgo', 'keywords' => $keywords];
        }
        if (searchUnsplashSource($keywords, $destPath)) {
            return ['success' => true, 'source' => 'unsplash', 'keywords' => $keywords];
        }
    } else {
        // 0. Intentar Bing catalogo primero para productos sin imagen propia
        if (searchBingCatalogImage($keywords, $destPath)) {
            return ['success' => true, 'source' => 'bing_catalog', 'keywords' => $keywords];
        }
        // 1. Intentar DuckDuckGo Images (mejor relevancia)
        if (searchDuckDuckGoImage($keywords, $destPath)) {
            return ['success' => true, 'source' => 'duckduckgo', 'keywords' => $keywords];
        }
        // 2. Intentar Pixabay
        if (searchPixabay($keywords, $destPath)) {
            return ['success' => true, 'source' => 'pixabay', 'keywords' => $keywords];
        }
        // 3. Intentar Unsplash Source como último recurso
        if (searchUnsplashSource($keywords, $destPath)) {
            return ['success' => true, 'source' => 'unsplash', 'keywords' => $keywords];
        }
    }

    // Si todo falla, mostrar placeholder
    return ['success' => false, 'error' => 'No se encontró imagen relevante'];
}

/**
 * Busca imagen en Unsplash Source (gratuito, sin API key)
 */
function searchUnsplashSource($keywords, $destPath)
{
    $query = urlencode($keywords);
    // Unsplash Source redirige a una imagen aleatoria basada en keywords
    $url = "https://source.unsplash.com/featured/?" . $query;
    
    return downloadAndCacheAsWebp($url, $destPath);
}

// ============ MAIN ============

try {
    $action = $_GET['action'] ?? '';
    $id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

    // Obtener conexión y dbName
    $conn = getEmpresaConnection($id_empresa);
    $dbName = $conn['dbName'];

    // Directorio de caché para esta empresa
    $cacheBaseDir = dirname(__DIR__, 2) . "/_lib/file/img/productos_cache/";
    $cacheDir = $cacheBaseDir . $dbName . "/";
    $cacheBasePath = "/_lib/file/img/productos_cache/{$dbName}/";

    // Crear directorio si no existe
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // === Acciones administrativas ===
    if ($action === 'clear_old') {
        header('Content-Type: application/json; charset=utf-8');

        // Verificar permisos (debe estar logueado)
        if (empty($_SESSION['id_login']) && empty($_SESSION['id_usuario'])) {
            echo json_encode(['error' => 'No autorizado', 'success' => false]);
            exit;
        }

        $days = (int)($_GET['days'] ?? 30);
        if ($days < 1) $days = 30;

        $result = clearOldCache($cacheDir, $days);
        $result['success'] = true;
        $result['message'] = "Limpieza completada: {$result['deleted']} archivos eliminados, {$result['freed_mb']} MB liberados";
        echo json_encode($result);
        exit;
    }

    if ($action === 'stats') {
        header('Content-Type: application/json; charset=utf-8');

        $stats = getCacheStats($cacheDir);
        $stats['success'] = true;
        $stats['cache_dir'] = $cacheDir;
        echo json_encode($stats);
        exit;
    }

    if ($action === 'search_bing_catalog') {
        header('Content-Type: application/json; charset=utf-8');
        $query = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(24, (int)($_GET['limit'] ?? 12)));
        if ($query === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Consulta requerida']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'source' => 'bing_catalog',
            'query' => $query,
            'images' => fetchBingCatalogCandidates($query, $limit),
        ]);
        exit;
    }

    $idProducto = (int)($_GET['id'] ?? 0);
    $query = $_GET['q'] ?? '';
    $refresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';
    $prefer = strtolower(trim((string)($_GET['prefer'] ?? '')));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');

        $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
        $payload = [];
        if (str_contains($contentType, 'application/json')) {
            $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        } else {
            $payload = $_POST;
        }

        $actionPost = trim((string)($payload['action'] ?? ''));
        $idProducto = (int)($payload['idproducto'] ?? $payload['id'] ?? 0);
        if ($idProducto <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de producto requerido']);
            exit;
        }

        $cachedFile = $cacheDir . $idProducto . '.webp';
        $cachedPublicUrl = '/public' . $cacheBasePath . $idProducto . '.webp';
        checkCacheLimit($cacheDir);

        if ($actionPost === 'upload') {
            if (!isset($_FILES['imagen']) || (int)($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Archivo de imagen requerido']);
                exit;
            }

            $tmpPath = (string)($_FILES['imagen']['tmp_name'] ?? '');
            $blob = $tmpPath !== '' && is_uploaded_file($tmpPath) ? (string)file_get_contents($tmpPath) : '';
            if (!saveImageBlobAsWebp($blob, $cachedFile)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No se pudo guardar la imagen en caché']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Imagen cacheada correctamente',
                'cache_url' => $cachedPublicUrl,
                'cached_at' => time()
            ]);
            exit;
        }

        if ($actionPost === 'upload_url') {
            $imageUrl = trim((string)($payload['image_url'] ?? ''));
            if ($imageUrl === '' || !preg_match('/^https?:\/\//i', $imageUrl)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'URL de imagen inválida']);
                exit;
            }

            if (!downloadAndCacheAsWebp($imageUrl, $cachedFile)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No se pudo descargar/cachear la imagen']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Imagen cacheada correctamente',
                'cache_url' => $cachedPublicUrl,
                'cached_at' => time()
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no soportada']);
        exit;
    }

    // === Obtener imagen de producto ===

    if ($idProducto <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'ID de producto requerido', 'success' => false]);
        exit;
    }

    // Ruta del archivo cacheado
    $cachedFile = $cacheDir . $idProducto . '.webp';
    $cachedPath = $cacheBasePath . $idProducto . '.webp';

    // Si existe caché y no se pidió refresh, servir directamente
    if (file_exists($cachedFile) && !$refresh) {
        // Servir imagen directamente
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=86400'); // Cache 1 día
        header('X-Cache: HIT');
        readfile($cachedFile);
        exit;
    }

    // Extraer keywords de la descripción
    $keywords = extractKeywords($query, $STOP_WORDS);

    // Verificar límite de caché antes de añadir
    checkCacheLimit($cacheDir);

    // Buscar y cachear imagen
    $result = searchAndCacheImage($keywords, $cachedFile, $idProducto, $prefer);

    if ($result['success'] && file_exists($cachedFile)) {
        // Servir imagen
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=86400');
        header('X-Cache: MISS');
        header('X-Source: ' . $result['source']);
        header('X-Keywords: ' . $keywords);
        readfile($cachedFile);
    } else {
        // Error: devolver placeholder o 404
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'No se pudo obtener imagen',
            'keywords' => $keywords
        ]);
    }
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'success' => false]);
}
