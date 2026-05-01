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

@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$boundary = 'assistframe';
header('Content-Type: multipart/x-mixed-replace; boundary=' . $boundary);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');

$stmt = $pdo->prepare("SELECT id, file_path, mime_type
    FROM " . MASTER_DB . ".smx_assist_device_frames
    WHERE device_id = ?
    ORDER BY id DESC
    LIMIT 1");

$lastFrameId = 0;
$startedAt = time();
$maxSeconds = 300;

while (!connection_aborted() && (time() - $startedAt) < $maxSeconds) {
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $frameId = (int)($row['id'] ?? 0);

    if ($frameId > 0 && $frameId !== $lastFrameId) {
        $relativePath = ltrim((string)($row['file_path'] ?? ''), '/');
        $fullPath = dirname(__DIR__) . '/soporte/uploads/assist_frames/' . $relativePath;
        if ($relativePath !== '' && is_file($fullPath)) {
            $binary = file_get_contents($fullPath);
            if (is_string($binary) && $binary !== '') {
                $mimeType = trim((string)($row['mime_type'] ?? 'image/jpeg')) ?: 'image/jpeg';
                echo "--{$boundary}\r\n";
                echo "Content-Type: {$mimeType}\r\n";
                echo 'Content-Length: ' . strlen($binary) . "\r\n\r\n";
                echo $binary;
                echo "\r\n";
                flush();
                $lastFrameId = $frameId;
            }
        }
    }

    usleep(300000);
}

echo "--{$boundary}--\r\n";
flush();
exit;
