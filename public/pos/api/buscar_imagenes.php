<?php
/**
 * API para buscar imágenes de productos en múltiples servicios
 * Con traducción automática de español a inglés usando diccionario local + MyMemory API
 * 
 * GET: Buscar imágenes
 *   - q: término de búsqueda (en español, se traduce automáticamente)
 *   - page: página de resultados (default: 1)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Configuración
define('IMAGES_PER_PAGE', 20);
// API Key de Pixabay - Obtén tu key gratis en: https://pixabay.com/api/docs/
// NOTA: La key debe tener el formato: XXXXXX-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
define('PIXABAY_API_KEY', '54541717-3563ce276d45c226152a49d0b');
// Pexels API Key - Alternativa, obtén gratis en: https://www.pexels.com/api/
define('PEXELS_API_KEY', ''); // Configurar si tienes una

/**
 * Traduce texto de español a inglés usando diccionario local + MyMemory API
 * Extrae solo las palabras clave relevantes del producto
 */
function translateToEnglish($text) {
    // Limpiar texto
    $text = trim($text);
    if (empty($text)) return $text;
    
    // Diccionario de términos de productos (español => inglés)
    $commonTerms = [
        // Alimentos básicos
        'arroz' => 'rice',
        'azucar' => 'sugar',
        'azúcar' => 'sugar',
        'aceite' => 'oil',
        'harina' => 'flour',
        'sal' => 'salt',
        'leche' => 'milk',
        'huevo' => 'egg',
        'huevos' => 'eggs',
        'pan' => 'bread',
        'queso' => 'cheese',
        'manteca' => 'butter',
        'mantequilla' => 'butter',
        'carne' => 'meat beef',
        'pollo' => 'chicken',
        'pescado' => 'fish',
        'cerdo' => 'pork',
        'res' => 'beef',
        
        // Embutidos y carnes procesadas
        'chorizo' => 'chorizo sausage',
        'salchicha' => 'sausage',
        'jamon' => 'ham',
        'jamón' => 'ham',
        'mortadela' => 'mortadella bologna',
        'salame' => 'salami',
        'tocino' => 'bacon',
        'panceta' => 'bacon',
        'longaniza' => 'sausage',
        'morcilla' => 'blood sausage',
        'fiambre' => 'cold cuts',
        
        // Verduras
        'verdura' => 'vegetable',
        'fruta' => 'fruit',
        'tomate' => 'tomato',
        'cebolla' => 'onion',
        'ajo' => 'garlic',
        'papa' => 'potato',
        'zanahoria' => 'carrot',
        'lechuga' => 'lettuce',
        'pimiento' => 'pepper bell pepper',
        'morron' => 'bell pepper',
        'morrón' => 'bell pepper',
        'pepino' => 'cucumber',
        'calabaza' => 'pumpkin squash',
        'zapallo' => 'pumpkin squash',
        'choclo' => 'corn',
        'arveja' => 'peas',
        'poroto' => 'beans',
        'lenteja' => 'lentils',
        
        // Frutas
        'manzana' => 'apple',
        'naranja' => 'orange fruit',
        'banana' => 'banana',
        'limon' => 'lemon',
        'limón' => 'lemon',
        'uva' => 'grape',
        'frutilla' => 'strawberry',
        'durazno' => 'peach',
        'pera' => 'pear',
        'sandia' => 'watermelon',
        'sandía' => 'watermelon',
        'melon' => 'melon',
        'melón' => 'melon',
        'piña' => 'pineapple',
        'mango' => 'mango fruit',
        'cafe' => 'coffee',
        'café' => 'coffee',
        'te' => 'tea',
        'té' => 'tea',
        'yerba' => 'yerba mate',
        'mate' => 'yerba mate',
        'agua' => 'water bottle',
        'jugo' => 'juice',
        'gaseosa' => 'soda soft drink',
        'cerveza' => 'beer',
        'vino' => 'wine',
        'galletita' => 'cookie biscuit',
        'galleta' => 'cookie biscuit',
        'chocolate' => 'chocolate',
        'caramelo' => 'candy',
        'dulce' => 'candy sweet',
        'fideo' => 'pasta noodle',
        'fideos' => 'pasta noodles',
        'tallarín' => 'spaghetti',
        'tallarines' => 'spaghetti',
        'atun' => 'tuna can',
        'atún' => 'tuna can',
        'sardina' => 'sardine',
        'mayonesa' => 'mayonnaise',
        'ketchup' => 'ketchup',
        'mostaza' => 'mustard',
        'vinagre' => 'vinegar',
        'pimienta' => 'pepper spice',
        'oregano' => 'oregano',
        'orégano' => 'oregano',
        'girasol' => 'sunflower',
        'maiz' => 'corn',
        'maíz' => 'corn',
        'avena' => 'oatmeal',
        'cereal' => 'cereal',
        
        // Bebidas (marcas conocidas - mantener nombre de marca)
        'coca' => 'coca cola bottle',
        'cola' => '', // ignorar para evitar duplicación
        'pepsi' => 'pepsi bottle',
        'sprite' => 'sprite bottle',
        'fanta' => 'fanta bottle',
        'seven' => '7up',
        'up' => '', // ignorar
        'refresco' => 'soda',
        'energizante' => 'energy drink',
        'pulp' => 'pulp juice',
        'natur' => 'natural juice',
        
        // Lácteos
        'yogur' => 'yogurt',
        'yogurt' => 'yogurt',
        'crema' => 'cream',
        
        // Limpieza
        'detergente' => 'detergent',
        'jabon' => 'soap',
        'jabón' => 'soap',
        'lavandina' => 'bleach',
        'cloro' => 'bleach chlorine',
        'desinfectante' => 'disinfectant',
        'esponja' => 'sponge',
        'escoba' => 'broom',
        'trapo' => 'cloth',
        'papel' => 'paper',
        'servilleta' => 'napkin',
        'toalla' => 'towel',
        'suavizante' => 'fabric softener',
        
        // Higiene personal
        'shampoo' => 'shampoo',
        'champu' => 'shampoo',
        'champú' => 'shampoo',
        'acondicionador' => 'conditioner',
        'dental' => 'toothpaste',
        'cepillo' => 'brush toothbrush',
        'desodorante' => 'deodorant',
        'perfume' => 'perfume',
        'colonia' => 'cologne',
        'pañal' => 'diaper',
        'pañales' => 'diapers',
        
        // Electrónica
        'celular' => 'cellphone mobile phone',
        'telefono' => 'phone telephone',
        'teléfono' => 'phone telephone',
        'cargador' => 'charger',
        'cable' => 'cable',
        'auricular' => 'earphone headphone',
        'auriculares' => 'headphones',
        'parlante' => 'speaker',
        'bateria' => 'battery',
        'batería' => 'battery',
        'pila' => 'battery',
        'pilas' => 'batteries',
        'foco' => 'light bulb',
        'lampara' => 'lamp',
        'lámpara' => 'lamp',
        
        // Colores (no traducir para búsqueda de productos)
        'blanco' => '',
        'negro' => '',
        'rojo' => '',
        'azul' => '',
        'verde' => '',
        'amarillo' => '',
        'rosa' => '',
        'marron' => '',
        'marrón' => '',
        'gris' => '',
        
        // Palabras a ignorar (unidades, conectores)
        'litro' => '',
        'litros' => '',
        'lts' => '',
        'lt' => '',
        'kilo' => '',
        'kilos' => '',
        'kg' => '',
        'gramo' => '',
        'gramos' => '',
        'gr' => '',
        'ml' => '',
        'cc' => '',
        'unidad' => '',
        'unidades' => '',
        'un' => '',
        'und' => '',
        'paquete' => '',
        'paq' => '',
        'caja' => '',
        'cj' => '',
        'bolsa' => '',
        'botella' => '',
        'lata' => '',
        'frasco' => '',
        'sobre' => '',
        'rollo' => '',
        'pack' => '',
        'x' => '',
        'de' => '',
        'con' => '',
        'sin' => '',
        'para' => '',
        'original' => '',
        'clasico' => '',
        'clásico' => '',
        'tradicional' => '',
        'especial' => '',
        'premium' => '',
        'light' => 'light',
        'zero' => 'zero',
        'diet' => 'diet',
    ];
    
    // Convertir a minúsculas
    $textLower = mb_strtolower($text, 'UTF-8');
    
    // Eliminar números y unidades de medida
    $textLower = preg_replace('/\d+\s*(g|gr|kg|lt|lts|l|ml|cc|un|und|paq|cj|cm|mm|m)s?\.?\b/i', ' ', $textLower);
    $textLower = preg_replace('/\d+/', ' ', $textLower);
    $textLower = preg_replace('/\s+/', ' ', trim($textLower));
    
    $words = preg_split('/[\s\-\_\.\/]+/', $textLower);
    $translatedWords = [];
    $unknownWords = [];
    
    foreach ($words as $word) {
        // Limpiar caracteres especiales
        $cleanWord = preg_replace('/[^a-záéíóúñü]/u', '', $word);
        if (empty($cleanWord) || strlen($cleanWord) < 2) continue;
        
        if (isset($commonTerms[$cleanWord])) {
            // Si tiene traducción, usarla (si no está vacía)
            if (!empty($commonTerms[$cleanWord])) {
                $translatedWords[] = $commonTerms[$cleanWord];
            }
        } else {
            // Palabra desconocida - podría ser marca, mantener
            $unknownWords[] = $cleanWord;
        }
    }
    
    // Combinar: primero traducciones conocidas, luego palabras desconocidas (marcas)
    $result = array_merge($translatedWords, $unknownWords);
    $result = array_unique($result);
    $finalQuery = implode(' ', $result);
    
    // Si quedó muy corto, intentar traducción con API
    if (strlen($finalQuery) < 3 && !empty($unknownWords)) {
        $apiTranslation = translateWithAPI(implode(' ', $unknownWords));
        if ($apiTranslation) {
            return $apiTranslation;
        }
        return implode(' ', $unknownWords);
    }
    
    return $finalQuery ?: $text;
}

/**
 * Traduce usando MyMemory API (gratuito)
 */
function translateWithAPI($text) {
    $url = 'https://api.mymemory.translated.net/get?' . http_build_query([
        'q' => $text,
        'langpair' => 'es|en',
        'de' => 'admin@sistemax.com.py'
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['responseData']['translatedText'])) {
            $translated = $data['responseData']['translatedText'];
            if (stripos($translated, 'INVALID') === false && 
                stripos($translated, 'MYMEMORY WARNING') === false) {
                return strtolower($translated);
            }
        }
    }
    
    return null;
}

/**
 * Busca imágenes en Pixabay API (fuente principal - confiable)
 */
function searchPixabay($query, $page = 1, $perPage = 20) {
    $apiKey = PIXABAY_API_KEY;
    if (empty($apiKey)) {
        return null;
    }
    
    $url = "https://pixabay.com/api/?" . http_build_query([
        'key' => $apiKey,
        'q' => $query,
        'image_type' => 'photo',
        'per_page' => $perPage,
        'page' => $page,
        'safesearch' => 'true',
        'min_width' => 200,
        'lang' => 'es'
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SistemaX POS/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        error_log("Pixabay API error: HTTP $httpCode, cURL: $curlError, Response: " . substr($response, 0, 200));
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || empty($data['hits'])) {
        // Si hay error en la respuesta (API key inválida)
        if (isset($data['error'])) {
            error_log("Pixabay API error: " . $data['error']);
        }
        return null;
    }
    
    $images = [];
    foreach ($data['hits'] as $hit) {
        $images[] = [
            'id' => 'pixabay_' . $hit['id'],
            'thumbnail' => $hit['previewURL'],
            'preview' => $hit['webformatURL'],
            'large' => $hit['largeImageURL'] ?? $hit['webformatURL'],
            'tags' => $hit['tags'],
            'user' => $hit['user'],
            'pageURL' => $hit['pageURL']
        ];
    }
    
    return [
        'totalHits' => $data['totalHits'] ?? count($images),
        'hits' => $images
    ];
}

/**
 * Busca imágenes en Pexels API (alternativa a Pixabay)
 */
function searchPexels($query, $page = 1, $perPage = 20) {
    $apiKey = defined('PEXELS_API_KEY') ? PEXELS_API_KEY : '';
    if (empty($apiKey)) {
        return null;
    }
    
    $url = "https://api.pexels.com/v1/search?" . http_build_query([
        'query' => $query,
        'per_page' => $perPage,
        'page' => $page
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || empty($data['photos'])) {
        return null;
    }
    
    $images = [];
    foreach ($data['photos'] as $photo) {
        $images[] = [
            'id' => 'pexels_' . $photo['id'],
            'thumbnail' => $photo['src']['tiny'],
            'preview' => $photo['src']['medium'],
            'large' => $photo['src']['large'],
            'tags' => $photo['alt'] ?? $query,
            'user' => $photo['photographer'],
            'pageURL' => $photo['url']
        ];
    }
    
    return [
        'totalHits' => $data['total_results'] ?? count($images),
        'hits' => $images
    ];
}

/**
 * Genera URLs de imágenes de Unsplash Source (gratuito, sin API key)
 * Nota: Este servicio genera imágenes aleatorias basadas en el término de búsqueda
 */
function searchUnsplashSource($query, $page = 1, $perPage = 20) {
    $images = [];
    $baseUrl = 'https://source.unsplash.com';
    
    // Generar múltiples URLs con diferentes semillas para variedad
    $startIndex = ($page - 1) * $perPage;
    
    for ($i = 0; $i < $perPage; $i++) {
        $seed = $startIndex + $i;
        // Dimensiones fijas para consistencia
        $images[] = [
            'id' => "unsplash_{$seed}",
            'thumbnail' => "{$baseUrl}/150x150/?{$query}&sig={$seed}",
            'preview' => "{$baseUrl}/640x480/?{$query}&sig={$seed}",
            'large' => "{$baseUrl}/1280x960/?{$query}&sig={$seed}",
            'tags' => $query,
            'user' => 'Unsplash',
            'pageURL' => 'https://unsplash.com/s/photos/' . urlencode($query)
        ];
    }
    
    return [
        'totalHits' => 100, // Unsplash Source siempre puede generar más
        'hits' => $images
    ];
}

/**
 * Busca imágenes en Lorem Picsum (fotos de alta calidad, gratuitas)
 */
function searchLoremPicsum($query, $page = 1, $perPage = 20) {
    // Lorem Picsum no tiene búsqueda por texto, pero tiene una lista de imágenes
    $url = "https://picsum.photos/v2/list?page={$page}&limit={$perPage}";
    
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
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return null;
    }
    
    $images = [];
    foreach ($data as $item) {
        $images[] = [
            'id' => $item['id'],
            'thumbnail' => "https://picsum.photos/id/{$item['id']}/150/150",
            'preview' => "https://picsum.photos/id/{$item['id']}/640/480",
            'large' => "https://picsum.photos/id/{$item['id']}/1280/960",
            'tags' => $item['author'],
            'user' => $item['author'],
            'pageURL' => $item['url']
        ];
    }
    
    return [
        'totalHits' => 1000,
        'hits' => $images
    ];
}

/**
 * Busca imágenes usando DuckDuckGo Images (scraping básico)
 */
function searchDuckDuckGo($query, $page = 1, $perPage = 20) {
    $vqd = getDuckDuckGoVQD($query);
    if (!$vqd) {
        return null;
    }
    
    $offset = ($page - 1) * $perPage;
    $url = "https://duckduckgo.com/i.js?" . http_build_query([
        'l' => 'us-en',
        'o' => 'json',
        'q' => $query,
        'vqd' => $vqd,
        'f' => ',,,',
        'p' => '1',
        's' => $offset
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
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
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['results'])) {
        return null;
    }
    
    $images = [];
    foreach (array_slice($data['results'], 0, $perPage) as $item) {
        $images[] = [
            'id' => md5($item['image']),
            'thumbnail' => $item['thumbnail'],
            'preview' => $item['image'],
            'large' => $item['image'],
            'tags' => $item['title'] ?? $query,
            'user' => parse_url($item['source'] ?? '', PHP_URL_HOST) ?: 'Web',
            'pageURL' => $item['url'] ?? '#'
        ];
    }
    
    return [
        'totalHits' => count($data['results']) + ($page * $perPage),
        'hits' => $images
    ];
}

/**
 * Obtiene el token VQD de DuckDuckGo necesario para búsquedas
 */
function getDuckDuckGoVQD($query) {
    $url = "https://duckduckgo.com/?" . http_build_query(['q' => $query, 'iar' => 'images']);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (preg_match('/vqd=([^&"\']+)/', $response, $matches)) {
        return $matches[1];
    }
    
    return null;
}

// Procesar solicitud
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido']);
        exit;
    }
    
    $query = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    
    if (empty($query)) {
        echo json_encode(['success' => false, 'error' => 'Parámetro de búsqueda requerido']);
        exit;
    }
    
    // Traducir de español a inglés
    $originalQuery = $query;
    $translatedQuery = translateToEnglish($query);
    
    $result = null;
    $source = 'none';
    
    // 1. Intentar con Pixabay primero (si tiene API key válida)
    $result = searchPixabay($translatedQuery, $page);
    if ($result && !empty($result['hits'])) {
        $source = 'pixabay';
    }
    
    // 2. Si Pixabay falla, intentar con Pexels (si tiene API key)
    if (!$result || empty($result['hits'])) {
        $result = searchPexels($translatedQuery, $page);
        if ($result && !empty($result['hits'])) {
            $source = 'pexels';
        }
    }
    
    // 3. Si ninguna API funciona, intentar DuckDuckGo
    if (!$result || empty($result['hits'])) {
        $result = searchDuckDuckGo($translatedQuery, $page);
        if ($result && !empty($result['hits'])) {
            $source = 'duckduckgo';
        }
    }
    
    // 4. Intentar con query original (marcas) en DuckDuckGo
    if ((!$result || empty($result['hits'])) && $translatedQuery !== $originalQuery) {
        $result = searchDuckDuckGo($originalQuery, $page);
        if ($result && !empty($result['hits'])) {
            $source = 'duckduckgo';
        }
    }
    
    // 5. Último recurso: Unsplash Source (siempre funciona, sin API key)
    if (!$result || empty($result['hits'])) {
        $result = searchUnsplashSource($translatedQuery, $page);
        if ($result && !empty($result['hits'])) {
            $source = 'unsplash';
        }
    }
    
    // Si ninguna fuente encontró resultados (muy raro con Unsplash Source)
    if (!$result || empty($result['hits'])) {
        echo json_encode([
            'success' => true,
            'original_query' => $originalQuery,
            'translated_query' => $translatedQuery,
            'source' => 'none',
            'total' => 0,
            'page' => $page,
            'per_page' => IMAGES_PER_PAGE,
            'images' => [],
            'message' => 'No se encontraron imágenes relevantes. Intenta buscar en Google Images.'
        ]);
        exit;
    }
    
    // Formatear respuesta
    $images = [];
    if (isset($result['hits']) && is_array($result['hits'])) {
        foreach ($result['hits'] as $hit) {
            $images[] = [
                'id' => $hit['id'],
                'thumbnail' => $hit['thumbnail'],
                'preview' => $hit['preview'],
                'large' => $hit['large'],
                'tags' => $hit['tags'],
                'user' => $hit['user'],
                'pageURL' => $hit['pageURL']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'original_query' => $originalQuery,
        'translated_query' => $translatedQuery,
        'source' => $source,
        'total' => $result['totalHits'] ?? count($images),
        'page' => $page,
        'per_page' => IMAGES_PER_PAGE,
        'images' => $images
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
