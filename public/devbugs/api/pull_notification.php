<?php
require_once __DIR__ . '/_common.php';

Session::start();
$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
$usuario = trim((string)($_SESSION['login'] ?? $_SESSION['usuario'] ?? ''));
if ($idEmpresa <= 0 || $usuario === '') {
    devbugs_json(['ok' => false, 'error' => 'Sin sesión'], 401);
}

try {
    $pdo = devbugs_pdo();
    devbugs_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, incident, title, message, created_at
        FROM " . MASTER_DB . ".dev_bug_user_notifications
        WHERE id_empresa_dest = :emp
          AND LOWER(TRIM(usuario_dest)) = LOWER(TRIM(:usr))
          AND status = 'unread'
        ORDER BY created_at ASC
        LIMIT 1
    ");
    $stmt->execute([':emp' => $idEmpresa, ':usr' => $usuario]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        devbugs_json(['ok' => true, 'notification' => null]);
    }

    $upd = $pdo->prepare("UPDATE " . MASTER_DB . ".dev_bug_user_notifications SET status = 'read', read_at = NOW() WHERE id = :id");
    $upd->execute([':id' => (int)$row['id']]);

    devbugs_json(['ok' => true, 'notification' => $row]);
} catch (Throwable $e) {
    devbugs_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
