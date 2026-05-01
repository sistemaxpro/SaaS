<?php
/**
 * OCR Factura API
 * Extrae datos de factura desde imagen con OpenAI o Gemini.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/gemini_lib.php';

function getGoogleVisionConfig(): array
{
    $cfg = [
        'api_key' => trim((string)getenv('GOOGLE_CLOUD_VISION_API_KEY')),
        'credentials_path' => trim((string)getenv('GOOGLE_CLOUD_VISION_CREDENTIALS')),
    ];

    $cfgPath = __DIR__ . '/../config/google_vision.php';
    if (is_file($cfgPath)) {
        $fileCfg = include $cfgPath;
        if (is_array($fileCfg)) {
            if (($cfg['api_key'] ?? '') === '' && !empty($fileCfg['api_key'])) {
                $cfg['api_key'] = trim((string)$fileCfg['api_key']);
            }
            if (($cfg['credentials_path'] ?? '') === '' && !empty($fileCfg['credentials_path'])) {
                $cfg['credentials_path'] = trim((string)$fileCfg['credentials_path']);
            }
        }
    }

    if (($cfg['credentials_path'] ?? '') === '') {
        $defaultCreds = __DIR__ . '/../config/google_vision_credentials.json';
        if (is_file($defaultCreds)) {
            $cfg['credentials_path'] = $defaultCreds;
        }
    }

    return $cfg;
}

function getGoogleAccessTokenFromServiceAccount(string $credentialsPath): string
{
    if (!is_file($credentialsPath)) {
        throw new Exception('No se encontró el archivo de credenciales de Google Vision.');
    }

    $json = json_decode((string)file_get_contents($credentialsPath), true);
    if (!is_array($json) || empty($json['client_email']) || empty($json['private_key']) || empty($json['token_uri'])) {
        throw new Exception('Credenciales de Google Vision inválidas.');
    }

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $now = time();
    $claim = [
        'iss' => $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud' => $json['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600
    ];

    $base64Url = static function (string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $segments = [
        $base64Url(json_encode($header, JSON_UNESCAPED_SLASHES)),
        $base64Url(json_encode($claim, JSON_UNESCAPED_SLASHES))
    ];
    $signingInput = implode('.', $segments);
    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $json['private_key'], OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new Exception('No se pudo firmar el token de Google Vision.');
    }
    $jwt = $signingInput . '.' . $base64Url($signature);

    $ch = curl_init($json['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Error obteniendo token de Google Vision: ' . $curlError);
    }
    $data = json_decode((string)$response, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        $msg = $data['error_description'] ?? $data['error'] ?? ('HTTP ' . $httpCode);
        throw new Exception('No se pudo obtener token de Google Vision: ' . $msg);
    }

    return (string)$data['access_token'];
}

function askGoogleVisionDocumentText(string $imageBase64, string $mimeType): array
{
    $cfg = getGoogleVisionConfig();
    $endpoint = 'https://vision.googleapis.com/v1/images:annotate';
    $headers = ['Content-Type: application/json'];

    if (($cfg['api_key'] ?? '') !== '') {
        $endpoint .= '?key=' . urlencode($cfg['api_key']);
    } elseif (($cfg['credentials_path'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . getGoogleAccessTokenFromServiceAccount($cfg['credentials_path']);
    } else {
        throw new Exception('Google Vision no está configurado. Falta GOOGLE_CLOUD_VISION_API_KEY o credenciales de servicio.');
    }

    $payload = [
        'requests' => [[
            'image' => ['content' => $imageBase64],
            'features' => [[
                'type' => 'DOCUMENT_TEXT_DETECTION',
                'maxResults' => 1
            ]],
            'imageContext' => [
                'languageHints' => ['es', 'en']
            ]
        ]]
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 35
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Error de conexión con Google Vision: ' . $curlError);
    }

    $data = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new Exception('Google Vision OCR: ' . $msg);
    }

    $res = $data['responses'][0] ?? [];
    if (!empty($res['error']['message'])) {
        throw new Exception('Google Vision OCR: ' . $res['error']['message']);
    }

    return $res;
}

function getOpenAiApiKey(): string
{
    $apiKey = trim((string)getenv('OPENAI_API_KEY'));
    if ($apiKey !== '') {
        return $apiKey;
    }

    $cfgPath = __DIR__ . '/../config/openai.php';
    if (is_file($cfgPath)) {
        $cfg = include $cfgPath;
        $apiKey = trim((string)($cfg['api_key'] ?? ''));
    }

    return $apiKey;
}

function getOpenAiModel(): string
{
    $model = trim((string)getenv('OPENAI_OCR_MODEL'));
    if ($model === '') {
        $model = trim((string)getenv('OPENAI_MODEL'));
    }
    if ($model === '') {
        $model = 'gpt-4.1';
    }
    return $model;
}

function askOpenAiVision(string $prompt, string $imageBase64, string $mimeType): string
{
    $openAiApiKey = getOpenAiApiKey();
    if ($openAiApiKey === '') {
        throw new Exception('No hay API key de OpenAI configurada para OCR.');
    }

    $payload = [
        'model' => getOpenAiModel(),
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$imageBase64}",
                            'detail' => 'high'
                        ]
                    ]
                ]
            ]
        ],
        'max_tokens' => 2000,
        'temperature' => 0.1
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $openAiApiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 35
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Error de conexión: $curlError");
    }

    $result = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $errorMsg = $result['error']['message'] ?? 'Error desconocido de OpenAI';
        throw new Exception("Error OCR IA ($httpCode): $errorMsg");
    }

    return (string)($result['choices'][0]['message']['content'] ?? '');
}

function getOllamaChatUrl(): string
{
    $url = trim((string)getenv('OLLAMA_URL'));
    if ($url === '') {
        $url = 'http://127.0.0.1:11434/api/chat';
    }
    $url = preg_replace('#/api/generate$#i', '/api/chat', $url);
    return (string)$url;
}

function getOllamaOcrModel(): string
{
    $model = trim((string)getenv('OLLAMA_OCR_MODEL'));
    if ($model === '') {
        $model = trim((string)getenv('OLLAMA_MODEL'));
    }
    if ($model === '') {
        // Debe ser un modelo con visión cargado en Ollama.
        $model = 'llava:latest';
    }
    return $model;
}

function askOllamaVision(string $prompt, string $imageBase64): string
{
    $ollamaUrl = getOllamaChatUrl();
    $ollamaModel = getOllamaOcrModel();
    $timeoutSec = (int)(getenv('OLLAMA_TIMEOUT') ?: 55);
    if ($timeoutSec < 10) $timeoutSec = 10;
    if ($timeoutSec > 45) $timeoutSec = 45;

    $run = static function (string $msgPrompt) use ($ollamaUrl, $ollamaModel, $imageBase64, $timeoutSec): string {
        $payload = [
            'model' => $ollamaModel,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $msgPrompt,
                    'images' => [$imageBase64]
                ]
            ],
            'stream' => false,
            'options' => [
                'temperature' => 0.1,
                'num_ctx' => 3072
            ]
        ];

        $ch = curl_init($ollamaUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 6
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Error de conexión con Ollama: ' . $curlError);
        }
        if ($httpCode !== 200) {
            $data = json_decode((string)$response, true);
            $err = trim((string)($data['error'] ?? ''));
            if ($err === '') $err = 'Ollama HTTP ' . $httpCode;
            throw new Exception($err);
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new Exception('Respuesta inválida de Ollama');
        }
        $content = trim((string)($data['message']['content'] ?? ''));
        if ($content === '') {
            throw new Exception('Ollama devolvió respuesta vacía');
        }
        return $content;
    };

    return $run($prompt);
}

function isQuotaOrRateLimitError(string $msg): bool
{
    return (bool)preg_match('/\b429\b|quota exceeded|rate limit|exceeded your current quota|resource exhausted|insufficient_quota|billing|credit balance is too low/i', $msg);
}

function normalizeOcrErrorMessage(string $msg): string
{
    $m = trim($msg);
    if ($m === '') {
        return 'No se pudo procesar la factura con IA.';
    }

    $hasQuota = isQuotaOrRateLimitError($m);
    $hasOllamaIssue = (stripos($m, 'ollama') !== false || stripos($m, 'model not found') !== false);
    $hasTimeout = (stripos($m, 'timed out') !== false || stripos($m, 'timeout') !== false);

    if ($hasQuota && $hasOllamaIssue && $hasTimeout) {
        return 'Sin cuota en OpenAI/Gemini y Ollama tardó demasiado en responder. Reintente en unos segundos.';
    }

    if ($hasQuota && $hasOllamaIssue) {
        return 'Sin cuota en OpenAI/Gemini y Ollama no respondió con OCR. Verifique Ollama activo y modelo de visión (ej: OLLAMA_OCR_MODEL=llava).';
    }

    if ($hasOllamaIssue && $hasTimeout) {
        return 'Ollama está activo y el modelo de visión existe, pero tardó demasiado en responder OCR. Reintentá con una imagen más liviana o en unos segundos.';
    }

    if ($hasOllamaIssue) {
        return 'OCR IA en Ollama no respondió correctamente. Verificá servicio activo y modelo de visión (ej: OLLAMA_OCR_MODEL=llava:latest).';
    }

    if (stripos($m, 'google vision') !== false) {
        return 'Google Vision OCR no respondió correctamente. Verificá API key o credenciales del servicio.';
    }

    if (stripos($m, 'no logró extraer los ítems') !== false) {
        return 'OCR rápido detectó cabecera pero no los ítems. Probá modo OCR IA (preciso) para extraer productos.';
    }
    if (stripos($m, 'tesseract') !== false) {
        return 'OCR rápido local no disponible o sin datos suficientes. Se intentó fallback con IA.';
    }

    if (isQuotaOrRateLimitError($m)) {
        return 'OCR IA temporalmente sin cuota disponible. Recargue saldo/cupo de OpenAI o Gemini y vuelva a intentar.';
    }

    if (stripos($m, 'No hay API key de OpenAI') !== false) {
        return 'OCR IA no configurado. Falta API key válida con cuota activa.';
    }

    if (
        stripos($m, 'Error de conexión') !== false ||
        stripos($m, 'timed out') !== false ||
        stripos($m, 'timeout') !== false
    ) {
        return 'El servicio OCR IA respondió demasiado lento. Reintentá con una imagen más liviana o en unos minutos.';
    }

    return $m;
}

function isLikelyTemplateOcrResult(array $invoiceData): bool
{
    $toLower = static function (string $v): string {
        $v = trim($v);
        return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    };

    $proveedor = $toLower((string)($invoiceData['proveedor'] ?? ''));
    $ruc = $toLower((string)($invoiceData['ruc'] ?? ''));
    $nro = $toLower((string)($invoiceData['nro_factura'] ?? ''));
    $timbrado = $toLower((string)($invoiceData['timbrado'] ?? ''));
    $fecha = $toLower((string)($invoiceData['fecha'] ?? ''));
    $items = $invoiceData['items'] ?? [];
    $firstDesc = '';
    if (is_array($items) && isset($items[0]) && is_array($items[0])) {
        $firstDesc = $toLower((string)($items[0]['descripcion'] ?? ''));
    }

    $hits = 0;
    if ($proveedor === 'nombre del emisor' || str_contains($proveedor, 'emisor')) $hits++;
    if (str_contains($ruc, 'sin dv')) $hits++;
    if (str_contains($nro, '001-001-0000123')) $hits++;
    if (str_contains($timbrado, '8 dígitos') || str_contains($timbrado, '8 digitos')) $hits++;
    if ($fecha === 'yyyy-mm-dd') $hits++;
    if ($firstDesc === '' || str_contains($firstDesc, 'descripción completa')) $hits++;

    return $hits >= 3;
}

function isSuspiciousInvoiceItemDescription(string $desc): bool
{
    $d = trim($desc);
    if ($d === '') {
        return true;
    }
    if (!preg_match('/[a-záéíóúñ]/iu', $d)) {
        return true;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($d, 'UTF-8') : strlen($d);
    if ($len < 4) {
        return true;
    }

    return (bool)preg_match(
        '/^(tel[eé]fono|telefono|ciudad|cliente|ruc|correo|cajero|fecha|timbrado|cdc|p[aá]g\.?|tipo\s+de\s+transacci[oó]n|motivo|actividad|total|subtotal|exenta|gravada|iva|condici[oó]n\s+de\s+venta|vencimiento|documento)\b/iu',
        $d
    );
}

function sanitizeInvoiceItems(array $items): array
{
    $clean = [];
    $seen = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $descripcion = trim((string)($item['descripcion'] ?? ''));
        if (isSuspiciousInvoiceItemDescription($descripcion)) {
            continue;
        }

        $cantidad = (float)($item['cantidad'] ?? 1);
        $costo = (float)($item['costo'] ?? 0);
        if ($cantidad <= 0 || $costo <= 0) {
            continue;
        }

        $key = strtolower(preg_replace('/\s+/', ' ', $descripcion));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $tipoIva = (int)($item['tipo_iva'] ?? 3);
        if (!in_array($tipoIva, [1, 2, 3], true)) {
            $tipoIva = 3;
        }

        $clean[] = [
            'codigo' => trim((string)($item['codigo'] ?? '')),
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'costo' => $costo,
            'tipo_iva' => $tipoIva
        ];
    }

    return array_slice($clean, 0, 80);
}

function validateInvoicePayload(array $invoiceData, string $context = 'ocr'): array
{
    $invoiceData = applySupplierTemplate($invoiceData);
    $invoiceData['proveedor'] = trim((string)($invoiceData['proveedor'] ?? ''));
    $invoiceData['ruc'] = trim((string)($invoiceData['ruc'] ?? ''));
    $invoiceData['nro_factura'] = trim((string)($invoiceData['nro_factura'] ?? ''));
    $invoiceData['timbrado'] = trim((string)($invoiceData['timbrado'] ?? ''));
    $invoiceData['fecha'] = trim((string)($invoiceData['fecha'] ?? date('Y-m-d'))) ?: date('Y-m-d');
    $invoiceData['items'] = sanitizeInvoiceItems(is_array($invoiceData['items'] ?? null) ? $invoiceData['items'] : []);
    $invoiceData['exenta'] = (float)($invoiceData['exenta'] ?? 0);
    $invoiceData['iva5'] = (float)($invoiceData['iva5'] ?? 0);
    $invoiceData['iva10'] = (float)($invoiceData['iva10'] ?? 0);
    $invoiceData['total'] = (float)($invoiceData['total'] ?? 0);

    $headerScore = 0;
    foreach (['proveedor', 'ruc', 'nro_factura', 'timbrado'] as $field) {
        if ($invoiceData[$field] !== '') {
            $headerScore++;
        }
    }

    $allowHeaderOnly = in_array($context, ['google vision', 'google vision header'], true);
    if (empty($invoiceData['items']) && !$allowHeaderOnly) {
        throw new Exception("OCR {$context} devolvió datos poco confiables: no se detectaron ítems válidos.");
    }
    if ($headerScore < 2) {
        throw new Exception("OCR {$context} devolvió datos poco confiables: faltan datos clave del encabezado.");
    }

    return $invoiceData;
}

function normalizeTemplateText(string $value): string
{
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
    return trim((string)$value);
}

function detectSupplierTemplate(array $invoiceData): ?array
{
    $provider = normalizeTemplateText((string)($invoiceData['proveedor'] ?? ''));
    $ruc = preg_replace('/\D+/', '', (string)($invoiceData['ruc'] ?? ''));

    $templates = [
        [
            'id' => 'stock',
            'provider_contains' => ['stock', 'stock supermerc', 'stock super'],
            'normalize_provider' => 'Stock',
            'strip_prefixes' => ['cod ', 'codigo ', 'art ', 'item '],
            'strip_words' => ['unidad', 'und', 'promo']
        ],
        [
            'id' => 'superseis',
            'provider_contains' => ['super seis', 'superseis'],
            'normalize_provider' => 'Superseis',
            'strip_prefixes' => ['cod ', 'codigo ', 'art '],
            'strip_words' => ['unidad', 'und', 'ahorro']
        ],
        [
            'id' => 'biggie',
            'provider_contains' => ['biggie'],
            'normalize_provider' => 'Biggie',
            'strip_prefixes' => ['cod ', 'codigo ', 'sku '],
            'strip_words' => ['promo', 'combo']
        ],
        [
            'id' => 'fuel',
            'provider_contains' => ['copetrol', 'petropar', 'shell', 'petromax'],
            'normalize_provider' => '',
            'force_keywords' => ['diesel', 'nafta', 'aditivada', 'comun'],
            'strip_prefixes' => ['producto ', 'art '],
            'strip_words' => ['litros', 'lts']
        ],
        [
            'id' => 'generic_kude',
            'provider_contains' => ['kude'],
            'normalize_provider' => '',
            'strip_prefixes' => ['cod ', 'codigo ', 'item '],
            'strip_words' => ['unidad', 'und']
        ]
    ];

    foreach ($templates as $template) {
        foreach ($template['provider_contains'] as $needle) {
            if ($needle !== '' && str_contains($provider, normalizeTemplateText($needle))) {
                return $template;
            }
        }
        if ($ruc !== '' && !empty($template['ruc']) && in_array($ruc, $template['ruc'], true)) {
            return $template;
        }
    }

    return null;
}

function applySupplierTemplate(array $invoiceData): array
{
    $template = detectSupplierTemplate($invoiceData);
    if ($template === null) {
        return $invoiceData;
    }

    if (!empty($template['normalize_provider'])) {
        $invoiceData['proveedor'] = $template['normalize_provider'];
    }

    $items = is_array($invoiceData['items'] ?? null) ? $invoiceData['items'] : [];
    foreach ($items as &$item) {
        if (!is_array($item)) {
            continue;
        }
        $desc = trim((string)($item['descripcion'] ?? ''));
        if ($desc === '') {
            continue;
        }

        foreach (($template['strip_prefixes'] ?? []) as $prefix) {
            $desc = preg_replace('/^' . preg_quote($prefix, '/') . '\s*/iu', '', $desc);
        }
        foreach (($template['strip_words'] ?? []) as $word) {
            $desc = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', ' ', $desc);
        }

        $desc = preg_replace('/\s{2,}/', ' ', (string)$desc);
        $desc = trim((string)$desc, " -\t\n\r\0\x0B");

        if (($template['id'] ?? '') === 'fuel' && $desc !== '') {
            $normalizedDesc = normalizeTemplateText($desc);
            $hasFuelWord = false;
            foreach (($template['force_keywords'] ?? []) as $kw) {
                if (str_contains($normalizedDesc, normalizeTemplateText($kw))) {
                    $hasFuelWord = true;
                    break;
                }
            }
            if (!$hasFuelWord && preg_match('/^\d+([.,]\d+)?$/', $desc)) {
                $desc = '';
            }
        }

        $item['descripcion'] = $desc;
    }
    unset($item);

    $invoiceData['items'] = $items;
    $invoiceData['_ocr_template'] = $template['id'] ?? null;
    return $invoiceData;
}

function cmdExists(string $cmd): bool
{
    $out = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
    return trim((string)$out) !== '';
}

function normalizeMoneyToFloat(string $raw): float
{
    $v = trim($raw);
    $v = str_replace(["\xc2\xa0", ' '], '', $v); // NBSP + spaces
    if ($v === '') return 0.0;
    // 12.345,67 -> 12345.67 ; 12,345.67 -> 12345.67 ; 12345 -> 12345
    if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $v)) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (preg_match('/^\d{1,3}(,\d{3})+\.\d+$/', $v)) {
        $v = str_replace(',', '', $v);
    } else {
        $v = str_replace(',', '.', $v);
    }
    return (float)$v;
}

function normalizeDatePy(string $raw): ?string
{
    $v = trim($raw);
    if ($v === '') return null;

    if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $v, $m)) {
        $yy = (int)$m[3];
        if ($yy < 100) $yy += 2000;
        return sprintf('%04d-%02d-%02d', $yy, (int)$m[2], (int)$m[1]);
    }
    return null;
}

function parseInvoiceFromPlainText(string $text): array
{
    $rows = preg_split('/\R+/', $text) ?: [];
    $lines = [];
    foreach ($rows as $r) {
        $line = trim((string)$r);
        if ($line === '') continue;
        $line = preg_replace('/\s{2,}/', ' ', $line);
        $lines[] = $line;
    }

    $invoice = [
        'proveedor' => '',
        'ruc' => '',
        'nro_factura' => '',
        'timbrado' => '',
        'fecha' => date('Y-m-d'),
        'vencimiento_timbrado' => null,
        'items' => [],
        'exenta' => 0,
        'iva5' => 0,
        'iva10' => 0,
        'total' => 0
    ];

    $haystack = implode("\n", $lines);

    if (preg_match('/\b(\d{3}\s*[-.]\s*\d{3}\s*[-.]\s*\d{6,8})\b/u', $haystack, $m)) {
        $invoice['nro_factura'] = preg_replace('/\s+/', '', str_replace('.', '-', $m[1]));
    }
    if (preg_match('/timbrado\s*[:#]?\s*(\d{6,10})/iu', $haystack, $m)) {
        $invoice['timbrado'] = trim($m[1]);
    } elseif (preg_match('/\b(\d{8})\b/u', $haystack, $m)) {
        $invoice['timbrado'] = trim($m[1]);
    }
    if (preg_match('/ruc\s*[:#]?\s*([0-9.\-]{6,20})/iu', $haystack, $m)) {
        $invoice['ruc'] = trim($m[1]);
    }
    if (preg_match('/\b(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}|\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2})\b/u', $haystack, $m)) {
        $maybeDate = normalizeDatePy($m[1]);
        if ($maybeDate) $invoice['fecha'] = $maybeDate;
    }

    foreach (array_slice($lines, 0, 12) as $line) {
        $l = strtolower($line);
        if (preg_match('/ruc|timbrado|factura|comprobante|iva|fecha|original|copia/i', $l)) continue;
        if (preg_match('/^[0-9\W]+$/', $line)) continue;
        $len = function_exists('mb_strlen') ? mb_strlen($line) : strlen($line);
        if ($len < 4) continue;
        $invoice['proveedor'] = trim($line);
        break;
    }

    $items = [];
    $seenDesc = [];
    foreach ($lines as $line) {
        $lower = strtolower($line);
        if (!preg_match('/[a-záéíóúñ]/iu', $line)) continue;
        if (!preg_match('/\d/', $line)) continue;
        if (preg_match('/timbrado|ruc|factura|total|subtotal|exenta|gravada|iva|fecha|cajero|cliente|telefono/i', $lower)) continue;

        if (preg_match('/^\s*(\d{1,4}(?:[.,]\d{1,3})?)\s+(.+?)\s+(\d{1,3}(?:[.\s]\d{3})*(?:[.,]\d{1,2})?)\s*$/u', $line, $m)) {
            $qty = max(0.01, normalizeMoneyToFloat($m[1]));
            $desc = trim($m[2]);
            $cost = max(0, normalizeMoneyToFloat($m[3]));
            if ($desc !== '' && $cost > 0) {
                $tipoIva = str_contains($lower, 'exenta') ? 1 : (str_contains($lower, '5%') ? 2 : 3);
                $k = strtolower(preg_replace('/\s+/', ' ', $desc));
                if (isset($seenDesc[$k])) continue;
                $seenDesc[$k] = true;
                $items[] = [
                    'codigo' => '',
                    'descripcion' => $desc,
                    'cantidad' => $qty,
                    'costo' => $cost,
                    'tipo_iva' => $tipoIva
                ];
            }
            continue;
        }

        // Patrón más flexible: línea con texto + al menos 2 números (cantidad y precio/costo).
        preg_match_all('/\d{1,3}(?:[.\s]\d{3})*(?:[.,]\d+)?|\d+(?:[.,]\d+)?/u', $line, $numM);
        $nums = $numM[0] ?? [];
        if (count($nums) >= 2) {
            $qtyRaw = $nums[0];
            $costRaw = $nums[count($nums) - 1];
            $qty = max(0.01, normalizeMoneyToFloat($qtyRaw));
            $cost = max(0, normalizeMoneyToFloat($costRaw));

            $desc = preg_replace('/\d{1,3}(?:[.\s]\d{3})*(?:[.,]\d+)?|\d+(?:[.,]\d+)?/u', ' ', $line);
            $desc = trim(preg_replace('/\s{2,}/', ' ', (string)$desc));
            if (strlen($desc) > 4 && $cost > 0) {
                $tipoIva = str_contains($lower, 'exenta') ? 1 : (str_contains($lower, '5%') ? 2 : 3);
                $k = strtolower(preg_replace('/\s+/', ' ', $desc));
                if (isset($seenDesc[$k])) continue;
                $seenDesc[$k] = true;
                $items[] = [
                    'codigo' => '',
                    'descripcion' => $desc,
                    'cantidad' => $qty,
                    'costo' => $cost,
                    'tipo_iva' => $tipoIva
                ];
            }
        }
    }
    if (count($items) > 0) {
        $invoice['items'] = array_slice($items, 0, 80);
    }

    if (preg_match('/\btotal\s*[: ]\s*([0-9][0-9.\s,]+)/iu', $haystack, $m)) {
        $invoice['total'] = normalizeMoneyToFloat($m[1]);
    }
    if (preg_match('/\b(?:iva\s*10|gravada\s*10)\s*[: ]\s*([0-9][0-9.\s,]+)/iu', $haystack, $m)) {
        $invoice['iva10'] = normalizeMoneyToFloat($m[1]);
    }
    if (preg_match('/\b(?:iva\s*5|gravada\s*5)\s*[: ]\s*([0-9][0-9.\s,]+)/iu', $haystack, $m)) {
        $invoice['iva5'] = normalizeMoneyToFloat($m[1]);
    }
    if (preg_match('/\bexenta\s*[: ]\s*([0-9][0-9.\s,]+)/iu', $haystack, $m)) {
        $invoice['exenta'] = normalizeMoneyToFloat($m[1]);
    }

    return $invoice;
}

function parseInvoiceFromGoogleVisionText(string $text): array
{
    $invoice = parseInvoiceFromPlainText($text);
    $rows = preg_split('/\R+/', $text) ?: [];
    $lines = [];
    foreach ($rows as $r) {
        $line = trim(preg_replace('/\s{2,}/', ' ', (string)$r));
        if ($line === '') {
            continue;
        }
        $lines[] = $line;
    }

    $items = [];
    $seen = [];

    foreach ($lines as $line) {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($line, 'UTF-8') : strtolower($line);
        if (preg_match('/^(ruc|timbrado|factura|comprobante|fecha|cliente|telefono|tel[eé]fono|correo|ciudad|cdc|total|subtotal|iva|exenta|gravada)\b/iu', $lower)) {
            continue;
        }

        // 2 ARROZ 15000
        if (preg_match('/^(\d+(?:[.,]\d{1,3})?)\s+(.+?)\s+(\d[\d.,]{2,})$/u', $line, $m)) {
            $qty = normalizeMoneyToFloat((string)$m[1]);
            $desc = trim((string)$m[2], " -\t\n\r\0\x0B");
            $cost = normalizeMoneyToFloat((string)$m[3]);

            if ($desc !== '' && $qty > 0 && $cost > 0 && !isSuspiciousInvoiceItemDescription($desc)) {
                $key = strtolower(preg_replace('/\s+/', ' ', $desc));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $items[] = [
                        'codigo' => '',
                        'descripcion' => $desc,
                        'cantidad' => $qty,
                        'costo' => $cost,
                        'tipo_iva' => 3
                    ];
                    continue;
                }
            }
        }

        // ARROZ 15000 30000 2  => desc unitario importe cantidad
        if (preg_match('/^(.+?)\s+(\d[\d.,]{2,})\s+(\d[\d.,]{2,})\s+(\d+(?:[.,]\d{1,3})?)$/u', $line, $m)) {
            $desc = trim((string)$m[1], " -\t\n\r\0\x0B");
            $unit = normalizeMoneyToFloat((string)$m[2]);
            $qty = normalizeMoneyToFloat((string)$m[4]);
            if ($desc !== '' && $qty > 0 && $unit > 0 && !isSuspiciousInvoiceItemDescription($desc)) {
                $key = strtolower(preg_replace('/\s+/', ' ', $desc));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $items[] = [
                        'codigo' => '',
                        'descripcion' => $desc,
                        'cantidad' => $qty,
                        'costo' => $unit,
                        'tipo_iva' => 3
                    ];
                    continue;
                }
            }
        }

        // ARROZ 2 x 15000
        if (preg_match('/^(.+?)\s+(\d+(?:[.,]\d{1,3})?)\s*[xX]\s*(\d[\d.,]{2,})$/u', $line, $m)) {
            $desc = trim((string)$m[1], " -\t\n\r\0\x0B");
            $qty = normalizeMoneyToFloat((string)$m[2]);
            $cost = normalizeMoneyToFloat((string)$m[3]);
            if ($desc !== '' && $qty > 0 && $cost > 0 && !isSuspiciousInvoiceItemDescription($desc)) {
                $key = strtolower(preg_replace('/\s+/', ' ', $desc));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $items[] = [
                        'codigo' => '',
                        'descripcion' => $desc,
                        'cantidad' => $qty,
                        'costo' => $cost,
                        'tipo_iva' => 3
                    ];
                    continue;
                }
            }
        }

        if (preg_match('/^(.+?)\s+(\d+(?:[.,]\d{1,3})?)\s+(\d[\d.,]{2,})$/u', $line, $m)) {
            $desc = trim((string)$m[1], " -\t\n\r\0\x0B");
            $qty = normalizeMoneyToFloat((string)$m[2]);
            $cost = normalizeMoneyToFloat((string)$m[3]);

            if ($desc !== '' && $qty > 0 && $cost > 0 && !isSuspiciousInvoiceItemDescription($desc)) {
                $key = strtolower(preg_replace('/\s+/', ' ', $desc));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $items[] = [
                        'codigo' => '',
                        'descripcion' => $desc,
                        'cantidad' => $qty,
                        'costo' => $cost,
                        'tipo_iva' => 3
                    ];
                }
            }
        }
    }

    if (!empty($items)) {
        $invoice['items'] = $items;
    }

    return $invoice;
}

function runFastTesseractOcr(string $imageBase64, string $mimeType): array
{
    if (!cmdExists('tesseract')) {
        throw new Exception('tesseract no está instalado en el servidor');
    }
    $ext = 'jpg';
    if (stripos($mimeType, 'png') !== false) $ext = 'png';
    if (stripos($mimeType, 'webp') !== false) $ext = 'webp';
    if (stripos($mimeType, 'bmp') !== false) $ext = 'bmp';
    if (stripos($mimeType, 'gif') !== false) $ext = 'gif';

    $tmpIn = tempnam(sys_get_temp_dir(), 'ocr_in_');
    if ($tmpIn === false) {
        throw new Exception('No se pudo crear archivo temporal para OCR');
    }
    $tmpImg = $tmpIn . '.' . $ext;
    rename($tmpIn, $tmpImg);
    file_put_contents($tmpImg, base64_decode($imageBase64));

    $cmd = 'timeout 20s tesseract ' . escapeshellarg($tmpImg) . ' stdout -l spa+eng --dpi 300 --psm 6 2>/dev/null';
    $text = (string)@shell_exec($cmd);
    @unlink($tmpImg);

    $text = trim($text);
    if ($text === '') {
        throw new Exception('Tesseract devolvió texto vacío');
    }

    $data = parseInvoiceFromPlainText($text);
    if (count($data['items']) === 0) {
        // Reintentos con otros modos de segmentación, útiles para tablas.
        $tmpIn2 = tempnam(sys_get_temp_dir(), 'ocr_in_');
        if ($tmpIn2 !== false) {
            $tmpImg2 = $tmpIn2 . '.' . $ext;
            rename($tmpIn2, $tmpImg2);
            file_put_contents($tmpImg2, base64_decode($imageBase64));
            $text4 = (string)@shell_exec('timeout 20s tesseract ' . escapeshellarg($tmpImg2) . ' stdout -l spa+eng --dpi 300 --psm 4 2>/dev/null');
            $text11 = (string)@shell_exec('timeout 20s tesseract ' . escapeshellarg($tmpImg2) . ' stdout -l spa+eng --dpi 300 --psm 11 2>/dev/null');
            @unlink($tmpImg2);

            $alt = parseInvoiceFromPlainText(trim($text4 . "\n" . $text11));
            if (count($alt['items']) > count($data['items'])) {
                $data['items'] = $alt['items'];
            }
            foreach (['proveedor', 'ruc', 'nro_factura', 'timbrado'] as $k) {
                if (trim((string)$data[$k]) === '' && trim((string)$alt[$k]) !== '') {
                    $data[$k] = $alt[$k];
                }
            }
            foreach (['total', 'iva5', 'iva10', 'exenta'] as $k) {
                if ((float)$data[$k] <= 0 && (float)$alt[$k] > 0) {
                    $data[$k] = $alt[$k];
                }
            }
        }
    }

    if (count($data['items']) === 0) {
        throw new Exception('Tesseract no logró extraer los ítems de la factura');
    }

    $filled = 0;
    foreach (['proveedor', 'ruc', 'nro_factura', 'timbrado'] as $k) {
        if (trim((string)$data[$k]) !== '') $filled++;
    }
    if ($filled < 2 && count($data['items']) === 0) {
        throw new Exception('Tesseract no logró extraer datos suficientes');
    }

    return $data;
}

function parseInvoiceFromPdfText(string $text): array
{
    $rows = preg_split('/\R+/', $text) ?: [];
    $lines = [];
    foreach ($rows as $r) {
        $line = trim((string)$r);
        if ($line === '') continue;
        $lines[] = $line;
    }

    $invoice = [
        'proveedor' => '',
        'ruc' => '',
        'nro_factura' => '',
        'timbrado' => '',
        'fecha' => date('Y-m-d'),
        'vencimiento_timbrado' => null,
        'items' => [],
        'exenta' => 0,
        'iva5' => 0,
        'iva10' => 0,
        'total' => 0
    ];

    $haystack = implode("\n", $lines);
    if (preg_match('/\b(\d{3}\s*[-.]\s*\d{3}\s*[-.]\s*\d{6,8})\b/u', $haystack, $m)) {
        $invoice['nro_factura'] = preg_replace('/\s+/', '', str_replace('.', '-', $m[1]));
    }
    if (preg_match('/timbrado\s*(?:n[°o]\s*)?[:#]?\s*(\d{6,10})/iu', $haystack, $m)) {
        $invoice['timbrado'] = trim($m[1]);
    }
    if (preg_match('/ruc\s*[:#]?\s*([0-9.\-]{6,20})/iu', $haystack, $m)) {
        $invoice['ruc'] = trim($m[1]);
    }
    if (preg_match('/fecha\s+de\s+emisi[oó]n\s*:\s*([0-9\/\-.]{8,12})/iu', $haystack, $m)) {
        $d = normalizeDatePy($m[1]);
        if ($d) $invoice['fecha'] = $d;
    }

    // Proveedor: elegir línea corporativa del encabezado y evitar dirección/ciudad.
    foreach (array_slice($lines, 0, 18) as $cand) {
        if ($cand === '') continue;
        if (preg_match('/:|kude|ruc|timbrado|fecha|factura|ciudad|tel[eé]fono|actividad|cliente/i', $cand)) continue;
        $len = function_exists('mb_strlen') ? mb_strlen($cand) : strlen($cand);
        if ($len < 8) continue;
        $invoice['proveedor'] = trim($cand);
        break;
    }

    $items = [];
    $lastIdx = -1;
    $inItemsTable = false;
    foreach ($lines as $line) {
        if (!$inItemsTable && preg_match('/c[oó]digo/i', $line) && preg_match('/descripci[oó]n/i', $line) && preg_match('/cantidad/i', $line)) {
            $inItemsTable = true;
            continue;
        }
        if (!$inItemsTable) {
            continue;
        }
        if (preg_match('/^(sub\s+totales|total\s+de\s+la\s+operaci[oó]n|total\s+en\s+guaranies|liquidaci[oó]n\s+del\s+iva)/iu', $line)) {
            break;
        }
        if (preg_match('/^(c[oó]digo|descripci[oó]n|unidad|cantidad|precio|descuento|valor)/iu', $line)) {
            continue;
        }

        if (preg_match('/\bunidad\b/iu', $line)) {
            $parts = preg_split('/\bunidad\b/iu', $line, 2);
            if (!$parts || count($parts) < 2) continue;
            $left = trim((string)$parts[0]);
            $right = trim((string)$parts[1]);

            if (!preg_match('/^(\S+)\s+(.+)$/u', $left, $lm)) {
                continue;
            }
            $codigo = trim((string)$lm[1]);
            $desc = trim((string)$lm[2]);
            if ($desc === '') continue;

            preg_match_all('/\d[\d.,]*/u', $right, $nm);
            $nums = $nm[0] ?? [];
            if (count($nums) < 2) continue;

            $qty = normalizeMoneyToFloat((string)$nums[0]);
            $unit = normalizeMoneyToFloat((string)$nums[1]);
            if ($qty <= 0) $qty = 1;

            $ex = 0.0; $iva5 = 0.0; $iva10 = 0.0;
            if (count($nums) >= 5) {
                $ex = normalizeMoneyToFloat((string)$nums[count($nums) - 3]);
                $iva5 = normalizeMoneyToFloat((string)$nums[count($nums) - 2]);
                $iva10 = normalizeMoneyToFloat((string)$nums[count($nums) - 1]);
            }
            if ($unit <= 0) {
                $lineTotal = max($ex, $iva5, $iva10);
                if ($lineTotal > 0 && $qty > 0) $unit = $lineTotal / $qty;
            }
            if ($unit <= 0) continue;

            $tipoIva = 3;
            if ($ex > 0 && $iva5 <= 0 && $iva10 <= 0) $tipoIva = 1;
            if ($iva5 > 0 && $iva10 <= 0) $tipoIva = 2;
            if ($iva10 > 0) $tipoIva = 3;

            $items[] = [
                'codigo' => $codigo,
                'descripcion' => $desc,
                'cantidad' => (float)$qty,
                'costo' => (float)$unit,
                'tipo_iva' => $tipoIva
            ];
            $lastIdx = count($items) - 1;
            continue;
        }

        if ($lastIdx >= 0) {
            if (preg_match('/^(ruc|cliente|tel[eé]fono|correo|tipo\s+de\s+transacci[oó]n|fecha|cdc|n[°o]\s+comp|ciudad|motivo)/iu', $line)) {
                continue;
            }
            if (preg_match('/^\d[\d.\,]*$/', $line)) continue;
            $len = function_exists('mb_strlen') ? mb_strlen($line) : strlen($line);
            if ($len >= 4) {
                $items[$lastIdx]['descripcion'] = trim($items[$lastIdx]['descripcion'] . ' ' . $line);
            }
        }
    }
    $invoice['items'] = $items;

    if (preg_match('/total\s+en\s+guaranies\s+([0-9][0-9.\,]+)/iu', $haystack, $m)) {
        $invoice['total'] = normalizeMoneyToFloat($m[1]);
    }

    return $invoice;
}

function runFastPdfTextOcr(string $pdfPath): array
{
    $text = extractPdfTextLayout($pdfPath);
    $data = parseInvoiceFromPdfText($text);
    if (count($data['items']) === 0) {
        throw new Exception('PDF leído pero sin ítems detectados');
    }

    // Rechazar "ítems" contaminados por líneas de cabecera/documento.
    $bad = 0;
    foreach ($data['items'] as $it) {
        $d = strtolower(trim((string)($it['descripcion'] ?? '')));
        if ($d === '' || preg_match('/^(tel[eé]fono|ciudad|cdc|cliente|ruc|correo|tipo\s+de\s+transacci[oó]n|motivo|p[aá]g\.)/iu', $d)) {
            $bad++;
        }
    }
    if ($bad > 0 && $bad >= (int)ceil(count($data['items']) * 0.35)) {
        throw new Exception('PDF leído con baja precisión en ítems');
    }

    return $data;
}

function extractPdfTextLayout(string $pdfPath): string
{
    if (!cmdExists('pdftotext')) {
        throw new Exception('pdftotext no está instalado en el servidor');
    }
    $cmd = 'timeout 20s pdftotext -layout -f 1 -l 1 ' . escapeshellarg($pdfPath) . ' - 2>/dev/null';
    $text = trim((string)@shell_exec($cmd));
    if ($text === '') {
        throw new Exception('No se pudo extraer texto del PDF');
    }
    return $text;
}

function askOpenAiText(string $prompt): string
{
    $openAiApiKey = getOpenAiApiKey();
    if ($openAiApiKey === '') {
        throw new Exception('No hay API key de OpenAI configurada para OCR.');
    }

    $payload = [
        'model' => getOpenAiModel(),
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 2500,
        'temperature' => 0.0
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $openAiApiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 40
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) throw new Exception('Error OpenAI texto: ' . $curlError);
    $result = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $msg = $result['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new Exception('OpenAI texto: ' . $msg);
    }
    return trim((string)($result['choices'][0]['message']['content'] ?? ''));
}

function getOllamaTextModel(): string
{
    $model = trim((string)getenv('OLLAMA_TEXT_MODEL'));
    if ($model === '') $model = trim((string)getenv('OLLAMA_MODEL'));
    if ($model === '') $model = 'qwen2.5:7b';
    return $model;
}

function askOllamaText(string $prompt): string
{
    $ollamaUrl = getOllamaChatUrl();
    $model = getOllamaTextModel();
    $timeoutSec = (int)(getenv('OLLAMA_TIMEOUT') ?: 55);
    if ($timeoutSec < 10) $timeoutSec = 10;
    if ($timeoutSec > 45) $timeoutSec = 45;

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'stream' => false,
        'options' => [
            'temperature' => 0.0,
            'num_ctx' => 8192
        ]
    ];

    $ch = curl_init($ollamaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 6
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) throw new Exception('Ollama texto: ' . $curlError);
    if ($httpCode !== 200) {
        $j = json_decode((string)$response, true);
        $err = trim((string)($j['error'] ?? 'HTTP ' . $httpCode));
        throw new Exception('Ollama texto: ' . $err);
    }
    $j = json_decode((string)$response, true);
    $content = trim((string)($j['message']['content'] ?? ''));
    if ($content === '') throw new Exception('Ollama texto vacío');
    return $content;
}

function decodeInvoiceJsonFromContent(string $content): array
{
    $c = trim($content);
    $c = preg_replace('/```json\s*/i', '', $c);
    $c = preg_replace('/```\s*/i', '', $c);
    $c = trim((string)$c);
    $data = json_decode($c, true);
    if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{[\s\S]*\}/', $c, $m)) {
        $data = json_decode($m[0], true);
    }
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Respuesta IA no parseable en JSON');
    }
    return $data;
}

function runMaxPrecisionPdfAi(string $pdfText): array
{
    $slice = function_exists('mb_substr') ? mb_substr($pdfText, 0, 24000) : substr($pdfText, 0, 24000);
    $prompt = <<<PROMPT
Extrae los datos de esta FACTURA ELECTRÓNICA paraguaya. Responde SOLO JSON válido.

Reglas críticas:
- El proveedor es el emisor, no el cliente.
- Extrae TODOS los ítems de la tabla de productos.
- No incluir campos del encabezado como ítems (teléfono, ciudad, CDC, cliente, etc.).
- "costo" es precio unitario.
- tipo_iva: 1=Exenta, 2=IVA 5%, 3=IVA 10%.

Formato JSON:
{
  "proveedor": "",
  "ruc": "",
  "nro_factura": "",
  "timbrado": "",
  "fecha": "YYYY-MM-DD",
  "vencimiento_timbrado": null,
  "items": [{"codigo":"","descripcion":"","cantidad":0,"costo":0,"tipo_iva":3}],
  "exenta": 0,
  "iva5": 0,
  "iva10": 0,
  "total": 0
}

Texto de factura:
{$slice}
PROMPT;

    $errs = [];

    try {
        $out = askOpenAiText($prompt);
        $data = decodeInvoiceJsonFromContent($out);
        if (!empty($data['items']) && is_array($data['items'])) return $data;
    } catch (Throwable $e) {
        $errs[] = $e->getMessage();
    }
    try {
        $gem = askGemini($prompt, null, 'text/plain');
        $out = getGeminiContent($gem);
        $data = decodeInvoiceJsonFromContent($out);
        if (!empty($data['items']) && is_array($data['items'])) return $data;
    } catch (Throwable $e) {
        $errs[] = $e->getMessage();
    }
    try {
        $out = askOllamaText($prompt);
        $data = decodeInvoiceJsonFromContent($out);
        if (!empty($data['items']) && is_array($data['items'])) return $data;
    } catch (Throwable $e) {
        $errs[] = $e->getMessage();
    }

    throw new Exception('No se pudo estructurar PDF con IA: ' . implode(' | ', $errs));
}

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $requestedEngine = strtolower(trim((string)($_POST['engine'] ?? '')));
    $useGemini = ($requestedEngine === 'gemini');
    $useGoogleVision = in_array($requestedEngine, ['google_vision', 'google-vision', 'vision'], true);
    $cloudOnly = in_array($requestedEngine, ['openai', 'gemini', 'cloud'], true);
    $localOnly = in_array($requestedEngine, ['ollama', 'local'], true);
    $forceAiOnly = in_array($requestedEngine, ['openai', 'gemini', 'ollama'], true);
    $allowAiFallback = $forceAiOnly || $cloudOnly || $localOnly || ((string)($_POST['ai_fallback'] ?? '') === '1');
    $precisionMode = strtolower(trim((string)($_POST['ocr_precision'] ?? '')));
    if ($precisionMode === '') {
        $precisionMode = $allowAiFallback ? 'max' : 'fast';
    }

    $imageBase64 = '';
    $mimeType = 'image/jpeg';
    $pdfTmpPath = '';
    $isPdfInput = false;

    if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $pdfTmpPath = (string)$_FILES['pdf']['tmp_name'];
        $isPdfInput = true;
    } elseif (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['imagen']['tmp_name'];
        $imageData = file_get_contents($tmpPath);
        if ($imageData === false || $imageData === '') {
            throw new Exception('No se pudo leer la imagen enviada.');
        }
        $imageBase64 = base64_encode($imageData);
        $mimeType = (string)(mime_content_type($tmpPath) ?: ($_FILES['imagen']['type'] ?? 'image/jpeg'));
        error_log(sprintf(
            'OCR upload source=%s(%sB) upload=%s(%sB) mime=%s',
            (string)($_POST['ocr_source_name'] ?? $_FILES['imagen']['name'] ?? 'unknown'),
            (string)($_POST['ocr_source_size'] ?? '0'),
            (string)($_POST['ocr_upload_name'] ?? $_FILES['imagen']['name'] ?? 'unknown'),
            (string)($_POST['ocr_upload_size'] ?? (int)($_FILES['imagen']['size'] ?? 0)),
            $mimeType
        ));
    } elseif (isset($_POST['imagen_base64'])) {
        $rawBase64 = trim((string)$_POST['imagen_base64']);
        if (preg_match('/^data:([^;]+);base64,(.*)$/s', $rawBase64, $m)) {
            $mimeType = trim((string)$m[1]);
            $imageBase64 = $m[2];
        } else {
            $imageBase64 = $rawBase64;
        }
    } else {
        throw new Exception('No se recibió ninguna imagen');
    }

    if ($isPdfInput) {
        $pdfText = extractPdfTextLayout($pdfTmpPath);
        if ($precisionMode === 'max') {
            try {
                $aiPdfData = runMaxPrecisionPdfAi($pdfText);
                if (isLikelyTemplateOcrResult($aiPdfData)) {
                    throw new Exception('IA devolvió plantilla para PDF');
                }
                $aiPdfData = validateInvoicePayload($aiPdfData, 'pdf ia');
                echo json_encode([
                    'success' => true,
                    'message' => 'Factura procesada correctamente (PDF IA máxima precisión)',
                    'data' => $aiPdfData
                ]);
                exit;
            } catch (Throwable $pdfAiErr) {
                error_log('OCR PDF max precision fallback to parser: ' . $pdfAiErr->getMessage());
            }
        }

        $pdfData = validateInvoicePayload(runFastPdfTextOcr($pdfTmpPath), 'pdf');
        if (!empty($pdfData['items'])) {
            echo json_encode([
                'success' => true,
                'message' => 'Factura procesada correctamente (PDF texto)',
                'data' => $pdfData
            ]);
            exit;
        }

        throw new Exception('PDF leído pero sin ítems detectados');
    }

    $imageBase64 = preg_replace('/\s+/', '', (string)$imageBase64);

    if (empty($imageBase64)) {
        throw new Exception('Imagen vacía o inválida');
    }
    if (base64_decode($imageBase64, true) === false) {
        throw new Exception('La imagen recibida no es base64 válido.');
    }
    if (stripos($mimeType, 'image/') !== 0) {
        throw new Exception('Formato no soportado para OCR IA. Use JPG, PNG, WEBP, BMP o GIF.');
    }

    if ($useGoogleVision) {
        $vision = askGoogleVisionDocumentText($imageBase64, $mimeType);
        $visionText = trim((string)($vision['fullTextAnnotation']['text'] ?? ''));
        if ($visionText === '') {
            throw new Exception('Google Vision OCR devolvió texto vacío.');
        }
        $visionPreview = function_exists('mb_substr') ? mb_substr($visionText, 0, 2000) : substr($visionText, 0, 2000);
        error_log("Google Vision OCR text preview:\n" . $visionPreview);
        $visionData = parseInvoiceFromGoogleVisionText($visionText);
        $visionContext = !empty($visionData['items']) ? 'google vision' : 'google vision header';
        $visionData = validateInvoicePayload($visionData, $visionContext);
        echo json_encode([
            'success' => true,
            'message' => 'Factura procesada correctamente (Google Vision OCR)',
            'data' => $visionData
        ]);
        exit;
    }

    // En modo IA/precisión máxima no aceptar primero el OCR rápido local.
    if (!$forceAiOnly && $precisionMode !== 'max') {
        try {
            $fastData = validateInvoicePayload(runFastTesseractOcr($imageBase64, $mimeType), 'rápido');
            echo json_encode([
                'success' => true,
                'message' => 'Factura procesada correctamente (OCR rápido)',
                'data' => $fastData
            ]);
            exit;
        } catch (Throwable $fastErr) {
            error_log('OCR fast(tesseract) error: ' . $fastErr->getMessage());
            if (!$allowAiFallback) {
                throw new Exception('OCR rápido no logró extraer datos suficientes. Probá una imagen más nítida o activá fallback IA.');
            }
            error_log('OCR fast(tesseract) fallback to IA enabled');
        }
    }

    $prompt = <<<PROMPT
Analiza detalladamente esta imagen de factura de compra paraguaya y extrae los datos del ENCABEZADO y TODOS los ITEMS de la tabla.

IMPORTANTE - PROVEEDOR: El PROVEEDOR (Emisor) está en la parte SUPERIOR (encabezado). NO es el cliente ni el receptor.
Busca el nombre de la empresa emisor y su RUC en la parte de arriba.

IMPORTANTE - ITEMS: Extrae TODOS los productos listados en la factura. 
Busca la tabla de productos (usualmente en el medio) y extrae cada fila. No te limites solo al primer ítem.
Asegúrate de capturar la descripción, cantidad, costo unitario y tipo de IVA por cada producto.

Responde SOLO con el JSON, sin texto adicional ni markdown.

Formato requerido:
{
    "proveedor": "Nombre del EMISOR de la factura (encabezado superior)",
    "ruc": "RUC del emisor (sin DV)",
    "nro_factura": "Número de factura (ej: 001-001-0000123)",
    "timbrado": "Número de timbrado (8 dígitos)",
    "fecha": "YYYY-MM-DD",
    "vencimiento_timbrado": "YYYY-MM-DD o null",
    "items": [
        {
            "codigo": "Código o código de barras del producto (si existe)",
            "descripcion": "Descripción completa del producto",
            "cantidad": 1.0,
            "costo": 50000,
            "tipo_iva": 3
        }
    ],
    "exenta": 0,
    "iva5": 0,
    "iva10": 0,
    "total": 0
}

Notas:
- tipo_iva: 1=Exenta, 2=IVA 5%, 3=IVA 10%
- Todos los montos en números enteros (Guaraníes)
- Si hay varios productos, inclúyelos TODOS en el array "items"
- La fecha debe ser YYYY-MM-DD
PROMPT;

    if ($useGemini) {
        try {
            $geminiResult = askGemini($prompt, $imageBase64, $mimeType);
            $content = getGeminiContent($geminiResult);
            if (trim($content) === '') {
                throw new Exception('Gemini devolvió respuesta vacía.');
            }
        } catch (Throwable $geminiErr) {
            $openAiErrMsg = '';
            $ollamaErrMsg = '';
            if (!$localOnly) {
                try {
                    $content = askOpenAiVision($prompt, $imageBase64, $mimeType);
                } catch (Throwable $openAiErr) {
                    $openAiErrMsg = $openAiErr->getMessage();
                }
            }
            if (trim((string)($content ?? '')) === '' && !$cloudOnly) {
                try {
                    $content = askOllamaVision($prompt, $imageBase64);
                } catch (Throwable $ollamaErr) {
                    $ollamaErrMsg = $ollamaErr->getMessage();
                }
            }
            if (trim((string)($content ?? '')) === '') {
                throw new Exception(normalizeOcrErrorMessage($geminiErr->getMessage() . ' | ' . $openAiErrMsg . ' | ' . $ollamaErrMsg));
            }
        }
    } else if ($localOnly) {
        try {
            $content = askOllamaVision($prompt, $imageBase64);
        } catch (Throwable $ollamaErr) {
            throw new Exception(normalizeOcrErrorMessage($ollamaErr->getMessage()));
        }
    } else {
        $openAiErrMsg = '';
        $geminiErrMsg = '';
        $ollamaErrMsg = '';
        try {
            $content = askOpenAiVision($prompt, $imageBase64, $mimeType);
        } catch (Throwable $openAiErr) {
            $openAiErrMsg = $openAiErr->getMessage();
            try {
                $geminiResult = askGemini($prompt, $imageBase64, $mimeType);
                $content = getGeminiContent($geminiResult);
            } catch (Throwable $geminiErr) {
                $geminiErrMsg = $geminiErr->getMessage();
            }
            if (trim((string)($content ?? '')) === '' && !$cloudOnly) {
                try {
                    $content = askOllamaVision($prompt, $imageBase64);
                } catch (Throwable $ollamaErr) {
                    $ollamaErrMsg = $ollamaErr->getMessage();
                }
            }
        }
        if (trim((string)($content ?? '')) === '') {
            throw new Exception(normalizeOcrErrorMessage($openAiErrMsg . ' | ' . $geminiErrMsg . ' | ' . $ollamaErrMsg));
        }
    }

    $content = preg_replace('/```json\s*/i', '', $content);
    $content = preg_replace('/```\s*/i', '', $content);
    $content = trim($content);

    $invoiceData = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $invoiceData = json_decode($matches[0], true);
        }

        // Evitar un segundo intento a Ollama acá para no exceder tiempos de gateway.

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("No se pudo parsear la respuesta: " . json_last_error_msg() . "\nRespuesta: " . substr($content, 0, 500));
        }
    }
    if (!is_array($invoiceData) || isLikelyTemplateOcrResult($invoiceData)) {
        throw new Exception('OCR devolvió datos de ejemplo y no la factura real. Reintente con una imagen más nítida o la primera página del PDF.');
    }

    $cleanData = [
        'proveedor' => trim($invoiceData['proveedor'] ?? ''),
        'ruc' => trim($invoiceData['ruc'] ?? ''),
        'nro_factura' => trim($invoiceData['nro_factura'] ?? ''),
        'timbrado' => trim($invoiceData['timbrado'] ?? ''),
        'fecha' => $invoiceData['fecha'] ?? date('Y-m-d'),
        'vencimiento_timbrado' => $invoiceData['vencimiento_timbrado'] ?? null,
        'items' => $invoiceData['items'] ?? [],
        'exenta' => (float)($invoiceData['exenta'] ?? 0),
        'iva5' => (float)($invoiceData['iva5'] ?? 0),
        'iva10' => (float)($invoiceData['iva10'] ?? 0),
        'total' => (float)($invoiceData['total'] ?? 0)
    ];

    $cleanData = validateInvoicePayload($cleanData, 'ia');

    echo json_encode([
        'success' => true,
        'message' => 'Factura procesada correctamente',
        'data' => $cleanData
    ]);
} catch (Throwable $e) {
    $rawMsg = trim((string)$e->getMessage());
    error_log('OCR factura API raw error: ' . $rawMsg);
    error_log('OCR factura API error: ' . normalizeOcrErrorMessage($rawMsg));
    // Evita ruido de "HTTP error" en cliente; el front ya maneja success=false.
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => normalizeOcrErrorMessage($rawMsg)
    ]);
}
