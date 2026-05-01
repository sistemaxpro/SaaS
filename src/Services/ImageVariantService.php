<?php

class ImageVariantService
{
    /**
     * Tamaños máximos por variante (lado mayor, en px).
     */
    public static function sizes(): array
    {
        return [
            'thumb' => 120,
            'small' => 320,
            'medium' => 640,
            'large' => 1280,
        ];
    }

    public static function r2VariantKey(string $baseKey, string $variant): string
    {
        $sizes = self::sizes();
        if (!isset($sizes[$variant])) {
            throw new InvalidArgumentException('Variante no soportada: ' . $variant);
        }
        return $baseKey . '_' . $variant . '.webp';
    }

    /**
     * Genera variantes webp desde un binario de imagen.
     * Retorna: ['thumb' => ['binary' => ..., 'width' => ..., 'height' => ...], ...]
     */
    public static function generateWebpVariantsFromBinary(string $binary): array
    {
        if ($binary === '') {
            throw new RuntimeException('Imagen vacía');
        }

        $src = @imagecreatefromstring($binary);
        if (!$src) {
            throw new RuntimeException('No se pudo procesar la imagen fuente');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            throw new RuntimeException('Dimensiones de imagen inválidas');
        }

        $out = [];
        foreach (self::sizes() as $name => $maxEdge) {
            [$newW, $newH] = self::fitDimensions($srcW, $srcH, $maxEdge);
            $canvas = imagecreatetruecolor($newW, $newH);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            $tmp = tempnam(sys_get_temp_dir(), 'sx_img_');
            if ($tmp === false) {
                imagedestroy($canvas);
                imagedestroy($src);
                throw new RuntimeException('No se pudo crear archivo temporal');
            }

            $ok = imagewebp($canvas, $tmp, 85);
            imagedestroy($canvas);
            if (!$ok || !is_file($tmp)) {
                @unlink($tmp);
                imagedestroy($src);
                throw new RuntimeException('No se pudo generar variante webp: ' . $name);
            }

            $variantBinary = (string)file_get_contents($tmp);
            @unlink($tmp);
            if ($variantBinary === '') {
                imagedestroy($src);
                throw new RuntimeException('Variante vacía: ' . $name);
            }

            $out[$name] = [
                'binary' => $variantBinary,
                'width' => $newW,
                'height' => $newH,
                'mime' => 'image/webp',
            ];
        }

        imagedestroy($src);
        return $out;
    }

    /**
     * Construye URLs de variantes desde una URL base sin sufijo (prefijo).
     */
    public static function variantUrlsFromBaseUrl(string $publicBasePrefix): array
    {
        $prefix = rtrim($publicBasePrefix, '/');
        if ($prefix === '') {
            return [];
        }

        $out = [];
        foreach (array_keys(self::sizes()) as $name) {
            $out[$name] = $prefix . '_' . $name . '.webp';
        }
        return $out;
    }

    /**
     * Intenta derivar variantes desde file_id/url.
     */
    public static function deriveVariantUrls(?string $fileId, ?string $url): array
    {
        $fileId = trim((string)$fileId);
        $url = self::sanitizeMalformedVariantUrl(trim((string)$url));

        if ($fileId !== '' && str_starts_with($fileId, 'r2:')) {
            $baseKey = ltrim(substr($fileId, 3), '/');
            if ($baseKey !== '' && $url !== '') {
                // Legacy: file_id apunta a un archivo final (ej: principal.webp), no a base key.
                if (preg_match('/\\.webp$/i', $baseKey) && !preg_match('/_(thumb|small|medium|large)\\.webp$/i', $baseKey)) {
                    return [
                        'thumb' => $url,
                        'small' => $url,
                        'medium' => $url,
                        'large' => $url,
                    ];
                }

                // Nuevo formato: base key sin extensión o key con sufijo de variante.
                $normalizedBaseKey = self::normalizeBaseKey($baseKey);
                if ($normalizedBaseKey !== '') {
                    $baseUrl = self::replaceUrlPathTail($url, basename($normalizedBaseKey));
                    if ($baseUrl !== '') {
                        return self::variantUrlsFromBaseUrl($baseUrl);
                    }
                }
            }
        }

        // Fallback para URL con sufijo _medium.webp o similares.
        if ($url !== '' && preg_match('/^(.*)_(thumb|small|medium|large)\\.webp(\\?.*)?$/i', $url, $m)) {
            $base = (string)($m[1] ?? '');
            $q = (string)($m[3] ?? '');
            $variants = self::variantUrlsFromBaseUrl($base);
            if ($q !== '') {
                foreach ($variants as $k => $v) {
                    $variants[$k] = $v . $q;
                }
            }
            return $variants;
        }

        if ($url !== '') {
            return [
                'thumb' => $url,
                'small' => $url,
                'medium' => $url,
                'large' => $url,
            ];
        }

        return [];
    }

    private static function fitDimensions(int $srcW, int $srcH, int $maxEdge): array
    {
        if ($srcW <= $maxEdge && $srcH <= $maxEdge) {
            return [$srcW, $srcH];
        }
        if ($srcW >= $srcH) {
            $newW = $maxEdge;
            $newH = (int)max(1, round(($srcH / $srcW) * $maxEdge));
        } else {
            $newH = $maxEdge;
            $newW = (int)max(1, round(($srcW / $srcH) * $maxEdge));
        }
        return [$newW, $newH];
    }

    private static function replaceUrlPathTail(string $url, string $newTail): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return '';
        }

        $path = (string)$parts['path'];
        $lastSlash = strrpos($path, '/');
        $basePath = $lastSlash === false ? '' : substr($path, 0, $lastSlash + 1);
        $finalPath = $basePath . $newTail;

        $out = $parts['scheme'] . '://' . $parts['host'] . $finalPath;
        if (!empty($parts['port'])) {
            $out = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] . $finalPath;
        }
        return $out;
    }

    private static function sanitizeMalformedVariantUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        // Corrige URLs legacy mal formadas: principal.webp_small.webp, principal.webp_medium.webp, etc.
        return (string)preg_replace('/\\.webp_(thumb|small|medium|large)\\.webp/i', '.webp', $url);
    }

    private static function normalizeBaseKey(string $key): string
    {
        $key = ltrim(trim($key), '/');
        if ($key === '') {
            return '';
        }
        if (preg_match('/_(thumb|small|medium|large)\\.webp$/i', $key)) {
            return preg_replace('/_(thumb|small|medium|large)\\.webp$/i', '', $key);
        }
        // Si trae .webp final pero no sufijo de variante, removemos extensión para usar como base.
        if (preg_match('/\\.webp$/i', $key)) {
            return preg_replace('/\\.webp$/i', '', $key);
        }
        return $key;
    }
}
