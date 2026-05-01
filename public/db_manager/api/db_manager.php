<?php

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Empresas/SuscripcionController.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function dbmOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Session::isLoggedIn()) {
    dbmOut(['ok' => false, 'error' => 'No autorizado. Inicie sesión nuevamente.']);
}

function dbmHasAccess(): bool
{
    if (strtoupper((string)($_SESSION['usr_priv_admin'] ?? 'N')) === 'Y') {
        return true;
    }
    if (Permission::hasAccess('app_grid_db_manager') || Permission::hasAccess('app_grid_db_migrador')) {
        return true;
    }

    $idEmpresa = (int)Session::getIdEmpresa();
    $appsEmpresa = SuscripcionController::getAppsEmpresa($idEmpresa);
    if (($appsEmpresa['success'] ?? false) !== true) {
        return false;
    }
    foreach (($appsEmpresa['data'] ?? []) as $app) {
        $codigo = (string)($app['codigo'] ?? '');
        if ($codigo === 'db_manager' || $codigo === 'db_migrador') {
            return true;
        }
    }
    return false;
}

if (!dbmHasAccess()) {
    dbmOut(['ok' => false, 'error' => 'Acceso restringido. App no asignada o sin permiso.']);
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST ?? [];
}

function dbmMysqlInitCommandAttribute(): int
{
    return defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
        ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
        : PDO::MYSQL_ATTR_INIT_COMMAND;
}

function dbmSafeId(string $name): string
{
    $name = trim($name);
    if ($name === '' || str_contains($name, "\0") || strpos($name, '..') !== false) {
        throw new Exception("Identificador inválido: {$name}");
    }
    return '`' . str_replace('`', '``', $name) . '`';
}

function dbmCfg(array $raw): array
{
    $cfg = [
        'host' => trim((string)($raw['host'] ?? '')),
        'port' => (int)($raw['port'] ?? 3306),
        'user' => trim((string)($raw['user'] ?? '')),
        'password' => (string)($raw['password'] ?? ''),
        'database' => trim((string)($raw['database'] ?? '')),
    ];
    if ($cfg['host'] === '' || $cfg['user'] === '') {
        throw new Exception('Host y usuario son obligatorios');
    }
    if ($cfg['port'] <= 0) {
        $cfg['port'] = 3306;
    }
    return $cfg;
}

function dbmConnect(array $cfg, ?string $dbName = null): PDO
{
    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=utf8mb4';
    if ($dbName !== null && $dbName !== '') {
        $dsn .= ';dbname=' . $dbName;
    }
    return new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        dbmMysqlInitCommandAttribute() => 'SET NAMES utf8mb4',
    ]);
}

function dbmEnsureServersTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saas_db_migrador_servers (
            id_server INT NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            host VARCHAR(190) NOT NULL,
            port INT NOT NULL DEFAULT 3306,
            `user` VARCHAR(120) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            database_default VARCHAR(120) NULL,
            observacion VARCHAR(255) NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_server),
            KEY idx_activo_nombre (activo, nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function dbmReadServer(int $idServer): array
{
    if ($idServer <= 0) {
        throw new Exception('Servidor inválido');
    }
    $db = Database::getMasterConnection();
    dbmEnsureServersTable($db);
    $stmt = $db->prepare('SELECT id_server, nombre, host, port, `user`, `password`, database_default, observacion, activo FROM saas_db_migrador_servers WHERE id_server = ? LIMIT 1');
    $stmt->execute([$idServer]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Servidor no encontrado');
    }
    return [
        'host' => (string)$row['host'],
        'port' => (int)$row['port'],
        'user' => (string)$row['user'],
        'password' => (string)$row['password'],
        'database' => (string)($row['database_default'] ?? ''),
        '_meta' => $row,
    ];
}

function dbmRequestCfg(array $input): array
{
    if (!empty($input['server_id'])) {
        $cfg = dbmReadServer((int)$input['server_id']);
        if (!empty($input['database'])) {
            $cfg['database'] = trim((string)$input['database']);
        }
        return $cfg;
    }
    return dbmCfg((array)($input['config'] ?? []));
}

function dbmIsReadQuery(string $sql): bool
{
    $sql = ltrim(preg_replace('~/\*.*?\*/~s', '', $sql) ?? $sql);
    return preg_match('~^(select|show|describe|desc|explain|with)\b~i', $sql) === 1;
}

function dbmSplitStatements(string $sql): array
{
    $items = array_values(array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== ''));
    return $items ?: [trim($sql)];
}

function dbmRows(PDOStatement $stmt, int $limit = 1000): array
{
    $rows = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false && count($rows) < $limit) {
        $rows[] = $row;
    }
    return $rows;
}

try {
    if ($action === 'list_servers') {
        $db = Database::getMasterConnection();
        dbmEnsureServersTable($db);
        $stmt = $db->query('SELECT id_server, nombre, host, port, `user`, `password`, database_default, observacion, activo FROM saas_db_migrador_servers ORDER BY activo DESC, nombre ASC');
        dbmOut(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'test_connection') {
        $cfg = dbmRequestCfg($input);
        $pdo = dbmConnect($cfg, $cfg['database'] ?: null);
        dbmOut(['ok' => true, 'data' => [
            'version' => (string)$pdo->query('SELECT VERSION()')->fetchColumn(),
            'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
        ]]);
    }

    if ($action === 'list_databases') {
        $cfg = dbmRequestCfg($input);
        $pdo = dbmConnect($cfg, null);
        $blocked = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        $rows = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_NUM);
        $dbs = [];
        foreach ($rows as $row) {
            $dbName = (string)($row[0] ?? '');
            if ($dbName !== '' && !in_array(strtolower($dbName), $blocked, true)) {
                $dbs[] = $dbName;
            }
        }
        sort($dbs);
        dbmOut(['ok' => true, 'data' => $dbs]);
    }

    if ($action === 'list_objects') {
        $cfg = dbmRequestCfg($input);
        $database = trim((string)($input['database'] ?? $cfg['database']));
        if ($database === '') {
            throw new Exception('Base requerida');
        }
        $pdo = dbmConnect($cfg, $database);
        $stmt = $pdo->prepare("SELECT table_name, table_type, engine, table_rows, ROUND((data_length + index_length) / 1024, 2) AS size_kb, table_comment FROM information_schema.tables WHERE table_schema = :db ORDER BY table_type, table_name");
        $stmt->execute([':db' => $database]);
        $tables = [];
        $views = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = [
                'name' => (string)$row['table_name'],
                'type' => (string)$row['table_type'],
                'engine' => (string)($row['engine'] ?? ''),
                'rows' => (int)($row['table_rows'] ?? 0),
                'size_kb' => (float)($row['size_kb'] ?? 0),
                'comment' => (string)($row['table_comment'] ?? ''),
            ];
            if (strtoupper((string)$row['table_type']) === 'VIEW') {
                $views[] = $item;
            } else {
                $tables[] = $item;
            }
        }
        $routines = [];
        try {
            $stmtR = $pdo->prepare("SELECT routine_name, routine_type FROM information_schema.routines WHERE routine_schema = :db ORDER BY routine_type, routine_name");
            $stmtR->execute([':db' => $database]);
            $routines = $stmtR->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $routines = [];
        }
        dbmOut(['ok' => true, 'data' => ['tables' => $tables, 'views' => $views, 'routines' => $routines]]);
    }

    if ($action === 'table_columns') {
        $cfg = dbmRequestCfg($input);
        $database = trim((string)($input['database'] ?? $cfg['database']));
        $table = trim((string)($input['table'] ?? ''));
        if ($database === '' || $table === '') {
            throw new Exception('Base y tabla requeridas');
        }
        $pdo = dbmConnect($cfg, $database);
        $stmt = $pdo->query('SHOW FULL COLUMNS FROM ' . dbmSafeId($table));
        $indexes = $pdo->query('SHOW INDEX FROM ' . dbmSafeId($table))->fetchAll(PDO::FETCH_ASSOC);
        $create = $pdo->query('SHOW CREATE TABLE ' . dbmSafeId($table))->fetch(PDO::FETCH_ASSOC) ?: [];
        $createSql = '';
        foreach ($create as $key => $value) {
            if (stripos((string)$key, 'Create') !== false) {
                $createSql = (string)$value;
            }
        }
        dbmOut(['ok' => true, 'data' => ['columns' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'indexes' => $indexes, 'create_sql' => $createSql]]);
    }

    if ($action === 'table_data') {
        $cfg = dbmRequestCfg($input);
        $database = trim((string)($input['database'] ?? $cfg['database']));
        $table = trim((string)($input['table'] ?? ''));
        $limit = min(1000, max(1, (int)($input['limit'] ?? 100)));
        $offset = max(0, (int)($input['offset'] ?? 0));
        $where = trim((string)($input['where'] ?? ''));
        if ($database === '' || $table === '') {
            throw new Exception('Base y tabla requeridas');
        }
        $pdo = dbmConnect($cfg, $database);
        $sql = 'SELECT * FROM ' . dbmSafeId($table);
        if ($where !== '') {
            if (preg_match('~;|--|/\*~', $where)) {
                throw new Exception('Filtro inválido');
            }
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $pdo->query($sql);
        dbmOut(['ok' => true, 'data' => ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'limit' => $limit, 'offset' => $offset, 'sql' => $sql]]);
    }

    if ($action === 'run_query') {
        $cfg = dbmRequestCfg($input);
        $database = trim((string)($input['database'] ?? $cfg['database']));
        $sql = trim((string)($input['sql'] ?? ''));
        if ($sql === '') {
            throw new Exception('SQL requerido');
        }
        $pdo = dbmConnect($cfg, $database ?: null);
        $results = [];
        foreach (dbmSplitStatements($sql) as $statement) {
            $started = microtime(true);
            if (dbmIsReadQuery($statement)) {
                $stmt = $pdo->query($statement);
                $results[] = [
                    'type' => 'rows',
                    'statement' => $statement,
                    'rows' => dbmRows($stmt, 1000),
                    'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
                ];
            } else {
                $affected = $pdo->exec($statement);
                $results[] = [
                    'type' => 'ok',
                    'statement' => $statement,
                    'affected_rows' => (int)$affected,
                    'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
                ];
            }
        }
        dbmOut(['ok' => true, 'data' => $results]);
    }

    if ($action === 'table_action') {
        $cfg = dbmRequestCfg($input);
        $database = trim((string)($input['database'] ?? $cfg['database']));
        $table = trim((string)($input['table'] ?? ''));
        $op = trim((string)($input['op'] ?? ''));
        $confirm = trim((string)($input['confirm'] ?? ''));
        if ($database === '' || $table === '') {
            throw new Exception('Base y tabla requeridas');
        }
        $pdo = dbmConnect($cfg, $database);
        if ($op === 'truncate') {
            if ($confirm !== $table) {
                throw new Exception('Confirmación requerida');
            }
            $pdo->exec('TRUNCATE TABLE ' . dbmSafeId($table));
            dbmOut(['ok' => true, 'message' => 'Tabla truncada']);
        }
        if ($op === 'drop') {
            if ($confirm !== $table) {
                throw new Exception('Confirmación requerida');
            }
            $pdo->exec('DROP TABLE ' . dbmSafeId($table));
            dbmOut(['ok' => true, 'message' => 'Tabla eliminada']);
        }
        throw new Exception('Operación inválida');
    }

    if ($action === 'database_action') {
        $cfg = dbmRequestCfg($input);
        $database = trim((string)($input['database'] ?? ''));
        $op = trim((string)($input['op'] ?? ''));
        $confirm = trim((string)($input['confirm'] ?? ''));
        if ($database === '') {
            throw new Exception('Base requerida');
        }
        $pdo = dbmConnect($cfg, null);
        if ($op === 'create') {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . dbmSafeId($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            dbmOut(['ok' => true, 'message' => 'Base creada']);
        }
        if ($op === 'drop') {
            if ($confirm !== $database) {
                throw new Exception('Confirmación requerida');
            }
            $pdo->exec('DROP DATABASE ' . dbmSafeId($database));
            dbmOut(['ok' => true, 'message' => 'Base eliminada']);
        }
        throw new Exception('Operación inválida');
    }

    dbmOut(['ok' => false, 'error' => 'Acción no válida']);
} catch (Throwable $e) {
    error_log('[db_manager] ' . $action . ': ' . $e->getMessage());
    dbmOut(['ok' => false, 'error' => $e->getMessage()]);
}
