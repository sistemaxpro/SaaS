<?php

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Empresas/SuscripcionController.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Session::isLoggedIn()) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'No autorizado. Inicie sesión nuevamente.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mDbMigradorEmpresaAsignada(int $idEmpresa): bool
{
    $appsEmpresa = SuscripcionController::getAppsEmpresa($idEmpresa);
    if (($appsEmpresa['success'] ?? false) !== true) {
        return false;
    }
    foreach (($appsEmpresa['data'] ?? []) as $app) {
        if ((string)($app['codigo'] ?? '') === 'db_migrador') {
            return true;
        }
    }
    return false;
}

$idEmpresa = (int)Session::getIdEmpresa();
$tieneAppAsignada = mDbMigradorEmpresaAsignada($idEmpresa);
$tienePermiso = Permission::hasAccess('app_grid_db_migrador');

if (!$tieneAppAsignada || !$tienePermiso) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'Acceso restringido. App no asignada o sin permiso.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = trim((string)($_GET['action'] ?? ''));
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST ?? [];
}

function mOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mSafeId(string $name): string
{
    $name = trim($name);
    if ($name === '' || str_contains($name, "\0")) {
        throw new Exception("Identificador inválido: {$name}");
    }
    return '`' . str_replace('`', '``', $name) . '`';
}

function mCfg(array $raw): array
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

function mConnect(array $cfg, ?string $dbName = null): PDO
{
    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=utf8mb4';
    if ($dbName !== null && $dbName !== '') {
        $dsn .= ';dbname=' . $dbName;
    }
    return new PDO(
        $dsn,
        $cfg['user'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            mMysqlInitCommandAttribute() => "SET NAMES utf8mb4",
        ]
    );
}

function mMysqlInitCommandAttribute(): int
{
    return defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
        ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
        : PDO::MYSQL_ATTR_INIT_COMMAND;
}

function mRowValue(array $row, string $key, $default = null, ?int $numericIndex = null)
{
    if (array_key_exists($key, $row)) {
        return $row[$key];
    }
    $upperKey = strtoupper($key);
    if (array_key_exists($upperKey, $row)) {
        return $row[$upperKey];
    }
    if ($numericIndex !== null && array_key_exists($numericIndex, $row)) {
        return $row[$numericIndex];
    }
    return $default;
}

function mEnsureServersTable(PDO $pdo): void
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

try {
    if ($action === 'list_servers') {
        $db = Database::getMasterConnection();
        mEnsureServersTable($db);
        $stmt = $db->query("
            SELECT id_server, nombre, host, port, `user`, `password`, database_default, observacion, activo
            FROM saas_db_migrador_servers
            ORDER BY activo DESC, nombre ASC, id_server DESC
        ");
        mOut(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($action === 'save_server') {
        $db = Database::getMasterConnection();
        mEnsureServersTable($db);
        $row = [
            'id_server' => (int)($input['id_server'] ?? 0),
            'nombre' => trim((string)($input['nombre'] ?? '')),
            'host' => trim((string)($input['host'] ?? '')),
            'port' => (int)($input['port'] ?? 3306),
            'user' => trim((string)($input['user'] ?? '')),
            'password' => (string)($input['password'] ?? ''),
            'database_default' => trim((string)($input['database_default'] ?? '')),
            'observacion' => trim((string)($input['observacion'] ?? '')),
            'activo' => !empty($input['activo']) ? 1 : 0,
        ];
        if ($row['nombre'] === '' || $row['host'] === '' || $row['user'] === '') {
            throw new Exception('Nombre, host y usuario son obligatorios');
        }
        if ($row['port'] <= 0) $row['port'] = 3306;
        if ($row['password'] === '') {
            throw new Exception('Password es obligatorio');
        }

        if ($row['id_server'] > 0) {
            $stmt = $db->prepare("
                UPDATE saas_db_migrador_servers
                SET nombre = :nombre,
                    host = :host,
                    port = :port,
                    `user` = :user,
                    `password` = :password,
                    database_default = :database_default,
                    observacion = :observacion,
                    activo = :activo
                WHERE id_server = :id
            ");
            $stmt->execute([
                ':nombre' => $row['nombre'],
                ':host' => $row['host'],
                ':port' => $row['port'],
                ':user' => $row['user'],
                ':password' => $row['password'],
                ':database_default' => $row['database_default'] !== '' ? $row['database_default'] : null,
                ':observacion' => $row['observacion'] !== '' ? $row['observacion'] : null,
                ':activo' => $row['activo'],
                ':id' => $row['id_server'],
            ]);
            mOut(['ok' => true, 'id_server' => $row['id_server']]);
        }

        $stmt = $db->prepare("
            INSERT INTO saas_db_migrador_servers
                (nombre, host, port, `user`, `password`, database_default, observacion, activo, created_by)
            VALUES
                (:nombre, :host, :port, :user, :password, :database_default, :observacion, :activo, :created_by)
        ");
        $stmt->execute([
            ':nombre' => $row['nombre'],
            ':host' => $row['host'],
            ':port' => $row['port'],
            ':user' => $row['user'],
            ':password' => $row['password'],
            ':database_default' => $row['database_default'] !== '' ? $row['database_default'] : null,
            ':observacion' => $row['observacion'] !== '' ? $row['observacion'] : null,
            ':activo' => $row['activo'],
            ':created_by' => (int)Session::getIdLogin(),
        ]);
        mOut(['ok' => true, 'id_server' => (int)$db->lastInsertId()]);
    }

    if ($action === 'delete_server') {
        $idServer = (int)($input['id_server'] ?? 0);
        if ($idServer <= 0) {
            throw new Exception('ID de servidor inválido');
        }
        $db = Database::getMasterConnection();
        mEnsureServersTable($db);
        $stmt = $db->prepare("DELETE FROM saas_db_migrador_servers WHERE id_server = :id");
        $stmt->execute([':id' => $idServer]);
        mOut(['ok' => true]);
    }

    if ($action === 'test_connection') {
        $cfg = mCfg((array)($input['config'] ?? []));
        $pdo = mConnect($cfg, $cfg['database'] ?: null);
        $ver = (string)$pdo->query("SELECT VERSION()")->fetchColumn();
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        mOut([
            'ok' => true,
            'data' => [
                'version' => $ver,
                'database' => $db,
                'host' => $cfg['host'],
                'port' => $cfg['port'],
            ],
        ]);
    }

    if ($action === 'list_databases') {
        $cfg = mCfg((array)($input['config'] ?? []));
        $pdo = mConnect($cfg, null);
        $rows = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_NUM);
        $blocked = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        $databases = [];
        foreach ($rows as $r) {
            $db = (string)($r[0] ?? '');
            if ($db === '' || in_array(strtolower($db), $blocked, true)) {
                continue;
            }
            $databases[] = $db;
        }
        sort($databases);
        mOut(['ok' => true, 'data' => $databases]);
    }

    if ($action === 'list_tables') {
        try {
            $rawCfg = (array)($input['config'] ?? []);
            $cfg = [
                'host' => trim((string)($rawCfg['host'] ?? '')),
                'port' => (int)($rawCfg['port'] ?? 3306),
                'user' => trim((string)($rawCfg['user'] ?? '')),
                'password' => (string)($rawCfg['password'] ?? ''),
                'database' => trim((string)($rawCfg['database'] ?? '')),
            ];
            if ($cfg['port'] <= 0) $cfg['port'] = 3306;
            if ($cfg['host'] === '' || $cfg['user'] === '') {
                mOut(['ok' => false, 'error' => 'Debe completar host y usuario del servidor origen']);
            }

            $dbName = trim((string)($input['database'] ?? $cfg['database']));
            if ($dbName === '') {
                mOut(['ok' => false, 'error' => 'Base de datos requerida']);
            }

            // Conectar sin DB para validar existencia y evitar 500 por "Unknown database".
            $pdoAdmin = mConnect($cfg, null);
            $dbQuoted = $pdoAdmin->quote($dbName);
            $stmtDb = $pdoAdmin->query("SHOW DATABASES LIKE {$dbQuoted}");
            if (!$stmtDb || !$stmtDb->fetch(PDO::FETCH_NUM)) {
                mOut(['ok' => false, 'error' => "La base '{$dbName}' no existe en el servidor origen"]);
            }

            $pdo = mConnect($cfg, $dbName);
            $stmt = $pdo->prepare("
                SELECT
                    table_name AS tn,
                    COALESCE(table_rows, 0) AS tr,
                    COALESCE(data_length, 0) + COALESCE(index_length, 0) AS tb
                FROM information_schema.tables
                WHERE table_schema = :db
                  AND table_type = 'BASE TABLE'
                ORDER BY table_name ASC
            ");
            $stmt->execute([':db' => $dbName]);
            $tables = [];
            foreach ($stmt->fetchAll() as $r) {
                $tableName = (string)mRowValue($r, 'tn', '');
                $tableRows = (int)mRowValue($r, 'tr', 0);
                $totalBytes = (int)mRowValue($r, 'tb', 0);
                $tables[] = [
                    'name' => $tableName,
                    'rows' => $tableRows,
                    'size_kb' => round($totalBytes / 1024, 2),
                ];
            }
            mOut(['ok' => true, 'data' => $tables]);
        } catch (Throwable $e) {
            mOut(['ok' => false, 'error' => 'No se pudo listar tablas: ' . $e->getMessage()]);
        }
    }

    if ($action === 'migrate') {
        @set_time_limit(0);
        $sourceCfg = mCfg((array)($input['source'] ?? []));
        $destCfg = mCfg((array)($input['destination'] ?? []));
        $sourceDb = trim((string)($input['source_db'] ?? $sourceCfg['database']));
        $destDb = trim((string)($input['destination_db'] ?? $destCfg['database']));
        if ($sourceDb === '' || $destDb === '') {
            mOut(['ok' => false, 'error' => 'Debe seleccionar base origen y destino']);
        }

        $opts = (array)($input['options'] ?? []);
        $copyStructure = !empty($opts['copy_structure']);
        $copyData = !empty($opts['copy_data']);
        if (!$copyStructure && !$copyData) {
            mOut(['ok' => false, 'error' => 'Debe activar al menos una opción: sincronización de estructuras o transferencia de datos']);
        }
        $dropTable = !empty($opts['drop_table']);
        $truncateTable = !empty($opts['truncate_table']);
        $createDestDb = !array_key_exists('create_destination_db', $opts) || (bool)$opts['create_destination_db'];
        $stopOnError = !array_key_exists('stop_on_error', $opts) || (bool)$opts['stop_on_error'];
        $batchSize = (int)($opts['batch_size'] ?? 500);
        if ($batchSize < 100) $batchSize = 100;
        if ($batchSize > 5000) $batchSize = 5000;
        $dataOffsetInput = (int)($input['data_offset'] ?? 0);
        if ($dataOffsetInput < 0) $dataOffsetInput = 0;
        $maxRowsPerRequest = (int)($input['max_rows_per_request'] ?? 3000);
        if ($maxRowsPerRequest < $batchSize) $maxRowsPerRequest = $batchSize;
        if ($maxRowsPerRequest > 50000) $maxRowsPerRequest = 50000;
        $insertModeRaw = strtolower(trim((string)($opts['insert_mode'] ?? 'insert_ignore')));
        $insertMode = in_array($insertModeRaw, ['insert', 'replace', 'insert_ignore'], true) ? $insertModeRaw : 'insert';

        $selectedTables = array_values(array_filter(array_map(
            static fn($t) => trim((string)$t),
            (array)($input['tables'] ?? [])
        )));

        $logs = [];
        $appendLog = static function (string $msg) use (&$logs): void {
            $logs[] = '[' . date('H:i:s') . '] ' . $msg;
        };

        $appendLog("Conectando a origen {$sourceCfg['host']}:{$sourceCfg['port']} / {$sourceDb}");
        $source = mConnect($sourceCfg, $sourceDb);
        $appendLog("Conectando a destino {$destCfg['host']}:{$destCfg['port']} / {$destDb}");
        $destAdmin = mConnect($destCfg, null);
        if ($createDestDb) {
            $appendLog("Verificando base destino {$destDb}");
            $destAdmin->exec('CREATE DATABASE IF NOT EXISTS ' . mSafeId($destDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $dest = mConnect($destCfg, $destDb);
        $dest->exec('SET FOREIGN_KEY_CHECKS=0');

        if (empty($selectedTables)) {
            $appendLog('No se enviaron tablas; obteniendo todas del origen...');
            $stmt = $source->prepare("
                SELECT table_name AS tn
                FROM information_schema.tables
                WHERE table_schema = :db
                  AND table_type = 'BASE TABLE'
                ORDER BY table_name
            ");
            $stmt->execute([':db' => $sourceDb]);
            $selectedTables = array_values(array_filter(array_map(
                static fn($r) => (string)mRowValue((array)$r, 'tn', ''),
                $stmt->fetchAll()
            )));
        }

        // Validar que las tablas seleccionadas existan realmente en el origen.
        $stmtExisting = $source->prepare("
            SELECT table_name AS tn
            FROM information_schema.tables
            WHERE table_schema = :db
              AND table_type = 'BASE TABLE'
        ");
        $stmtExisting->execute([':db' => $sourceDb]);
        $existingSet = [];
        foreach ($stmtExisting->fetchAll() as $row) {
            $tn = (string)mRowValue((array)$row, 'tn', '');
            if ($tn !== '') {
                $existingSet[$tn] = true;
            }
        }
        $stmtExistingDest = $dest->prepare("
            SELECT table_name AS tn
            FROM information_schema.tables
            WHERE table_schema = :db
              AND table_type = 'BASE TABLE'
        ");
        $stmtExistingDest->execute([':db' => $destDb]);
        $existingDestSet = [];
        foreach ($stmtExistingDest->fetchAll() as $row) {
            $tn = (string)mRowValue((array)$row, 'tn', '');
            if ($tn !== '') {
                $existingDestSet[$tn] = true;
            }
        }
        $missingTables = [];
        $foundInDestOnly = [];
        foreach ($selectedTables as $tableName) {
            if (!isset($existingSet[$tableName])) {
                $missingTables[] = $tableName;
                if (isset($existingDestSet[$tableName])) {
                    $foundInDestOnly[] = $tableName;
                }
            }
        }
        if (!empty($selectedTables) && count($missingTables) === count($selectedTables) && !empty($foundInDestOnly)) {
            mOut([
                'ok' => false,
                'error' => "Origen y destino parecen invertidos: las tablas seleccionadas no existen en '{$sourceDb}' pero sí en '{$destDb}'.",
                'logs' => $logs
            ]);
        }
        if (!empty($missingTables)) {
            $appendLog('Tablas no encontradas en origen: ' . implode(', ', $missingTables));
            $selectedTables = array_values(array_filter(
                $selectedTables,
                static fn($t) => isset($existingSet[$t])
            ));
            if (empty($selectedTables)) {
                mOut([
                    'ok' => false,
                    'error' => "Las tablas seleccionadas no existen en la base origen '{$sourceDb}'. Verifique origen/destino y vuelva a cargar tablas.",
                    'logs' => $logs
                ]);
            }
        }

        if (empty($selectedTables)) {
            mOut(['ok' => false, 'error' => 'No hay tablas para migrar']);
        }

        $summary = [
            'tables_total' => count($selectedTables),
            'tables_ok' => 0,
            'tables_error' => 0,
            'rows_total' => 0,
            'errors' => [],
        ];
        $partial = false;
        $partialProgress = [
            'table' => null,
            'next_offset' => 0,
            'done' => true,
            'rows_migrated_call' => 0,
        ];
        $abortedByError = false;
        $abortMessage = '';

        foreach ($selectedTables as $tableName) {
            try {
                $qt = mSafeId($tableName);
                $isResumeChunk = ($dataOffsetInput > 0);
                $appendLog("Migrando tabla {$tableName}");

                if ($dropTable && !$isResumeChunk) {
                    $dest->exec("DROP TABLE IF EXISTS {$qt}");
                    $appendLog(" - DROP TABLE {$tableName}");
                }

                $stmtExists = $dest->prepare("
                    SELECT 1
                    FROM information_schema.tables
                    WHERE table_schema = :db
                      AND table_name = :table
                    LIMIT 1
                ");
                $stmtExists->execute([':db' => $destDb, ':table' => $tableName]);
                $tableExists = (bool)$stmtExists->fetchColumn();

                // Crear estructura cuando:
                // 1) se pidió sincronización de estructura, o
                // 2) se copiarán datos y la tabla aún no existe en destino.
                if (($copyStructure || ($copyData && !$tableExists)) && !$isResumeChunk) {
                    if ($tableExists) {
                        if ($copyStructure) {
                            $appendLog(" - Estructura ya existe, omitida");
                        }
                    } else {
                        $stmtCreate = $source->query("SHOW CREATE TABLE {$qt}");
                        $rowCreate = $stmtCreate->fetch(PDO::FETCH_ASSOC);
                        if (!$rowCreate) {
                            throw new Exception("No se pudo obtener CREATE TABLE de {$tableName}");
                        }
                        $createSql = '';
                        foreach ($rowCreate as $k => $v) {
                            if (stripos((string)$k, 'Create Table') !== false) {
                                $createSql = (string)$v;
                                break;
                            }
                        }
                        if ($createSql === '') {
                            throw new Exception("CREATE TABLE vacío para {$tableName}");
                        }
                        $dest->exec($createSql);
                        $tableExists = true;
                        $appendLog(" - Estructura OK");
                    }
                }

                if ($copyData) {
                    if ($truncateTable && !$isResumeChunk) {
                        $dest->exec("TRUNCATE TABLE {$qt}");
                        $appendLog(" - TRUNCATE {$tableName}");
                    }

                    $srcColsStmt = $source->query("SHOW COLUMNS FROM {$qt}");
                    $srcCols = array_map(static fn($r) => (string)$r['Field'], $srcColsStmt->fetchAll());
                    $dstColsStmt = $dest->query("SHOW COLUMNS FROM {$qt}");
                    $dstCols = array_map(static fn($r) => (string)$r['Field'], $dstColsStmt->fetchAll());

                    $dstSet = [];
                    foreach ($dstCols as $dc) {
                        $dstSet[$dc] = true;
                    }
                    $cols = [];
                    $skippedCols = [];
                    foreach ($srcCols as $sc) {
                        if (isset($dstSet[$sc])) {
                            $cols[] = $sc;
                        } else {
                            $skippedCols[] = $sc;
                        }
                    }

                    if (!empty($skippedCols)) {
                        $appendLog(" - Columnas omitidas en {$tableName} (no existen en destino): " . implode(', ', $skippedCols));
                    }

                    if (!empty($cols)) {
                        $quotedCols = array_map(static fn($c) => mSafeId($c), $cols);
                        $colList = implode(', ', $quotedCols);
                        $ph = implode(', ', array_fill(0, count($cols), '?'));
                        $verb = 'INSERT';
                        if ($insertMode === 'replace') $verb = 'REPLACE';
                        if ($insertMode === 'insert_ignore') $verb = 'INSERT IGNORE';
                        $insertSql = "{$verb} INTO {$qt} ({$colList}) VALUES ({$ph})";
                        $insertStmt = $dest->prepare($insertSql);

                        $offset = $isResumeChunk ? $dataOffsetInput : 0;
                        $rowsMigratedTable = 0;
                        $reachedRequestCap = false;
                        while (true) {
                            $selSql = "SELECT {$colList} FROM {$qt} LIMIT {$batchSize} OFFSET {$offset}";
                            $chunk = $source->query($selSql)->fetchAll(PDO::FETCH_NUM);
                            if (!$chunk) {
                                break;
                            }

                            $dest->beginTransaction();
                            try {
                                foreach ($chunk as $row) {
                                    $insertStmt->execute($row);
                                }
                                $dest->commit();
                            } catch (Exception $txe) {
                                if ($dest->inTransaction()) $dest->rollBack();
                                throw $txe;
                            }

                            $countChunk = count($chunk);
                            $offset += $countChunk;
                            $rowsMigratedTable += $countChunk;
                            $summary['rows_total'] += $countChunk;
                            $appendLog(" - Datos {$tableName}: +{$countChunk} filas (total {$rowsMigratedTable})");

                            if ($rowsMigratedTable >= $maxRowsPerRequest) {
                                $reachedRequestCap = true;
                                break;
                            }
                        }

                        if ($reachedRequestCap) {
                            $partial = true;
                            $partialProgress = [
                                'table' => $tableName,
                                'next_offset' => $offset,
                                'done' => false,
                                'rows_migrated_call' => $rowsMigratedTable,
                            ];
                            $appendLog(" - Parcial {$tableName}: {$rowsMigratedTable} filas en esta solicitud. Continuar desde offset {$offset}");
                        }
                    } else {
                        throw new Exception("No hay columnas comunes entre origen y destino para {$tableName}");
                    }
                }

                if ($partial) {
                    break;
                }
                $summary['tables_ok']++;
            } catch (Exception $te) {
                $summary['tables_error']++;
                $msg = "{$tableName}: " . $te->getMessage();
                $summary['errors'][] = $msg;
                $appendLog(" - ERROR {$msg}");
                if ($stopOnError) {
                    $abortedByError = true;
                    $abortMessage = $msg;
                    break;
                }
            }
        }

        $dest->exec('SET FOREIGN_KEY_CHECKS=1');
        if ($partial) {
            mOut([
                'ok' => true,
                'partial' => true,
                'progress' => $partialProgress,
                'summary' => $summary,
                'logs' => $logs
            ]);
        }
        $appendLog('Migración finalizada');
        if ($abortedByError) {
            mOut(['ok' => false, 'error' => $abortMessage, 'summary' => $summary, 'logs' => $logs]);
        }
        mOut(['ok' => true, 'summary' => $summary, 'logs' => $logs]);
    }

    mOut(['ok' => false, 'error' => 'Acción no válida'], 400);
} catch (Throwable $e) {
    error_log('[db_migrador] ' . $action . ': ' . $e->getMessage());
    mOut(['ok' => false, 'error' => $e->getMessage()], 200);
}
