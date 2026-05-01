<?php
require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function devbugs_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function devbugs_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = Database::getMasterConnection();
    return $pdo;
}

function devbugs_ensure_schema(PDO $pdo): void
{
    $cacheFile = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sistemax_devbugs_schema_ready.flag';
    $cacheTtlSec = 6 * 3600;
    $last = @filemtime($cacheFile);
    if ($last !== false && (time() - $last) < $cacheTtlSec) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".dev_bug_reports (
            id BIGINT NOT NULL AUTO_INCREMENT,
            incident VARCHAR(60) NOT NULL,
            id_empresa_reportante INT NOT NULL,
            empresa_reportante VARCHAR(160) NOT NULL,
            usuario_reportante VARCHAR(120) DEFAULT NULL,
            app VARCHAR(180) DEFAULT NULL,
            error_msg TEXT,
            error_detail MEDIUMTEXT,
            source_url TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'nuevo',
            analysis_note MEDIUMTEXT,
            analyzed_by VARCHAR(120) DEFAULT NULL,
            analyzed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_incident (incident),
            KEY idx_emp_created (id_empresa_reportante, created_at),
            KEY idx_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($sql);

    // Compatibilidad incremental de columnas para deduplicación y multi-empresa afectada.
    $needColumns = [
        'dedupe_key' => "ALTER TABLE " . MASTER_DB . ".dev_bug_reports ADD COLUMN dedupe_key VARCHAR(64) DEFAULT NULL AFTER incident",
        'affected_empresas_json' => "ALTER TABLE " . MASTER_DB . ".dev_bug_reports ADD COLUMN affected_empresas_json MEDIUMTEXT DEFAULT NULL AFTER source_url",
        'affected_usuarios_json' => "ALTER TABLE " . MASTER_DB . ".dev_bug_reports ADD COLUMN affected_usuarios_json MEDIUMTEXT DEFAULT NULL AFTER affected_empresas_json",
    ];
    foreach ($needColumns as $col => $ddl) {
        $stmtCol = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = :db AND table_name = 'dev_bug_reports' AND column_name = :col
        ");
        $stmtCol->execute([':db' => MASTER_DB, ':col' => $col]);
        if ((int)$stmtCol->fetchColumn() === 0) {
            $pdo->exec($ddl);
        }
    }

    $stmtIdx = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = :db AND table_name = 'dev_bug_reports' AND index_name = 'uk_dedupe_key'
    ");
    $stmtIdx->execute([':db' => MASTER_DB]);
    if ((int)$stmtIdx->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE " . MASTER_DB . ".dev_bug_reports ADD UNIQUE KEY uk_dedupe_key (dedupe_key)");
    }

    $sql2 = "
        CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".dev_bug_user_notifications (
            id BIGINT NOT NULL AUTO_INCREMENT,
            incident VARCHAR(60) NOT NULL,
            id_empresa_dest INT NOT NULL,
            usuario_dest VARCHAR(120) NOT NULL,
            title VARCHAR(180) DEFAULT NULL,
            message MEDIUMTEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'unread',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_incident_user (incident, id_empresa_dest, usuario_dest),
            KEY idx_user_status (id_empresa_dest, usuario_dest, status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($sql2);

    @file_put_contents($cacheFile, (string)time());
}

function devbugs_normalize_detail(string $detail): string
{
    $txt = str_replace(["\r\n", "\r"], "\n", $detail);
    $lines = explode("\n", $txt);
    $filtered = [];
    foreach ($lines as $ln) {
        $raw = trim((string)$ln);
        if ($raw === '') continue;
        $low = mb_strtolower($raw);
        if (strpos($low, 'incidencia:') === 0) continue;
        if (strpos($low, 'fecha:') === 0) continue;
        if (strpos($low, 'pagina:') === 0) continue;
        $raw = preg_replace('/INC-\d{8}-\d{6}-\d{4}/i', 'INC-XXX', $raw);
        $raw = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z/', 'DATE_ISO', $raw);
        $filtered[] = $raw;
    }
    return trim(implode("\n", $filtered));
}

function devbugs_build_dedupe_key(string $app, string $error, string $detail): string
{
    $base = mb_strtolower(trim($app)) . '|' . mb_strtolower(trim($error)) . '|' . mb_strtolower(devbugs_normalize_detail($detail));
    return sha1($base);
}
