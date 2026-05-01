<?php

if (!function_exists('smxEscposLogoPathFromEmpresa')) {
    function smxEscposLogoPathFromEmpresa(array $empresa): string
    {
        $logoFile = trim((string)($empresa['logos'] ?? ''));
        if ($logoFile === '') {
            return '';
        }
        $logoPath = dirname(__DIR__, 2) . '/_lib/file/img/empresa/' . ltrim($logoFile, '/');
        if (!is_file($logoPath) || filesize($logoPath) <= 0) {
            return '';
        }
        return $logoPath;
    }
}

if (!function_exists('smxEscposLoadImage')) {
    function smxEscposLoadImage(string $imagePath)
    {
        if (!function_exists('imagecreatefrompng') || !file_exists($imagePath)) {
            return false;
        }
        $info = @getimagesize($imagePath);
        if (!$info) {
            return false;
        }
        $mime = $info['mime'] ?? '';
        switch ($mime) {
            case 'image/png':
                return @imagecreatefrompng($imagePath);
            case 'image/jpeg':
                return @imagecreatefromjpeg($imagePath);
            case 'image/gif':
                return @imagecreatefromgif($imagePath);
            default:
                return false;
        }
    }
}

if (!function_exists('smxEscposPrepareLogoBitmap')) {
    function smxEscposPrepareLogoBitmap(string $imagePath, int $widthCols = 48): array
    {
        $img = smxEscposLoadImage($imagePath);
        if (!$img) {
            return [null, 0, 0, 0];
        }

        // En el proyecto usamos 32 cols para 58mm y 40/48 cols para 80mm.
        // El logo debe seguir esa misma equivalencia para no quedar "centrado" como ticket angosto.
        $maxDots = $widthCols <= 32 ? 384 : 576;
        $origW = imagesx($img);
        $origH = imagesy($img);
        $logoMaxWidth = (int)intval($maxDots * 0.6);
        $logoMaxHeight = 150;

        if ($origW > $logoMaxWidth) {
            $newW = $logoMaxWidth;
            $newH = (int)intval($origH * ($logoMaxWidth / max(1, $origW)));
        } else {
            $newW = $origW;
            $newH = $origH;
        }

        if ($newH > $logoMaxHeight) {
            $newW = (int)intval($newW * ($logoMaxHeight / max(1, $newH)));
            $newH = $logoMaxHeight;
        }

        $resized = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagealphablending($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($img);

        return [$resized, $newW, $newH, $maxDots];
    }
}

if (!function_exists('smxEscposLogoToBitImage')) {
    function smxEscposLogoToBitImage(string $imagePath, int $widthCols = 48): string
    {
        [$resized, $newW, $newH, $maxDots] = smxEscposPrepareLogoBitmap($imagePath, $widthCols);
        if (!$resized || $newW <= 0 || $newH <= 0) {
            return '';
        }

        $ESC = "\x1B";
        $LF = "\x0A";
        $out = '';
        $out .= $ESC . "3" . chr(24);
        $leftPadDots = max(0, (int)floor(($maxDots - $newW) / 2));
        $leftNL = $leftPadDots % 256;
        $leftNH = intdiv($leftPadDots, 256);

        for ($y = 0; $y < $newH; $y += 24) {
            $out .= $ESC . "$" . chr($leftNL) . chr($leftNH);
            $out .= $ESC . "*" . chr(33) . chr($newW % 256) . chr(intdiv($newW, 256));
            for ($x = 0; $x < $newW; $x++) {
                for ($k = 0; $k < 3; $k++) {
                    $slice = 0;
                    for ($b = 0; $b < 8; $b++) {
                        $yy = $y + ($k * 8) + $b;
                        if ($yy >= $newH) {
                            continue;
                        }
                        $rgb = imagecolorat($resized, $x, $yy);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $bl = $rgb & 0xFF;
                        $gray = ($r * 0.299) + ($g * 0.587) + ($bl * 0.114);
                        if ($gray < 128) {
                            $slice |= (1 << (7 - $b));
                        }
                    }
                    $out .= chr($slice);
                }
            }
            $out .= $LF;
        }

        $out .= $ESC . "$" . chr(0) . chr(0);
        $out .= $ESC . "2";
        imagedestroy($resized);
        return $out;
    }
}

if (!function_exists('smxEscposAppendLogo')) {
    function smxEscposAppendLogo(string &$buffer, array $empresa, int $widthCols = 48, string $lf = "\x0A"): string
    {
        $logoPath = smxEscposLogoPathFromEmpresa($empresa);
        if ($logoPath === '') {
            return '';
        }
        $logoData = smxEscposLogoToBitImage($logoPath, $widthCols);
        if ($logoData !== '') {
            $buffer .= $logoData;
        }
        return $logoPath;
    }
}
