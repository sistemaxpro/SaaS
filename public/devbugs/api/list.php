<?php
require_once __DIR__ . '/_common.php';

Session::start();

$status = trim((string)($_GET['status'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));
$limit = max(10, min(300, (int)($_GET['limit'] ?? 100)));

try {
    $pdo = devbugs_pdo();
    devbugs_ensure_schema($pdo);

    $where = ['1=1'];
    $params = [];

    if ($status !== '' && $status !== 'all') {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(incident LIKE :q OR empresa_reportante LIKE :q2 OR usuario_reportante LIKE :q3 OR app LIKE :q4 OR error_msg LIKE :q5)';
        $params[':q'] = "%$search%";
        $params[':q2'] = "%$search%";
        $params[':q3'] = "%$search%";
        $params[':q4'] = "%$search%";
        $params[':q5'] = "%$search%";
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT id, incident, id_empresa_reportante, empresa_reportante, usuario_reportante, app,
                   error_msg, error_detail, source_url, affected_empresas_json, affected_usuarios_json,
                   status, analysis_note, analyzed_by, analyzed_at, created_at, updated_at
            FROM " . MASTER_DB . ".dev_bug_reports
            WHERE $whereSql
            ORDER BY created_at DESC
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    devbugs_json(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
    devbugs_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
