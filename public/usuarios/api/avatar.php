<?php

require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
$sessionIdLogin = (int)($_SESSION['id_login'] ?? 0);
if ($idEmpresa <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Sin sesión']);
    exit;
}

$pdo = Database::getMasterConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$actionQuery = (string)($_GET['action'] ?? '');

if ($method === 'GET' && $actionQuery === 'view') {
    $idLoginView = (int)($_GET['id_login'] ?? 0);
    try {
        outputAvatarImage($pdo, $idEmpresa, $idLoginView);
    } catch (Throwable $e) {
        outputAvatarFallbackSvg('U');
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    ensureUploadWithinPhpLimits();

    if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) {
        $action = (string)($_POST['action'] ?? 'upload');
        $idLogin = (int)($_POST['id_login'] ?? 0);
        assertAvatarUpdateAllowed($idLogin, $sessionIdLogin);
        if ($action !== 'upload') {
            echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
            exit;
        }
        handleUploadFile($pdo, $idEmpresa, $idLogin);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($input['action'] ?? '');
    $idLogin = (int)($input['id_login'] ?? 0);
    assertAvatarUpdateAllowed($idLogin, $sessionIdLogin);

    if ($action === 'upload_url') {
        handleUploadUrl($pdo, $idEmpresa, $idLogin, (string)($input['image_url'] ?? ''));
        exit;
    }

    if ($action === 'remove') {
        handleRemove($pdo, $idEmpresa, $idLogin);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function assertAvatarUpdateAllowed(int $idLogin, int $sessionIdLogin): void
{
    if ($idLogin > 0 && $sessionIdLogin > 0 && $idLogin === $sessionIdLogin) {
        return;
    }
    Permission::requirePermission('usuarios', 'priv_update');
}

function assertUserBelongs(PDO $pdo, int $idEmpresa, int $idLogin): array
{
    if ($idLogin <= 0) {
        throw new Exception('Usuario inválido');
    }
    $stmt = $pdo->prepare("SELECT id_login, foto, login, name FROM " . MASTER_DB . ".sec_users WHERE id_login = :id AND id_empresa = :emp LIMIT 1");
    $stmt->execute([':id' => $idLogin, ':emp' => $idEmpresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        throw new Exception('Usuario no encontrado');
    }
    return $row;
}

function parseIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $bytes = (int)$value;
    switch ($unit) {
        case 'g':
            $bytes *= 1024;
        case 'm':
            $bytes *= 1024;
        case 'k':
            $bytes *= 1024;
            break;
    }
    return $bytes;
}

function ensureUploadWithinPhpLimits(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return;
    }
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0) {
        return;
    }
    $postMax = parseIniBytes((string)ini_get('post_max_size'));
    if ($postMax > 0 && $contentLength > $postMax) {
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'error' => 'La imagen excede el limite permitido del servidor',
            'code' => 'payload_too_large',
            'max_bytes' => $postMax,
        ]);
        exit;
    }
}

function avatarStorageCandidates(): array
{
    $projectRoot = dirname(__DIR__, 3);
    $runtimeDir = rtrim((string)sys_get_temp_dir(), '/') . '/sistemax_avatars';
    return [
        [$projectRoot . '/public/_lib/file/usuario', '/public/_lib/file/usuario/'],
        [$projectRoot . '/public/_lib/file/img/usuario', '/public/_lib/file/img/usuario/'],
        [$runtimeDir, ''],
    ];
}

function avatarDir(): array
{
    foreach (avatarStorageCandidates() as [$dir, $urlBase]) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return [$dir, $urlBase];
        }
    }

    throw new Exception('No hay un directorio escribible para avatars');
}

function findAvatarFile(string $foto): ?string
{
    $foto = trim($foto);
    if ($foto === '' || $foto === 'defaultuser.png') {
        return null;
    }

    if (preg_match('#^https?://#i', $foto)) {
        return $foto;
    }

    $basename = basename($foto);
    foreach (avatarStorageCandidates() as [$dir]) {
        $path = rtrim($dir, '/') . '/' . $basename;
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function outputAvatarFallbackSvg(string $label = 'U'): void
{
    $label = strtoupper(substr(trim($label) !== '' ? trim($label) : 'U', 0, 1));
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96">'
        . '<rect width="96" height="96" rx="48" fill="#475569"/>'
        . '<text x="48" y="57" text-anchor="middle" font-family="Arial, sans-serif" font-size="34" font-weight="700" fill="#ffffff">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</text></svg>';
}

function outputAvatarImage(PDO $pdo, int $idEmpresa, int $idLogin): void
{
    $user = assertUserBelongs($pdo, $idEmpresa, $idLogin);
    $foto = trim((string)($user['foto'] ?? ''));
    $name = trim((string)($user['name'] ?? $user['login'] ?? 'U'));
    $label = $name !== '' ? $name : 'U';

    if ($foto === '' || $foto === 'defaultuser.png') {
        outputAvatarFallbackSvg($label);
        return;
    }

    $found = findAvatarFile($foto);
    if ($found === null) {
        outputAvatarFallbackSvg($label);
        return;
    }

    if (preg_match('#^https?://#i', $found)) {
        header('Location: ' . $found, true, 302);
        return;
    }

    $mime = (string)(mime_content_type($found) ?: 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($found));
    header('Cache-Control: public, max-age=300');
    readfile($found);
}

function deletePreviousAvatarFile(?string $foto): void
{
    $foto = trim((string)$foto);
    if ($foto === '' || $foto === 'defaultuser.png') {
        return;
    }
    if (preg_match('#^https?://#i', $foto)) {
        return;
    }

    $basename = basename($foto);
    foreach (avatarStorageCandidates() as [$dir]) {
        $path = rtrim($dir, '/') . '/' . $basename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function normalizeImageBinary(string $binary, string $fallbackName = 'avatar'): array
{
    if ($binary === '') {
        throw new Exception('Imagen vacía');
    }
    $imgInfo = @getimagesizefromstring($binary);
    $mime = strtolower((string)($imgInfo['mime'] ?? ''));
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'];
    if (!in_array($mime, $allowed, true)) {
        throw new Exception('Tipo de imagen no permitido');
    }

    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($binary);
        if ($im !== false) {
            imagesavealpha($im, true);
            ob_start();
            if (function_exists('imagewebp')) {
                imagewebp($im, null, 86);
                $out = (string)ob_get_clean();
                imagedestroy($im);
                if ($out !== '') {
                    return ['binary' => $out, 'ext' => 'webp', 'mime' => 'image/webp'];
                }
            } else {
                imagepng($im);
                $out = (string)ob_get_clean();
                imagedestroy($im);
                if ($out !== '') {
                    return ['binary' => $out, 'ext' => 'png', 'mime' => 'image/png'];
                }
            }
        }
    }

    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
    ];
    return ['binary' => $binary, 'ext' => ($extMap[$mime] ?? 'jpg'), 'mime' => $mime];
}

function saveAvatarForUser(PDO $pdo, array $user, string $binary): void
{
    $normalized = normalizeImageBinary($binary, (string)($user['login'] ?? 'avatar'));
    [$dir] = avatarDir();
    $filename = 'u' . (int)$user['id_login'] . '_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $normalized['ext'];
    $fullPath = $dir . '/' . $filename;
    if (@file_put_contents($fullPath, $normalized['binary']) === false) {
        throw new Exception('No se pudo guardar el avatar en disco');
    }

    deletePreviousAvatarFile((string)($user['foto'] ?? ''));

    $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".sec_users SET foto = :foto WHERE id_login = :id AND id_empresa = :emp");
    $stmt->execute([
        ':foto' => $filename,
        ':id' => (int)$user['id_login'],
        ':emp' => (int)($_SESSION['id_empresa'] ?? 0),
    ]);

    echo json_encode([
        'ok' => true,
        'msg' => 'Avatar actualizado',
        'foto' => $filename,
        'avatar_url' => 'api/avatar.php?action=view&id_login=' . (int)$user['id_login'] . '&v=' . rawurlencode($filename),
    ]);
}

function handleUploadFile(PDO $pdo, int $idEmpresa, int $idLogin): void
{
    $user = assertUserBelongs($pdo, $idEmpresa, $idLogin);
    if (!isset($_FILES['avatar'])) {
        throw new Exception('No se recibió archivo de imagen');
    }
    $uploadError = (int)($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            http_response_code(413);
            echo json_encode([
                'ok' => false,
                'error' => 'La imagen excede el tamaño permitido',
                'code' => 'payload_too_large',
                'max_bytes' => min(
                    parseIniBytes((string)ini_get('upload_max_filesize')),
                    parseIniBytes((string)ini_get('post_max_size'))
                ),
            ]);
            exit;
        }
        if ($uploadError === UPLOAD_ERR_PARTIAL) {
            throw new Exception('La carga del archivo quedó incompleta');
        }
        throw new Exception('No se recibió archivo de imagen');
    }
    $tmp = (string)($_FILES['avatar']['tmp_name'] ?? '');
    $size = (int)($_FILES['avatar']['size'] ?? 0);
    if ($size <= 0 || $size > (8 * 1024 * 1024)) {
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'error' => 'La imagen excede el tamaño permitido',
            'code' => 'payload_too_large',
            'max_bytes' => 8 * 1024 * 1024,
        ]);
        exit;
    }
    $binary = (string)@file_get_contents($tmp);
    saveAvatarForUser($pdo, $user, $binary);
}

function handleUploadUrl(PDO $pdo, int $idEmpresa, int $idLogin, string $imageUrl): void
{
    $user = assertUserBelongs($pdo, $idEmpresa, $idLogin);
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '' || !preg_match('#^https?://#i', $imageUrl)) {
        throw new Exception('URL de imagen inválida');
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $imageUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SistemaX Avatar Bot/1.0',
    ]);
    $binary = (string)curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = (string)curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $binary === '') {
        throw new Exception('No se pudo descargar la imagen' . ($curlErr !== '' ? ': ' . $curlErr : ''));
    }
    saveAvatarForUser($pdo, $user, $binary);
}

function handleRemove(PDO $pdo, int $idEmpresa, int $idLogin): void
{
    $user = assertUserBelongs($pdo, $idEmpresa, $idLogin);
    deletePreviousAvatarFile((string)($user['foto'] ?? ''));
    $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".sec_users SET foto = 'defaultuser.png' WHERE id_login = :id AND id_empresa = :emp");
    $stmt->execute([':id' => $idLogin, ':emp' => $idEmpresa]);
    echo json_encode(['ok' => true, 'msg' => 'Avatar eliminado', 'foto' => 'defaultuser.png', 'avatar_url' => '']);
}
