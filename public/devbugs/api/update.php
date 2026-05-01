<?php
require_once __DIR__ . '/_common.php';

Session::start();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    devbugs_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$id = (int)($input['id'] ?? 0);
$status = trim((string)($input['status'] ?? ''));
$note = trim((string)($input['analysis_note'] ?? ''));

if ($id <= 0) devbugs_json(['ok' => false, 'error' => 'ID inválido'], 422);
$allowed = ['nuevo', 'en_analisis', 'resuelto'];
if (!in_array($status, $allowed, true)) {
    devbugs_json(['ok' => false, 'error' => 'Estado inválido'], 422);
}

try {
    $pdo = devbugs_pdo();
    devbugs_ensure_schema($pdo);

    $user = (string)($_SESSION['login'] ?? $_SESSION['usuario'] ?? 'admin.169');
    $sql = "UPDATE " . MASTER_DB . ".dev_bug_reports
            SET status = :status,
                analysis_note = :note,
                analyzed_by = :by,
                analyzed_at = NOW()
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status' => $status,
        ':note' => $note !== '' ? $note : null,
        ':by' => mb_substr($user, 0, 120),
        ':id' => $id,
    ]);

    if ($status === 'resuelto') {
        $stmtBug = $pdo->prepare("SELECT incident, id_empresa_reportante, usuario_reportante, affected_usuarios_json FROM " . MASTER_DB . ".dev_bug_reports WHERE id = :id LIMIT 1");
        $stmtBug->execute([':id' => $id]);
        $bug = $stmtBug->fetch(PDO::FETCH_ASSOC);
        if ($bug) {
            $msg = "Estimado/a usuario/a,\n\n"
                . "Le informamos que el inconveniente reportado ha sido corregido exitosamente por el Departamento de Desarrollo.\n\n"
                . "Agradecemos su paciencia y el aviso brindado, el cual nos ayuda a mejorar continuamente nuestro sistema.\n\n"
                . "Observación: Sivase reiniciar su sistema si la corrección no se aplica aún.\n\n"
                . "Quedamos a disposición ante cualquier otra consulta o inconveniente adicional.\n\n"
                . "Atentamente,\n"
                . "Departamento de Desarrollo";

            $targets = [];
            $affectedUsers = json_decode((string)($bug['affected_usuarios_json'] ?? '[]'), true);
            if (is_array($affectedUsers)) {
                foreach ($affectedUsers as $it) {
                    $emp = (int)($it['id_empresa'] ?? 0);
                    $usr = trim((string)($it['usuario'] ?? ''));
                    if ($emp <= 0 || $usr === '') continue;
                    $k = $emp . '|' . mb_strtolower($usr);
                    $targets[$k] = ['id_empresa' => $emp, 'usuario' => mb_substr($usr, 0, 120)];
                }
            }
            if (empty($targets) && !empty($bug['usuario_reportante'])) {
                $usrFallback = trim((string)$bug['usuario_reportante']);
                if ($usrFallback !== '') {
                    $k = ((int)$bug['id_empresa_reportante']) . '|' . mb_strtolower($usrFallback);
                    $targets[$k] = ['id_empresa' => (int)$bug['id_empresa_reportante'], 'usuario' => mb_substr($usrFallback, 0, 120)];
                }
            }

            $ins = $pdo->prepare("
                INSERT INTO " . MASTER_DB . ".dev_bug_user_notifications
                    (incident, id_empresa_dest, usuario_dest, title, message, status)
                VALUES
                    (:incident, :id_emp, :usuario, :title, :message, 'unread')
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    message = VALUES(message),
                    status = 'unread',
                    read_at = NULL
            ");
            foreach ($targets as $t) {
                $ins->execute([
                    ':incident' => (string)$bug['incident'],
                    ':id_emp' => (int)$t['id_empresa'],
                    ':usuario' => (string)$t['usuario'],
                    ':title' => '✅ Incidencia resuelta',
                    ':message' => $msg,
                ]);
            }
        }
    }

    devbugs_json(['ok' => true, 'msg' => 'Seguimiento actualizado']);
} catch (Throwable $e) {
    devbugs_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
