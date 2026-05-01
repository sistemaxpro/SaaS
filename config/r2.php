<?php

/**
 * Configuración R2 (Cloudflare) para imágenes.
 *
 * Variables de entorno esperadas:
 * - R2_ACCOUNT_ID
 * - R2_ACCESS_KEY_ID
 * - R2_SECRET_ACCESS_KEY
 * - R2_BUCKET
 * - R2_PUBLIC_BASE_URL (ej: https://img.tudominio.com)
 *
 * Opcionales:
 * - R2_ENABLED=true|false
 * - R2_ENDPOINT (si se quiere custom, por defecto usa account_id)
 * - R2_MAX_IMAGE_MB (default 8)
 * - R2_MAX_PER_PRODUCT (default 5)
 */

if (!function_exists('sx_r2_env')) {
    function sx_r2_env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== null && $v !== '') {
            return trim((string)$v);
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return trim((string)$_SERVER[$key]);
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return trim((string)$_ENV[$key]);
        }
        return $default;
    }
}

$accountId = sx_r2_env('R2_ACCOUNT_ID');
$accessKey = sx_r2_env('R2_ACCESS_KEY_ID');
$secretKey = sx_r2_env('R2_SECRET_ACCESS_KEY');
$bucket = sx_r2_env('R2_BUCKET');
$publicBaseUrl = rtrim(sx_r2_env('R2_PUBLIC_BASE_URL'), '/');
$endpoint = sx_r2_env('R2_ENDPOINT');

$enabledRaw = strtolower(sx_r2_env('R2_ENABLED'));
$forceEnabled = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

if ($endpoint === '' && $accountId !== '') {
    $endpoint = 'https://' . $accountId . '.r2.cloudflarestorage.com';
}

$autoEnabled = ($accountId !== '' && $accessKey !== '' && $secretKey !== '' && $bucket !== '' && $publicBaseUrl !== '' && $endpoint !== '');
$enabled = $forceEnabled || $autoEnabled;

return [
    'enabled' => $enabled,
    'account_id' => $accountId,
    'access_key_id' => $accessKey,
    'secret_access_key' => $secretKey,
    'bucket' => $bucket,
    'endpoint' => rtrim($endpoint, '/'),
    'public_base_url' => $publicBaseUrl,
    'max_image_mb' => (int)(sx_r2_env('R2_MAX_IMAGE_MB', '8') ?: 8),
    'max_per_product' => (int)(sx_r2_env('R2_MAX_PER_PRODUCT', '5') ?: 5),
];
