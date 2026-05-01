<?php

/**
 * Gestión de Sesión Multi-Empresa
 */

if (!defined('SISTEMAX_V1')) {
    die('Acceso no autorizado');
}

class Session
{
    private static function getDefaultHost(): string
    {
        $envOverride = strtolower(trim((string)(getenv('SISTEMAX_ENV') ?: '')));
        if (in_array($envOverride, ['dev', 'development'], true)) {
            return '127.0.0.1';
        } elseif (in_array($envOverride, ['prod', 'production'], true)) {
            return 'localhost';
        } else {
            $projectRoot = defined('SISTEMAX_PROJECT_ROOT') ? SISTEMAX_PROJECT_ROOT : __DIR__ . '/..';
            return preg_match('~(?:^|[\\\\/])(desarrollo|sistemaxpro-dev)(?:[\\\\/]|$)~i', $projectRoot) === 1 ? '127.0.0.1' : 'localhost';
        }
    }

    private static function getDefaultPort(): int
    {
        $envOverride = strtolower(trim((string)(getenv('SISTEMAX_ENV') ?: '')));
        if (in_array($envOverride, ['dev', 'development'], true)) {
            return 3307;
        } elseif (in_array($envOverride, ['prod', 'production'], true)) {
            return 3306;
        } else {
            $projectRoot = defined('SISTEMAX_PROJECT_ROOT') ? SISTEMAX_PROJECT_ROOT : __DIR__ . '/..';
            return preg_match('~(?:^|[\\\\/])(desarrollo|sistemaxpro-dev)(?:[\\\\/]|$)~i', $projectRoot) === 1 ? 3307 : 3306;
        }
    }

    /**
     * Iniciar sesión si no está activa
     * Por defecto la sesión expira al cerrar el navegador (lifetime = 0)
     */
    public static function start(int $lifetime = 0): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Configurar cookie de sesión
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
        // Normalizar bandera admin legacy para todo el sistema (S -> Y)
        if (isset($_SESSION['usr_priv_admin'])) {
            $priv = strtoupper((string)$_SESSION['usr_priv_admin']);
            $_SESSION['usr_priv_admin'] = in_array($priv, ['Y', 'S', '1', 'TRUE'], true) ? 'Y' : 'N';
        }
    }
    
    /**
     * Iniciar sesión con duración extendida (para "Recordarme")
     */
    public static function startPersistent(): void
    {
        // 30 días
        self::start(86400 * 30);
    }

    /**
     * Establecer datos de empresa en sesión
     */
    public static function setEmpresa(array $empresa): void
    {
        self::start();
        $isDev = self::getDefaultPort() === 3307;
        $idEmpresa = (int)($empresa['id_empresa'] ?? 0);
        $dbName = $idEmpresa > 0 ? 'empresa_' . $idEmpresa : trim((string)($empresa['dbase'] ?? ''));
        $companyName = trim((string)($empresa['empresa'] ?? $empresa['empresa_nombre'] ?? ''));
        $dbUser = trim((string)($empresa['user'] ?? ''));
        $dbPass = (string)($empresa['password'] ?? '');
        $dbDatabase = trim((string)($empresa['database'] ?? ''));
        $dbPort = self::getDefaultPort();

        $_SESSION['id_empresa'] = $idEmpresa;
        $_SESSION['dbu'] = $dbName;
        $_SESSION['dbase'] = $dbName;
        $_SESSION['master_db'] = defined('MASTER_DB') ? MASTER_DB : 'serproc1';
        $_SESSION['db_master'] = $_SESSION['master_db'];
        $_SESSION['empresa'] = $companyName;
        $_SESSION['empresa_nombre'] = $companyName;
        $_SESSION['ruc'] = trim((string)($empresa['ruc'] ?? ''));
        $_SESSION['dv'] = trim((string)($empresa['dv'] ?? ''));
        $_SESSION['server'] = self::getDefaultHost();
        $_SESSION['user'] = $isDev ? 'sistemax' : ($dbUser !== '' ? $dbUser : ($_SESSION['user'] ?? ''));
        $_SESSION['password'] = $isDev ? 'dev_local_2024' : ($dbPass !== '' ? $dbPass : ($_SESSION['password'] ?? ''));
        $_SESSION['database'] = $dbDatabase !== '' ? $dbDatabase : $dbName;
        $_SESSION['puerto'] = $dbPort > 0 ? $dbPort : (int)($_SESSION['puerto'] ?? self::getDefaultPort());
        $_SESSION['moneda_principal'] = (int)($empresa['moneda_principal'] ?? ($_SESSION['moneda_principal'] ?? 1));
        $_SESSION['establecimiento'] = trim((string)($empresa['establecimiento'] ?? ''));
        $_SESSION['punto_expedicion'] = trim((string)($empresa['punto_expedicion'] ?? ''));
        $_SESSION['active'] = $empresa['active'] ?? ($empresa['activo'] ?? 'Y');
        $_SESSION['activo'] = $empresa['activo'] ?? ($empresa['active'] ?? 'Y');
    }

    /**
     * Establecer usuario logueado
     */
    public static function setUser(array $user): void
    {
        self::start();
        $priv = strtoupper((string)($user['priv_admin'] ?? 'N'));
        $resolvedGroupId = null;
        $resolvedGroupName = $user['group_name'] ?? 'Sin Rol';

        $login = (string)($user['login'] ?? '');
        $idEmpresa = (int)($user['id_empresa'] ?? ($user['id_grupo'] ?? 0));
        if (!empty($user['group_id'])) {
            $resolvedGroupId = (int)$user['group_id'];
        } elseif ($login !== '' && $idEmpresa > 0) {
            try {
                $db = Database::getMasterConnection();
                $stmt = $db->prepare("
                    SELECT ug.group_id, sg.description
                    FROM sec_users_groups ug
                    LEFT JOIN sec_groups sg
                      ON sg.id_grupo = ug.id_grupo
                     AND sg.group_id = ug.group_id
                    WHERE ug.login = ? AND ug.id_grupo = ?
                    ORDER BY CASE WHEN ug.group_id = 1 THEN 0 ELSE 1 END, ug.group_id ASC
                    LIMIT 1
                ");
                $stmt->execute([$login, $idEmpresa]);
                $groupRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($groupRow) {
                    $resolvedGroupId = (int)($groupRow['group_id'] ?? 0);
                    $resolvedGroupName = (string)($groupRow['description'] ?? $resolvedGroupName);
                }
            } catch (Throwable $e) {
                error_log('[Session] setUser group resolve error: ' . $e->getMessage());
            }
        }

        if (($resolvedGroupId ?? 0) <= 0 && in_array($priv, ['Y', 'S', '1', 'TRUE'], true)) {
            $resolvedGroupId = 1;
            $resolvedGroupName = 'ADMINISTRADOR';
        }

        $idSucursal = (int)($user['id_sucursal'] ?? 0);
        $idCajaDef = (int)($user['id_caja'] ?? ($user['caja_def'] ?? 0));
        $sucursalName = '';
        $cajaName = '';

        if ($idSucursal > 0 || $idCajaDef > 0) {
            try {
                $empresaDb = null;
                if (class_exists('Database')) {
                    try {
                        $empresaDb = Database::getSessionEmpresaConnection();
                    } catch (Throwable $e) {
                        $empresaDb = null;
                    }
                }

                if ($empresaDb instanceof PDO) {
                    if ($idSucursal > 0) {
                        $candidates = [
                            ['sucursales', 'id_sucursal', ['nombre', 'sucursal', 'NOMBRE']],
                            ['sucursal', 'id_sucursal', ['NOMBRE', 'sucursal', 'nombre']],
                        ];
                        foreach ($candidates as [$table, $idCol, $nameCols]) {
                            try {
                                $stmt = $empresaDb->prepare("SELECT * FROM {$table} WHERE {$idCol} = ? LIMIT 1");
                                $stmt->execute([$idSucursal]);
                                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($row) {
                                    foreach ($nameCols as $nameCol) {
                                        if (!array_key_exists($nameCol, $row)) continue;
                                        $value = trim((string)($row[$nameCol] ?? ''));
                                        if ($value !== '') {
                                            $sucursalName = $value;
                                            break 2;
                                        }
                                    }
                                    if ($sucursalName === '') {
                                        $sucursalName = 'Sucursal #' . $idSucursal;
                                        break;
                                    }
                                }
                            } catch (Throwable $e) {
                                // Continuar con el siguiente candidato.
                            }
                        }
                    }

                    if ($idCajaDef > 0) {
                        try {
                            $stmtCaja = $empresaDb->prepare("
                                SELECT *
                                FROM cajas
                                WHERE id_caja = ?
                                LIMIT 1
                            ");
                            $stmtCaja->execute([$idCajaDef]);
                            $rowCaja = $stmtCaja->fetch(PDO::FETCH_ASSOC);
                            if ($rowCaja) {
                                foreach (['caja', 'nombre'] as $col) {
                                    $value = trim((string)($rowCaja[$col] ?? ''));
                                    if ($value !== '') {
                                        $cajaName = $value;
                                        break;
                                    }
                                }
                            }
                        } catch (Throwable $e) {
                            // Ignorar y usar fallback.
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignorar y usar valores de respaldo.
            }
        }

        $_SESSION['id_login'] = $user['id_login'];
        $_SESSION['login'] = $user['login'];
        $_SESSION['usuario'] = $user['login'];
        $_SESSION['usr_priv_admin'] = in_array($priv, ['Y', 'S', '1', 'TRUE'], true) ? 'Y' : 'N';
        $_SESSION['group_id'] = $resolvedGroupId;
        $_SESSION['id_group'] = $resolvedGroupId;
        $_SESSION['id_grupo'] = $resolvedGroupId;
        $_SESSION['group_name'] = $resolvedGroupName;
        $_SESSION['user_name'] = $user['name'] ?? $user['login'];
        $_SESSION['user_email'] = $user['email'] ?? null;
        $_SESSION['id_empresa'] = (int)($user['id_empresa'] ?? ($_SESSION['id_empresa'] ?? 0));
        $_SESSION['id_sucursal'] = $idSucursal > 0 ? $idSucursal : (int)($_SESSION['id_sucursal'] ?? 0);
        $_SESSION['sucursal'] = $sucursalName !== '' ? $sucursalName : (string)($_SESSION['sucursal'] ?? '');
        $_SESSION['id_caja_def'] = $idCajaDef > 0 ? $idCajaDef : (int)($_SESSION['id_caja_def'] ?? 0);
        $_SESSION['caja_def'] = $_SESSION['id_caja_def'];
        $_SESSION['caja'] = $cajaName !== '' ? $cajaName : (string)($_SESSION['caja'] ?? '');
        $_SESSION['id_caja'] = $_SESSION['id_caja_def'];
    }

    /**
     * Obtener ID de empresa actual
     */
    public static function getIdEmpresa(): ?int
    {
        self::start();
        return $_SESSION['id_empresa'] ?? null;
    }

    /**
     * Obtener ID de usuario logueado
     */
    public static function getIdLogin(): ?int
    {
        self::start();
        return $_SESSION['id_login'] ?? null;
    }

    /**
     * Obtener nombre de base de datos de empresa actual
     */
    public static function getDbase(): ?string
    {
        self::start();
        $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
        if ($idEmpresa > 0) {
            return 'empresa_' . $idEmpresa;
        }

        return $_SESSION['dbu'] ?? null;
    }

    /**
     * Verificar si hay sesión activa
     */
    public static function isLoggedIn(): bool
    {
        self::start();
        return isset($_SESSION['id_login']) && isset($_SESSION['id_empresa']);
    }

    /**
     * Requerir login (redirigir si no está logueado)
     */
    public static function requireLogin(string $redirectUrl = '/public/login.php'): void
    {
        if (!self::isLoggedIn()) {
            // Capturar la URL actual para redirigir después del login
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
            $separator = strpos($redirectUrl, '?') !== false ? '&' : '?';
            $loginUrl = $redirectUrl . $separator . 'redirect=' . urlencode($currentUrl);
            header('Location: ' . $loginUrl);
            exit;
        }
    }

    /**
     * Verificar si es administrador
     */
    public static function isAdmin(): bool
    {
        self::start();
        return ($_SESSION['usr_priv_admin'] ?? 'N') === 'Y';
    }

    /**
     * Destruir sesión
     */
    public static function destroy(): void
    {
        self::start();
        session_unset();
        session_destroy();
    }

    /**
     * Obtener todos los datos de sesión
     */
    public static function getAll(): array
    {
        self::start();
        return $_SESSION;
    }

    /**
     * Establecer variable de sesión
     */
    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Obtener variable de sesión
     */
    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Verificar y validar inactividad de sesión (20 minutos por defecto)
     * Destruye la sesión si el tiempo de inactividad se excede
     *
     * @param int $timeoutMinutes Minutos de inactividad permitidos (por defecto 20)
     * @return bool true si la sesión está activa, false si fue destruida por inactividad
     */
    public static function checkInactivity(int $timeoutMinutes = 20): bool
    {
        self::start();

        // Si no hay sesión activa, no hay nada que validar
        if (!self::isLoggedIn()) {
            return true;
        }

        $timeoutSeconds = $timeoutMinutes * 60;
        $currentTime = time();

        // Inicializar último tiempo de actividad si no existe
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = $currentTime;
            return true;
        }

        $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
        $timeSinceLastActivity = $currentTime - $lastActivity;

        // Verificar si se excedió el tiempo de inactividad
        if ($timeSinceLastActivity > $timeoutSeconds) {
            // Registrar la razón del cierre de sesión
            $_SESSION['session_closed_reason'] = 'inactivity_timeout';
            $_SESSION['session_closed_time'] = $currentTime;

            // Destruir la sesión
            self::destroy();

            return false;
        }

        // Actualizar el último tiempo de actividad
        $_SESSION['last_activity'] = $currentTime;
        return true;
    }

    /**
     * Actualizar manualmente el tiempo de última actividad
     * Útil para operaciones que toman mucho tiempo
     */
    public static function updateActivityTime(): void
    {
        self::start();
        $_SESSION['last_activity'] = time();
    }

    /**
     * Obtener el tiempo restante antes de timeout (en segundos)
     */
    public static function getTimeRemainingBeforeTimeout(int $timeoutMinutes = 20): ?int
    {
        self::start();

        if (!self::isLoggedIn() || !isset($_SESSION['last_activity'])) {
            return null;
        }

        $timeoutSeconds = $timeoutMinutes * 60;
        $lastActivity = (int)$_SESSION['last_activity'];
        $currentTime = time();
        $timeSinceLastActivity = $currentTime - $lastActivity;
        $timeRemaining = $timeoutSeconds - $timeSinceLastActivity;

        return max(0, $timeRemaining);
    }
}
