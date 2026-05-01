<?php
/**
 * GoogleDriveService — Almacenamiento de imágenes en Google Drive via OAuth 2.0
 *
 * Usa OAuth 2.0 refresh_token del usuario real (sistemaxpro5@gmail.com)
 * para que los archivos cuenten contra la cuota del usuario (15 GB gratis).
 *
 * Las Service Accounts NO tienen cuota de almacenamiento, por eso usamos OAuth.
 *
 * Uso:
 *   $drive = new GoogleDriveService();
 *   $result = $drive->uploadProductImage($db, $idProd, $fileData, $filename);
 *   $images = $drive->listProductImages($db, $idProd);
 *   $drive->deleteImage($fileId);
 */

class GoogleDriveService
{
    private array   $config;
    private ?string $accessToken = null;
    private int     $tokenExpiry = 0;

    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const DRIVE_API  = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';

    /** Cache estática de folder IDs para evitar búsquedas repetidas */
    private static array $folderCache = [];

    // ─── Constructor ────────────────────────────────────────────────────

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require __DIR__ . '/../../config/google_drive.php';

        if (empty($this->config['oauth_client_id']) || empty($this->config['oauth_client_secret'])) {
            throw new \RuntimeException(
                "Google Drive: OAuth no configurado.\n" .
                "Ejecutá el wizard en /public/setup/google_drive_setup.php"
            );
        }
        if (empty($this->config['oauth_refresh_token'])) {
            throw new \RuntimeException(
                "Google Drive: falta refresh_token.\n" .
                "Completá la autorización en /public/setup/google_drive_setup.php"
            );
        }
    }

    /**
     * Verifica si el servicio está correctamente configurado.
     */
    public static function isConfigured(): bool
    {
        $cfg = @include __DIR__ . '/../../config/google_drive.php';
        if (!is_array($cfg)) return false;
        return !empty($cfg['oauth_client_id'])
            && !empty($cfg['oauth_client_secret'])
            && !empty($cfg['oauth_refresh_token'])
            && !empty($cfg['root_folder_id']);
    }

    // ─── Autenticación OAuth 2.0 ────────────────────────────────────────

    /**
     * Obtiene un access_token válido usando el refresh_token.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        // Intentar leer del caché
        $this->loadCachedToken();
        if ($this->accessToken && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        // Renovar con refresh_token
        $this->refreshAccessToken();
        return $this->accessToken;
    }

    private function loadCachedToken(): void
    {
        $cachePath = $this->config['token_cache_path'] ?? '';
        if (!$cachePath || !file_exists($cachePath)) return;

        $data = json_decode(@file_get_contents($cachePath), true);
        if (!$data) return;

        if (!empty($data['access_token']) && !empty($data['expiry']) && time() < (int)$data['expiry']) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpiry = (int)$data['expiry'];
        }
    }

    private function saveCachedToken(): void
    {
        $cachePath = $this->config['token_cache_path'] ?? '';
        if (!$cachePath) return;

        @file_put_contents($cachePath, json_encode([
            'access_token' => $this->accessToken,
            'expiry'       => $this->tokenExpiry,
        ]), LOCK_EX);
    }

    private function refreshAccessToken(): void
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $this->config['oauth_client_id'],
                'client_secret' => $this->config['oauth_client_secret'],
                'refresh_token' => $this->config['oauth_refresh_token'],
                'grant_type'    => 'refresh_token',
            ]),
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("Google Drive: error al renovar token — {$err}");

        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            $errMsg = $data['error_description'] ?? $data['error'] ?? $body;
            throw new \RuntimeException("Google Drive: no se pudo renovar token — {$errMsg}");
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + (int)($data['expires_in'] ?? 3500) - 60;
        $this->saveCachedToken();
    }

    // ─── Gestión de Carpetas ────────────────────────────────────────────

    /**
     * Obtiene (o crea) la carpeta de productos de una empresa.
     * Estructura legacy: root_folder / {dbName} / productos
     */
    public function ensureProductosFolder(string $dbName): string
    {
        $cacheKey = "productos_{$dbName}";
        if (isset(self::$folderCache[$cacheKey])) {
            return self::$folderCache[$cacheKey];
        }

        $rootId = $this->config['root_folder_id'];

        // 1. Carpeta de la empresa
        $empresaFolderId = $this->findOrCreateFolder($dbName, $rootId);

        // 2. Subcarpeta "productos"
        $productosFolderId = $this->findOrCreateFolder('productos', $empresaFolderId);

        self::$folderCache[$cacheKey] = $productosFolderId;
        return $productosFolderId;
    }

    /**
     * Obtiene (o crea) carpeta de fotos por empresa.
     * Estructura: root_folder / foto / empresa / empresa{id_empresa}
     */
    public function ensureEmpresaFotoFolder(int $idEmpresa): string
    {
        if ($idEmpresa <= 0) {
            throw new \InvalidArgumentException('idEmpresa inválido para carpeta de fotos');
        }

        $cacheKey = "foto_empresa_{$idEmpresa}";
        if (isset(self::$folderCache[$cacheKey])) {
            return self::$folderCache[$cacheKey];
        }

        $rootId = $this->config['root_folder_id'];
        $fotoFolderId = $this->findOrCreateFolder('foto', $rootId);
        $empresaParentFolderId = $this->findOrCreateFolder('empresa', $fotoFolderId);
        $empresaFolderId = $this->findOrCreateFolder("empresa{$idEmpresa}", $empresaParentFolderId);

        self::$folderCache[$cacheKey] = $empresaFolderId;
        return $empresaFolderId;
    }

    /**
     * Obtiene (o crea) carpeta de productos por empresa siguiendo esquema local.
     * Estructura: root_folder / foto / empresa / empresa{id_empresa} / productos / {dbName}
     */
    public function ensureEmpresaProductosFolder(int $idEmpresa, string $dbName): string
    {
        if ($idEmpresa <= 0) {
            throw new \InvalidArgumentException('idEmpresa inválido para carpeta de productos');
        }
        $dbName = trim($dbName);
        if ($dbName === '') {
            throw new \InvalidArgumentException('dbName inválido para carpeta de productos');
        }

        $cacheKey = "foto_empresa_{$idEmpresa}_productos_{$dbName}";
        if (isset(self::$folderCache[$cacheKey])) {
            return self::$folderCache[$cacheKey];
        }

        $empresaFolderId = $this->ensureEmpresaFotoFolder($idEmpresa);
        $productosFolderId = $this->findOrCreateFolder('productos', $empresaFolderId);
        $dbFolderId = $this->findOrCreateFolder($dbName, $productosFolderId);

        self::$folderCache[$cacheKey] = $dbFolderId;
        return $dbFolderId;
    }

    /**
     * Busca una carpeta hija por nombre; si no existe, la crea.
     */
    private function findOrCreateFolder(string $name, string $parentId): string
    {
        $query = sprintf(
            "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            addcslashes($name, "'\\"),
            $parentId
        );
        $result = $this->driveGet('/files', [
            'q'      => $query,
            'fields' => 'files(id,name)',
            'spaces' => 'drive',
        ]);

        if (!empty($result['files'])) {
            return $result['files'][0]['id'];
        }

        // Crear
        $meta = [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parentId],
        ];
        $created = $this->drivePost('/files', $meta);
        return $created['id'];
    }

    /**
     * Crea la carpeta raíz SistemaXPro en el Drive del usuario.
     * Retorna el folder ID.
     */
    public function createRootFolder(string $name = 'SistemaXPro'): string
    {
        // Buscar si ya existe en la raíz
        $query = "name = '{$name}' and 'root' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $result = $this->driveGet('/files', [
            'q'      => $query,
            'fields' => 'files(id,name)',
        ]);

        if (!empty($result['files'])) {
            return $result['files'][0]['id'];
        }

        $meta = [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        $created = $this->drivePost('/files', $meta);
        return $created['id'];
    }

    // ─── Upload de Imágenes ─────────────────────────────────────────────

    /**
     * Sube una imagen de producto a Google Drive.
     *
     * @return array { id, url, name, size }
     */
    public function uploadProductImage(string $dbName, int $idProducto, string $fileData, string $filename = 'foto.jpg', bool $isPath = false, int $idEmpresa = 0): array
    {
        if ($isPath) {
            if (!file_exists($fileData)) {
                throw new \RuntimeException("Archivo no encontrado: {$fileData}");
            }
            $binary = file_get_contents($fileData);
        } else {
            $binary = $fileData;
        }

        $processed = $this->processImage($binary);

        $ext = $this->config['image']['format'] ?? 'webp';
        $orden = $this->getNextImageOrder($dbName, $idProducto, $idEmpresa);
        $driveName = "{$idProducto}_{$orden}.{$ext}";

        $folderId = $idEmpresa > 0
            ? $this->ensureEmpresaProductosFolder($idEmpresa, $dbName)
            : $this->ensureProductosFolder($dbName);
        $fileId = $this->multipartUpload($driveName, $processed['data'], $processed['mime'], $folderId);

        // Hacer pública
        $url = '';
        if ($this->config['public_access'] ?? true) {
            $this->makePublic($fileId);
            $url = "https://lh3.googleusercontent.com/d/{$fileId}";
        } else {
            $url = self::DRIVE_API . "/files/{$fileId}?alt=media";
        }

        return [
            'id'   => $fileId,
            'url'  => $url,
            'name' => $driveName,
            'size' => strlen($processed['data']),
        ];
    }

    private function getNextImageOrder(string $dbName, int $idProducto, int $idEmpresa = 0): int
    {
        $images = $this->listProductImages($dbName, $idProducto, $idEmpresa);
        if (empty($images)) return 1;

        $maxOrder = 0;
        foreach ($images as $img) {
            if (preg_match("/{$idProducto}_(\d+)\./", $img['name'], $m)) {
                $maxOrder = max($maxOrder, (int)$m[1]);
            }
        }
        return $maxOrder + 1;
    }

    /**
     * Lista las imágenes de un producto.
     *
     * @return array [ { id, name, url, size, createdTime } , ... ]
     */
    public function listProductImages(string $dbName, int $idProducto, int $idEmpresa = 0): array
    {
        $images = [];
        $seen = [];
        $folderIds = [];
        if ($idEmpresa > 0) {
            // Nueva estructura alineada al esquema local.
            $folderIds[] = $this->ensureEmpresaProductosFolder($idEmpresa, $dbName);
            // Compatibilidad: carpeta legacy sin subcarpeta productos/{dbName}.
            $folderIds[] = $this->ensureEmpresaFotoFolder($idEmpresa);
        } else {
            $folderIds[] = $this->ensureProductosFolder($dbName);
        }

        foreach (array_unique($folderIds) as $folderId) {
            $query = sprintf(
                "name contains '%d_' and '%s' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'",
                $idProducto,
                $folderId
            );

            $result = $this->driveGet('/files', [
                'q'       => $query,
                'fields'  => 'files(id,name,size,createdTime,webContentLink,thumbnailLink)',
                'orderBy' => 'name',
                'spaces'  => 'drive',
            ]);

            foreach (($result['files'] ?? []) as $file) {
                if (!preg_match("/^{$idProducto}_\d+\./", $file['name'])) continue;
                $fid = (string)($file['id'] ?? '');
                if ($fid === '' || isset($seen[$fid])) continue;
                $seen[$fid] = true;

                $images[] = [
                    'id'          => $fid,
                    'name'        => $file['name'],
                    'url'         => "https://lh3.googleusercontent.com/d/{$fid}",
                    'size'        => (int)($file['size'] ?? 0),
                    'createdTime' => $file['createdTime'] ?? null,
                    'thumbnail'   => $file['thumbnailLink'] ?? null,
                ];
            }
        }

        return $images;
    }

    /**
     * Elimina una imagen por su file ID.
     */
    public function deleteImage(string $fileId): bool
    {
        $token = $this->getAccessToken();
        $url = self::DRIVE_API . "/files/{$fileId}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return in_array($code, [200, 204, 404]);
    }

    /**
     * Elimina TODAS las imágenes de un producto.
     */
    public function deleteAllProductImages(string $dbName, int $idProducto, int $idEmpresa = 0): int
    {
        $images = $this->listProductImages($dbName, $idProducto, $idEmpresa);
        $deleted = 0;
        foreach ($images as $img) {
            if ($this->deleteImage($img['id'])) $deleted++;
        }
        return $deleted;
    }

    public function getPublicUrl(string $fileId): string
    {
        return "https://lh3.googleusercontent.com/d/{$fileId}";
    }

    // ─── Procesamiento de Imágenes ──────────────────────────────────────

    private function processImage(string $binary): array
    {
        $imgCfg  = $this->config['image'] ?? [];
        $maxW    = $imgCfg['max_width']  ?? 1200;
        $maxH    = $imgCfg['max_height'] ?? 1200;
        $quality = $imgCfg['quality']    ?? 82;
        $format  = $imgCfg['format']     ?? 'webp';

        $maxBytes = ($imgCfg['max_size_mb'] ?? 5) * 1024 * 1024;
        if (strlen($binary) > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'La imagen excede el tamaño máximo de %d MB.',
                $imgCfg['max_size_mb'] ?? 5
            ));
        }

        $src = @imagecreatefromstring($binary);
        if (!$src) {
            throw new \RuntimeException('No se pudo leer la imagen. Formato no soportado o archivo corrupto.');
        }

        $origW = imagesx($src);
        $origH = imagesy($src);
        $ratio = min($maxW / $origW, $maxH / $origH, 1.0);
        $newW  = (int)round($origW * $ratio);
        $newH  = (int)round($origH * $ratio);

        if ($ratio < 1.0) {
            $dst = imagecreatetruecolor($newW, $newH);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        $mime = 'image/webp';
        switch ($format) {
            case 'webp':
                imagewebp($src, null, $quality);
                $mime = 'image/webp';
                break;
            case 'jpeg': case 'jpg':
                imagejpeg($src, null, $quality);
                $mime = 'image/jpeg';
                break;
            case 'png':
                imagepng($src, null, (int)(9 - ($quality / 100 * 9)));
                $mime = 'image/png';
                break;
            default:
                imagewebp($src, null, $quality);
                $mime = 'image/webp';
        }
        $data = ob_get_clean();
        imagedestroy($src);

        return ['data' => $data, 'mime' => $mime, 'width' => $newW, 'height' => $newH];
    }

    // ─── HTTP helpers ───────────────────────────────────────────────────

    private function driveGet(string $endpoint, array $params = []): array
    {
        $token = $this->getAccessToken();
        $url = self::DRIVE_API . $endpoint;
        if ($params) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("Google Drive GET error: {$err}");
        $data = json_decode($body, true) ?? [];
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException("Google Drive API error: {$msg}");
        }
        return $data;
    }

    private function drivePost(string $endpoint, array $body): array
    {
        $token = $this->getAccessToken();
        $url = self::DRIVE_API . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $respBody = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("Google Drive POST error: {$err}");
        $data = json_decode($respBody, true) ?? [];
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException("Google Drive API error: {$msg}");
        }
        return $data;
    }

    private function multipartUpload(string $filename, string $fileData, string $mimeType, string $folderId): string
    {
        $token = $this->getAccessToken();
        $url = self::UPLOAD_API . '/files?uploadType=multipart&fields=id,name,size';

        $metadata = json_encode([
            'name'    => $filename,
            'parents' => [$folderId],
        ]);

        $boundary = 'sistemax_' . uniqid();
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mimeType}\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode($fileData) . "\r\n"
            . "--{$boundary}--";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Content-Type: multipart/related; boundary={$boundary}",
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $respBody = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \RuntimeException("Google Drive Upload error: {$err}");
        $data = json_decode($respBody, true) ?? [];
        if ($code >= 400 || empty($data['id'])) {
            $msg = $data['error']['message'] ?? "HTTP {$code} — upload falló";
            throw new \RuntimeException("Google Drive Upload error: {$msg}");
        }

        return $data['id'];
    }

    private function makePublic(string $fileId): void
    {
        $token = $this->getAccessToken();
        $url = self::DRIVE_API . "/files/{$fileId}/permissions";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode(['role' => 'reader', 'type' => 'anyone']),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
