<?php

/**
 * SistemaX Multi-Empresa - Database Configuration
 * Arquitectura: 1 Master DB (serproc1) + 1 DB por Empresa
 */

if (!defined('SISTEMAX_V1')) {
    die('Acceso no autorizado');
}

class Database
{
    // Configuración Master DB (cargada desde variables de entorno)
    private static $masterConfig = null;

    private static $masterConnection = null;
    private static $empresaConnections = [];

    private static function envEnabled(string $key): bool
    {
        $raw = strtolower(trim((string)(getenv($key) ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function getEnvironment(): string
    {
        $envOverride = strtolower(trim((string)(getenv('SISTEMAX_ENV') ?: '')));
        if (in_array($envOverride, ['dev', 'development'], true)) {
            return 'dev';
        } elseif (in_array($envOverride, ['prod', 'production'], true)) {
            return 'prod';
        } else {
            $projectRoot = defined('SISTEMAX_PROJECT_ROOT') ? SISTEMAX_PROJECT_ROOT : __DIR__ . '/..';
            return preg_match('~(?:^|[\\\\/])(desarrollo|sistemaxpro-dev)(?:[\\\\/]|$)~i', $projectRoot) === 1 ? 'dev' : 'prod';
        }
    }

    private static function getDefaultHost(): string
    {
        return self::getEnvironment() === 'dev' ? '127.0.0.1' : 'localhost';
    }

    private static function getDefaultPort(): int
    {
        return self::getEnvironment() === 'dev' ? 3307 : 3306;
    }

    private static function getDefaultUser(): string
    {
        return self::getEnvironment() === 'dev' ? 'sistemax' : 'sistemax';
    }

    private static function getDefaultPassword(): string
    {
        return self::getEnvironment() === 'dev' ? 'dev_local_2024' : 'Armagedon123';
    }

    private static function shouldLogResolvedDsn(): bool
    {
        return self::getEnvironment() === 'dev' || self::envEnabled('SISTEMAX_DB_LOG_DSN');
    }

    private static function getMasterConfig(): array
    {
        if (is_array(self::$masterConfig)) {
            return self::$masterConfig;
        }

        $strictEnv = self::envEnabled('SISTEMAX_MASTER_DB_STRICT_ENV');

        // Compatibilidad por defecto: mantiene credenciales historicas.
        // Modo estricto opcional: obliga a definir variables de entorno.
        $defaultDatabase = 'serproc1';

        $host = (string)(getenv('SISTEMAX_MASTER_DB_HOST') ?: ($strictEnv ? '' : self::getDefaultHost()));
        $port = (int)(getenv('SISTEMAX_MASTER_DB_PORT') ?: ($strictEnv ? 0 : self::getDefaultPort()));
        $database = (string)(getenv('SISTEMAX_MASTER_DB_NAME') ?: $defaultDatabase);
        $username = (string)(getenv('SISTEMAX_MASTER_DB_USER') ?: ($strictEnv ? '' : self::getDefaultUser()));
        $password = (string)(getenv('SISTEMAX_MASTER_DB_PASS') ?: ($strictEnv ? '' : self::getDefaultPassword()));
        $charset = (string)(getenv('SISTEMAX_MASTER_DB_CHARSET') ?: 'utf8mb4');

        if ($strictEnv && ($host === '' || $port <= 0 || $database === '' || $username === '' || $password === '')) {
            throw new RuntimeException(
                'Configuracion DB incompleta: defina SISTEMAX_MASTER_DB_HOST, SISTEMAX_MASTER_DB_PORT, ' .
                'SISTEMAX_MASTER_DB_USER y SISTEMAX_MASTER_DB_PASS'
            );
        }

        self::$masterConfig = [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
        ];

        return self::$masterConfig;
    }

    /**
     * Conexión a Master DB (serproc1)
     * Tablas: empresa, sec_users, sec_groups, sec_groups_apps, habilitacion_sifen
     */
    public static function getMasterConnection(): PDO
    {
        if (self::$masterConnection === null) {
            try {
                $cfg = self::getMasterConfig();
                $dsn = sprintf(
                    "mysql:host=%s;port=%d;dbname=%s;charset=%s",
                    $cfg['host'],
                    $cfg['port'],
                    $cfg['database'],
                    $cfg['charset']
                );

                self::$masterConnection = new PDO(
                    $dsn,
                    $cfg['username'],
                    $cfg['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                        PDO::ATTR_PERSISTENT => false
                    ]
                );
                if (self::shouldLogResolvedDsn()) {
                    error_log(sprintf(
                        '[DB] master env=%s host=%s port=%d db=%s user=%s',
                        self::getEnvironment(),
                        $cfg['host'],
                        $cfg['port'],
                        $cfg['database'],
                        $cfg['username']
                    ));
                }
            } catch (Throwable $e) {
                error_log("[ERROR] Error Master DB: " . $e->getMessage());
                throw new Exception("Error de conexión a base de datos maestra", 0, $e);
            }
        }

        return self::$masterConnection;
    }

    /**
     * Conexión a DB de Empresa específica
     * Lee configuración desde serproc1.empresa
     */
    public static function getEmpresaConnection(int $idEmpresa): PDO
    {
        if (isset(self::$empresaConnections[$idEmpresa])) {
            return self::$empresaConnections[$idEmpresa];
        }

        try {
            $master = self::getMasterConnection();

            try {
                $stmt = $master->prepare("
                    SELECT dbase, server, user, password, puerto
                    FROM empresa
                    WHERE id_empresa = ? AND activo = 1
                ");
                $stmt->execute([$idEmpresa]);
                $empresa = $stmt->fetch();
            } catch (PDOException $e) {
                // Compatibilidad con esquemas legacy donde no existe la columna `puerto`.
                if (($e->errorInfo[1] ?? null) !== 1054) {
                    throw $e;
                }
                $stmt = $master->prepare("
                    SELECT dbase, server, user, password
                    FROM empresa
                    WHERE id_empresa = ? AND activo = 1
                ");
                $stmt->execute([$idEmpresa]);
                $empresa = $stmt->fetch();
            }

            if (!$empresa) {
                throw new Exception("Empresa ID {$idEmpresa} no encontrada o inactiva");
            }

            $masterCfg = self::getMasterConfig();
            $env = self::getEnvironment();
            // Usar el valor 'dbase' real desde la tabla empresa (puede ser tienda_X, flotamax_X, etc.)
            $dbName = trim($empresa['dbase'] ?? ('empresa_' . $idEmpresa));

            // En dev, reutilizar host/port del master (respeta SISTEMAX_MASTER_DB_PORT).
            // En prod, forzar host centralizado y respetar puerto por empresa.
            $resolvedHost = $env === 'dev' ? $masterCfg['host'] : $masterCfg['host'];
            $resolvedPort = $env === 'dev'
                ? $masterCfg['port']
                : (isset($empresa['puerto']) && (int)$empresa['puerto'] > 0 ? (int)$empresa['puerto'] : self::getDefaultPort());
            $resolvedUser = $env === 'dev' ? $masterCfg['username'] : ($empresa['user'] ?: $masterCfg['username']);
            $resolvedPass = $env === 'dev' ? $masterCfg['password'] : ($empresa['password'] ?: $masterCfg['password']);

            $dsn = sprintf(
                "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
                $resolvedHost,
                $resolvedPort,
                $dbName
            );

            self::$empresaConnections[$idEmpresa] = new PDO(
                $dsn,
                $resolvedUser,
                $resolvedPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]
            );
            if (self::shouldLogResolvedDsn()) {
                error_log(sprintf(
                    '[DB] empresa env=%s id_empresa=%d host=%s port=%d db=%s user=%s',
                    self::getEnvironment(),
                    $idEmpresa,
                    $resolvedHost,
                    $resolvedPort,
                    $dbName,
                    $resolvedUser
                ));
            }

            return self::$empresaConnections[$idEmpresa];
        } catch (PDOException $e) {
            error_log("[ERROR] Error DB Empresa {$idEmpresa}: " . $e->getMessage());
            throw new Exception("Error de conexión a base de datos de empresa", 0, $e);
        }
    }

    /**
     * Conexión a DB de empresa desde sesión activa
     */
    public static function getSessionEmpresaConnection(): PDO
    {
        Session::start();
        $idEmpresa = Session::getIdEmpresa();

        if (!$idEmpresa) {
            throw new Exception("No hay empresa seleccionada en sesión");
        }

        return self::getEmpresaConnection($idEmpresa);
    }

    /**
     * Obtener información de empresa desde Master DB
     */
    public static function getEmpresaInfo(int $idEmpresa): ?array
    {
        $master = self::getMasterConnection();
        try {
            $stmt = $master->prepare("
                SELECT id_empresa, empresa, empresa AS empresa_nombre, ruc, dv, dbase, server, `user`, `password`, puerto, `database`, software,
                       moneda_principal, establecimiento, punto_expedicion, active, activo
                FROM empresa 
                WHERE id_empresa = ?
            ");
            $stmt->execute([$idEmpresa]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1054) {
                throw $e;
            }
            $stmt = $master->prepare("
                SELECT id_empresa, empresa, empresa AS empresa_nombre, ruc, dv, dbase, server, `user`, `password`, `database`, software,
                       moneda_principal, establecimiento, punto_expedicion, active, activo
                FROM empresa
                WHERE id_empresa = ?
            ");
            $stmt->execute([$idEmpresa]);
            return $stmt->fetch() ?: null;
        }
    }

    /**
     * Nombre de la Master DB.
     */
    public static function getMasterDbName(): string
    {
        return self::getMasterConfig()['database'];
    }

    /**
     * Cerrar todas las conexiones
     */
    public static function closeAll(): void
    {
        self::$masterConnection = null;
        self::$empresaConnections = [];
    }
}
