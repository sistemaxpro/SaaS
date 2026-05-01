<?php

/**
 * Security - CSRF, Rate Limiting, Logging de Accesos
 */

if (!defined('SISTEMAX_V1')) {
    die('Acceso no autorizado');
}

class Security
{
    // Configuración de rate limiting
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const RATE_LIMIT_WINDOW = 60; // segundos

    // Tabla de logs
    private const LOG_TABLE = 'sec_access_logs';
    private const ATTEMPTS_TABLE = 'sec_login_attempts';

    /**
     * ============================================
     * CSRF TOKEN MANAGEMENT
     * ============================================
     */

    /**
     * Generar token CSRF
     */
    public static function generateCSRFToken(): string
    {
        Session::start();

        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        // Regenerar token si tiene más de 1 hora
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validar token CSRF
     */
    public static function validateCSRFToken(?string $token): bool
    {
        Session::start();

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Obtener input hidden con CSRF token
     */
    public static function csrfField(): string
    {
        $token = self::generateCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Obtener meta tag con CSRF token (para AJAX)
     */
    public static function csrfMeta(): string
    {
        $token = self::generateCSRFToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }

    /**
     * ============================================
     * RATE LIMITING / BLOQUEO POR INTENTOS
     * ============================================
     */

    /**
     * Verificar si IP está bloqueada
     */
    public static function isBlocked(string $ip, string $username = ''): bool
    {
        try {
            self::ensureTablesExist();

            $db = Database::getMasterConnection();

            // Verificar bloqueo por IP
            $stmt = $db->prepare("
                SELECT COUNT(*) as attempts, 
                       MAX(created_at) as last_attempt
                FROM " . self::ATTEMPTS_TABLE . "
                WHERE ip_address = ? 
                  AND success = 0
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$ip, self::LOCKOUT_MINUTES]);
            $result = $stmt->fetch();

            if ($result && $result['attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
                return true;
            }

            // Verificar bloqueo por usuario específico
            if (!empty($username)) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) as attempts
                    FROM " . self::ATTEMPTS_TABLE . "
                    WHERE username = ? 
                      AND success = 0
                      AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                ");
                $stmt->execute([$username, self::LOCKOUT_MINUTES]);
                $result = $stmt->fetch();

                if ($result && $result['attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            error_log("[Security] Error checking block status: " . $e->getMessage());
            return false; // En caso de error, permitir (fail-open)
        }
    }

    /**
     * Obtener tiempo restante de bloqueo en minutos
     */
    public static function getRemainingLockoutTime(string $ip, string $username = ''): int
    {
        try {
            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                SELECT TIMESTAMPDIFF(MINUTE, MAX(created_at), NOW()) as minutes_ago
                FROM " . self::ATTEMPTS_TABLE . "
                WHERE (ip_address = ? OR username = ?)
                  AND success = 0
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                HAVING COUNT(*) >= ?
            ");
            $stmt->execute([$ip, $username, self::LOCKOUT_MINUTES, self::MAX_LOGIN_ATTEMPTS]);
            $result = $stmt->fetch();

            if ($result) {
                return max(0, self::LOCKOUT_MINUTES - (int)$result['minutes_ago']);
            }

            return 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Registrar intento de login
     */
    public static function logLoginAttempt(string $username, bool $success, string $ip, string $userAgent = ''): void
    {
        try {
            self::ensureTablesExist();

            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                INSERT INTO " . self::ATTEMPTS_TABLE . " 
                (username, ip_address, user_agent, success, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$username, $ip, $userAgent, $success ? 1 : 0]);

            // Limpiar intentos antiguos (más de 24 horas)
            $db->exec("DELETE FROM " . self::ATTEMPTS_TABLE . " WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        } catch (Exception $e) {
            error_log("[Security] Error logging attempt: " . $e->getMessage());
        }
    }

    /**
     * Obtener intentos restantes
     */
    public static function getRemainingAttempts(string $ip, string $username = ''): int
    {
        try {
            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                SELECT COUNT(*) as attempts
                FROM " . self::ATTEMPTS_TABLE . "
                WHERE (ip_address = ? OR username = ?)
                  AND success = 0
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$ip, $username, self::LOCKOUT_MINUTES]);
            $result = $stmt->fetch();

            $attempts = $result ? (int)$result['attempts'] : 0;
            return max(0, self::MAX_LOGIN_ATTEMPTS - $attempts);
        } catch (Exception $e) {
            return self::MAX_LOGIN_ATTEMPTS;
        }
    }

    /**
     * Limpiar intentos después de login exitoso
     */
    public static function clearLoginAttempts(string $ip, string $username): void
    {
        try {
            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                UPDATE " . self::ATTEMPTS_TABLE . " 
                SET success = 1 
                WHERE (ip_address = ? OR username = ?)
                  AND success = 0
            ");
            $stmt->execute([$ip, $username]);
        } catch (Exception $e) {
            error_log("[Security] Error clearing attempts: " . $e->getMessage());
        }
    }

    /**
     * ============================================
     * ACCESS LOGGING
     * ============================================
     */

    /**
     * Registrar acceso al sistema
     */
    public static function logAccess(
        int $userId,
        string $action,
        string $resource = '',
        string $details = '',
        string $ip = '',
        string $userAgent = ''
    ): void {
        try {
            self::ensureTablesExist();

            $db = Database::getMasterConnection();

            $ip = $ip ?: self::getClientIP();
            $userAgent = $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');

            $stmt = $db->prepare("
                INSERT INTO " . self::LOG_TABLE . " 
                (user_id, action, resource, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $action, $resource, $details, $ip, $userAgent]);
        } catch (Exception $e) {
            error_log("[Security] Error logging access: " . $e->getMessage());
        }
    }

    /**
     * Registrar login exitoso
     */
    public static function logLogin(int $userId, string $username): void
    {
        self::logAccess($userId, 'LOGIN', 'auth', "Usuario: $username");
    }

    /**
     * Registrar logout
     */
    public static function logLogout(int $userId, string $username): void
    {
        self::logAccess($userId, 'LOGOUT', 'auth', "Usuario: $username");
    }

    /**
     * Obtener historial de accesos
     */
    public static function getAccessLogs(int $userId = 0, int $limit = 50, int $offset = 0): array
    {
        try {
            $db = Database::getMasterConnection();

            $sql = "SELECT l.*, u.login as username, u.name as user_name
                    FROM " . self::LOG_TABLE . " l
                    LEFT JOIN sec_users u ON l.user_id = u.id_login";

            $params = [];

            if ($userId > 0) {
                $sql .= " WHERE l.user_id = ?";
                $params[] = $userId;
            }

            $sql .= " ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("[Security] Error getting logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * ============================================
     * REMEMBER ME TOKENS
     * ============================================
     */

    private const REMEMBER_TABLE = 'sec_remember_tokens';
    private const REMEMBER_EXPIRY_DAYS = 30;

    /**
     * Generar token "Recordarme"
     */
    public static function createRememberToken(int $userId): string
    {
        try {
            self::ensureTablesExist();

            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $hashedValidator = hash('sha256', $validator);
            $expires = date('Y-m-d H:i:s', strtotime('+' . self::REMEMBER_EXPIRY_DAYS . ' days'));

            $db = Database::getMasterConnection();

            // Eliminar tokens anteriores del usuario
            $stmt = $db->prepare("DELETE FROM " . self::REMEMBER_TABLE . " WHERE user_id = ?");
            $stmt->execute([$userId]);

            // Insertar nuevo token
            $stmt = $db->prepare("
                INSERT INTO " . self::REMEMBER_TABLE . " 
                (user_id, selector, hashed_validator, expires_at, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $selector, $hashedValidator, $expires]);

            return $selector . ':' . $validator;
        } catch (Exception $e) {
            error_log("[Security] Error creating remember token: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Validar token "Recordarme"
     */
    public static function validateRememberToken(string $token): ?int
    {
        try {
            if (strpos($token, ':') === false) {
                return null;
            }

            [$selector, $validator] = explode(':', $token, 2);

            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                SELECT * FROM " . self::REMEMBER_TABLE . "
                WHERE selector = ? AND expires_at > NOW()
            ");
            $stmt->execute([$selector]);
            $record = $stmt->fetch();

            if (!$record) {
                return null;
            }

            $hashedValidator = hash('sha256', $validator);

            if (hash_equals($record['hashed_validator'], $hashedValidator)) {
                return (int)$record['user_id'];
            }

            return null;
        } catch (Exception $e) {
            error_log("[Security] Error validating remember token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Eliminar token "Recordarme"
     */
    public static function deleteRememberToken(int $userId): void
    {
        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("DELETE FROM " . self::REMEMBER_TABLE . " WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("[Security] Error deleting remember token: " . $e->getMessage());
        }
    }

    /**
     * ============================================
     * PASSWORD RESET TOKENS
     * ============================================
     */

    private const RESET_TABLE = 'sec_password_resets';
    private const RESET_EXPIRY_HOURS = 2;

    /**
     * Crear token de reset de contraseña
     */
    public static function createPasswordResetToken(int $userId, string $email): string
    {
        try {
            self::ensureTablesExist();

            $token = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', strtotime('+' . self::RESET_EXPIRY_HOURS . ' hours'));

            $db = Database::getMasterConnection();

            // Eliminar tokens anteriores del usuario
            $stmt = $db->prepare("DELETE FROM " . self::RESET_TABLE . " WHERE user_id = ?");
            $stmt->execute([$userId]);

            // Insertar nuevo token
            $stmt = $db->prepare("
                INSERT INTO " . self::RESET_TABLE . " 
                (user_id, email, token, expires_at, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $email, $hashedToken, $expires]);

            return $token;
        } catch (Exception $e) {
            error_log("[Security] Error creating reset token: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Validar token de reset
     */
    public static function validatePasswordResetToken(string $token): ?array
    {
        try {
            $hashedToken = hash('sha256', $token);

            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                SELECT r.*, u.login, u.email as user_email
                FROM " . self::RESET_TABLE . " r
                INNER JOIN sec_users u ON r.user_id = u.id_login
                WHERE r.token = ? AND r.expires_at > NOW() AND r.used = 0
            ");
            $stmt->execute([$hashedToken]);

            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            error_log("[Security] Error validating reset token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Marcar token como usado
     */
    public static function markResetTokenUsed(string $token): void
    {
        try {
            $hashedToken = hash('sha256', $token);

            $db = Database::getMasterConnection();
            $stmt = $db->prepare("UPDATE " . self::RESET_TABLE . " SET used = 1 WHERE token = ?");
            $stmt->execute([$hashedToken]);
        } catch (Exception $e) {
            error_log("[Security] Error marking token used: " . $e->getMessage());
        }
    }

    /**
     * ============================================
     * UTILIDADES
     * ============================================
     */

    /**
     * Obtener IP del cliente
     */
    public static function getClientIP(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Si hay múltiples IPs (proxy), tomar la primera
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Asegurar que las tablas de seguridad existen
     */
    private static function ensureTablesExist(): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        try {
            $db = Database::getMasterConnection();

            // Tabla de intentos de login
            $db->exec("
                CREATE TABLE IF NOT EXISTS " . self::ATTEMPTS_TABLE . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100) NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent TEXT,
                    success TINYINT(1) DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    INDEX idx_ip (ip_address),
                    INDEX idx_username (username),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Tabla de logs de acceso
            $db->exec("
                CREATE TABLE IF NOT EXISTS " . self::LOG_TABLE . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    resource VARCHAR(100),
                    details TEXT,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at DATETIME NOT NULL,
                    INDEX idx_user (user_id),
                    INDEX idx_action (action),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Tabla de tokens "Recordarme"
            $db->exec("
                CREATE TABLE IF NOT EXISTS " . self::REMEMBER_TABLE . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    selector VARCHAR(32) NOT NULL UNIQUE,
                    hashed_validator VARCHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_user (user_id),
                    INDEX idx_selector (selector),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Tabla de reset de contraseña
            $db->exec("
                CREATE TABLE IF NOT EXISTS " . self::RESET_TABLE . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    used TINYINT(1) DEFAULT 0,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_user (user_id),
                    INDEX idx_token (token),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $checked = true;
        } catch (Exception $e) {
            error_log("[Security] Error creating tables: " . $e->getMessage());
        }
    }

    /**
     * Limpiar datos antiguos (ejecutar periódicamente)
     */
    public static function cleanup(): void
    {
        try {
            $db = Database::getMasterConnection();

            // Limpiar intentos de login antiguos (más de 24 horas)
            $db->exec("DELETE FROM " . self::ATTEMPTS_TABLE . " WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

            // Limpiar tokens recordarme expirados
            $db->exec("DELETE FROM " . self::REMEMBER_TABLE . " WHERE expires_at < NOW()");

            // Limpiar tokens de reset expirados
            $db->exec("DELETE FROM " . self::RESET_TABLE . " WHERE expires_at < NOW()");

            // Limpiar logs antiguos (más de 90 días)
            $db->exec("DELETE FROM " . self::LOG_TABLE . " WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        } catch (Exception $e) {
            error_log("[Security] Error in cleanup: " . $e->getMessage());
        }
    }
}
