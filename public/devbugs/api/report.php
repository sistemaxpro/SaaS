<?php
require_once __DIR__ . '/_common.php';

Session::start();
$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
if ($idEmpresa <= 0) {
    devbugs_json(['ok' => false, 'error' => 'Sin sesión'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    devbugs_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$incident = trim((string)($input['incident'] ?? ''));
$app = trim((string)($input['app'] ?? ''));
$error = trim((string)($input['error'] ?? ''));
$detail = trim((string)($input['detail'] ?? ''));
$url = trim((string)($input['url'] ?? ''));

$errorLow = function_exists('mb_strtolower') ? mb_strtolower($error, 'UTF-8') : strtolower($error);
$urlLow = function_exists('mb_strtolower') ? mb_strtolower($url, 'UTF-8') : strtolower($url);
$isAbortNoise = (
    (strpos($errorLow, 'signal is aborted without reason') !== false || strpos($errorLow, 'abort') !== false)
    && strpos($urlLow, '/public/menu/api/chat.php') !== false
);
if ($isAbortNoise) {
    devbugs_json([
        'ok' => true,
        'msg' => 'Reporte ignorado (abort de polling/chat transitorio)',
        'incident' => $incident,
        'ignored' => true
    ]);
}

if ($incident === '' || $error === '') {
    devbugs_json(['ok' => false, 'error' => 'Datos incompletos'], 422);
}

$empresa = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa ' . $idEmpresa));
$usuario = (string)($_SESSION['login'] ?? $_SESSION['usuario'] ?? '');

try {
    $pdo = devbugs_pdo();
    devbugs_ensure_schema($pdo);
    $dedupeKey = devbugs_build_dedupe_key($app, $error, $detail);

    $stmtFind = $pdo->prepare("
        SELECT id, incident, affected_empresas_json, affected_usuarios_json
        FROM " . MASTER_DB . ".dev_bug_reports
        WHERE dedupe_key = :k
        LIMIT 1
    ");
    $stmtFind->execute([':k' => $dedupeKey]);
    $exists = $stmtFind->fetch(PDO::FETCH_ASSOC) ?: null;

    $empresaItem = [
        'id_empresa' => $idEmpresa,
        'empresa' => mb_substr($empresa, 0, 160),
    ];
    $usuarioItem = [
        'id_empresa' => $idEmpresa,
        'usuario' => mb_substr($usuario, 0, 120),
    ];

    if ($exists) {
        $empList = json_decode((string)($exists['affected_empresas_json'] ?? '[]'), true);
        if (!is_array($empList)) $empList = [];
        $usrList = json_decode((string)($exists['affected_usuarios_json'] ?? '[]'), true);
        if (!is_array($usrList)) $usrList = [];

        $hasEmp = false;
        foreach ($empList as $it) {
            if ((int)($it['id_empresa'] ?? 0) === $idEmpresa) {
                $hasEmp = true;
                break;
            }
        }
        if (!$hasEmp) $empList[] = $empresaItem;

        $hasUsr = false;
        foreach ($usrList as $it) {
            if ((int)($it['id_empresa'] ?? 0) === $idEmpresa && mb_strtolower(trim((string)($it['usuario'] ?? ''))) === mb_strtolower(trim((string)$usuario))) {
                $hasUsr = true;
                break;
            }
        }
        if (!$hasUsr && $usuario !== '') $usrList[] = $usuarioItem;

        $stmtUpd = $pdo->prepare("
            UPDATE " . MASTER_DB . ".dev_bug_reports
            SET updated_at = CURRENT_TIMESTAMP,
                source_url = COALESCE(NULLIF(:source_url,''), source_url),
                affected_empresas_json = :emp_json,
                affected_usuarios_json = :usr_json
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':source_url' => $url !== '' ? $url : null,
            ':emp_json' => json_encode($empList, JSON_UNESCAPED_UNICODE),
            ':usr_json' => json_encode($usrList, JSON_UNESCAPED_UNICODE),
            ':id' => (int)$exists['id'],
        ]);

        devbugs_json(['ok' => true, 'msg' => 'Reporte asociado a bug existente', 'incident' => (string)$exists['incident'], 'deduped' => true]);
    }

    $empJson = json_encode([$empresaItem], JSON_UNESCAPED_UNICODE);
    $usrJson = json_encode($usuario !== '' ? [$usuarioItem] : [], JSON_UNESCAPED_UNICODE);
    $sql = "
        INSERT INTO " . MASTER_DB . ".dev_bug_reports
            (incident, dedupe_key, id_empresa_reportante, empresa_reportante, usuario_reportante, app, error_msg, error_detail, source_url, affected_empresas_json, affected_usuarios_json, status)
        VALUES
            (:incident, :dedupe_key, :id_emp, :empresa, :usuario, :app, :error_msg, :error_detail, :source_url, :emp_json, :usr_json, 'nuevo')
        ON DUPLICATE KEY UPDATE
            updated_at = CURRENT_TIMESTAMP
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':incident' => $incident,
        ':dedupe_key' => $dedupeKey,
        ':id_emp' => $idEmpresa,
        ':empresa' => mb_substr($empresa, 0, 160),
        ':usuario' => mb_substr($usuario, 0, 120),
        ':app' => ($app !== '' ? mb_substr($app, 0, 180) : null),
        ':error_msg' => $error,
        ':error_detail' => $detail !== '' ? $detail : null,
        ':source_url' => $url !== '' ? $url : null,
        ':emp_json' => $empJson,
        ':usr_json' => $usrJson,
    ]);

    devbugs_json(['ok' => true, 'msg' => 'Reporte guardado', 'incident' => $incident, 'deduped' => false]);
} catch (Throwable $e) {
    devbugs_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
