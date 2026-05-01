<?php
require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
if (!in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acceso reservado a soporte';
    exit;
}

$deviceId = (int)($_GET['device_id'] ?? 0);
if ($deviceId <= 0) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'device_id invalido';
    exit;
}

$pdo = Database::getMasterConnection();
$stmt = $pdo->prepare("SELECT file_path, mime_type
    FROM " . MASTER_DB . ".smx_assist_device_frames
    WHERE device_id = ?
    ORDER BY id DESC
    LIMIT 1");
$stmt->execute([$deviceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Sin frame';
    exit;
}

$relativePath = ltrim((string)($row['file_path'] ?? ''), '/');
$fullPath = dirname(__DIR__) . '/soporte/uploads/assist_frames/' . $relativePath;
if ($relativePath === '' || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Frame no encontrado';
    exit;
}

$mimeType = trim((string)($row['mime_type'] ?? 'image/jpeg')) ?: 'image/jpeg';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($fullPath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($fullPath);
exit;
