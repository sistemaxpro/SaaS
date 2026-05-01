<?php

/**
 * Sistema de Autenticación Multi-Empresa
 * Con soporte para: Remember Me, Rate Limiting, Logging
 */

class Auth
{
    // Nombre de la cookie "Recordarme"
    private const REMEMBER_COOKIE = 'sistemax_remember';
    private const REMEMBER_DAYS = 30;

    /**
     * Autenticar usuario
     * @param bool $remember Guardar sesión con cookie
     */
    public static function login(string $usuario, string $password, bool $remember = false): array
    {
        $ip = Security::getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        try {
            // Verificar si está bloqueado por intentos fallidos
            if (Security::isBlocked($ip, $usuario)) {
                $remaining = Security::getRemainingLockoutTime($ip, $usuario);
                return [
                    'success' => false,
                    'error' => "Cuenta bloqueada. Intente nuevamente en {$remaining} minutos.",
                    'blocked' => true,
                    'remaining_minutes' => $remaining
                ];
            }

            $db = Database::getMasterConnection();

            // Buscar usuario con su rol
            $stmt = $db->prepare("
                SELECT u.*, 
                       CASE 
                           WHEN u.priv_admin = 'Y' THEN 'ADMINISTRADOR'
                           ELSE COALESCE(g.description, 'Usuario')
                       END as group_name
                FROM sec_users u
                LEFT JOIN sec_groups g ON u.id_grupo = g.id_grupo AND g.group_id = 1
                WHERE u.login = ? AND u.active = 'Y'
            ");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();

            if (!$user) {
                // Registrar intento fallido
                Security::logLoginAttempt($usuario, false, $ip, $userAgent);
                $remaining = Security::getRemainingAttempts($ip, $usuario);

                return [
                    'success' => false,
                    'error' => 'Usuario no encontrado',
                    'remaining_attempts' => $remaining
                ];
            }

            // Verificar contraseña (MD5 legacy del sistema ScriptCase)
            $passwordMatch = $user['pswd'] === md5($password)
                || password_verify($password, $user['pswd'])
                || $user['pswd'] === $password; // Fallback para texto plano

            if (!$passwordMatch) {
                // Registrar intento fallido
                Security::logLoginAttempt($usuario, false, $ip, $userAgent);
                $remaining = Security::getRemainingAttempts($ip, $usuario);

                return [
                    'success' => false,
                    'error' => 'Contraseña incorrecta',
                    'remaining_attempts' => $remaining
                ];
            }

            // Obtener empresa por defecto del usuario
            $empresa = self::getUserDefaultEmpresa($user['id_login']);

            if (!$empresa) {
                return ['success' => false, 'error' => 'No hay empresa asignada'];
            }

            // Login exitoso - limpiar intentos fallidos
            Security::clearLoginAttempts($ip, $usuario);
            Security::logLoginAttempt($usuario, true, $ip, $userAgent);

            // Establecer sesión
            Session::setEmpresa($empresa);
            Session::setUser($user);

            // Registrar acceso
            Security::logLogin($user['id_login'], $usuario);

            // Configurar "Recordarme" si se solicitó
            if ($remember) {
                self::setRememberCookie($user['id_login']);
                // Marcar que esta sesión es persistente
                Session::set('session_persistent', true);
            }

            return [
                'success' => true,
                'user' => $user,
                'empresa' => $empresa
            ];
        } catch (Exception $e) {
            error_log("[ERROR] Error en login: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error en autenticación'];
        }
    }

    /**
     * Login automático desde cookie "Recordarme"
     */
    public static function loginFromRememberCookie(): bool
    {
        if (Session::isLoggedIn()) {
            return true;
        }

        if (empty($_COOKIE[self::REMEMBER_COOKIE])) {
            return false;
        }

        $token = $_COOKIE[self::REMEMBER_COOKIE];
        $userId = Security::validateRememberToken($token);

        if (!$userId) {
            self::clearRememberCookie();
            return false;
        }

        try {
            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                SELECT u.*, 
                       CASE 
                           WHEN u.priv_admin = 'Y' THEN 'ADMINISTRADOR'
                           ELSE COALESCE(g.description, 'Usuario')
                       END as group_name
                FROM sec_users u
                LEFT JOIN sec_groups g ON u.id_grupo = g.id_grupo AND g.group_id = 1
                WHERE u.id_login = ? AND u.active = 'Y'
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                self::clearRememberCookie();
                return false;
            }

            $empresa = self::getUserDefaultEmpresa($user['id_login']);

            if (!$empresa) {
                return false;
            }

            // Establecer sesión
            Session::setEmpresa($empresa);
            Session::setUser($user);

            // Registrar acceso automático
            Security::logAccess($user['id_login'], 'AUTO_LOGIN', 'auth', 'Login desde cookie recordarme');

            // Renovar token
            self::setRememberCookie($user['id_login']);

            return true;
        } catch (Exception $e) {
            error_log("[Auth] Error en auto-login: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Establecer cookie "Recordarme"
     */
    private static function setRememberCookie(int $userId): void
    {
        $token = Security::createRememberToken($userId);

        if ($token) {
            $expires = time() + (86400 * self::REMEMBER_DAYS);
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

            setcookie(
                self::REMEMBER_COOKIE,
                $token,
                [
                    'expires' => $expires,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        }
    }

    /**
     * Limpiar cookie "Recordarme"
     */
    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true
            ]);
        }
    }

    /**
     * Obtener empresa por defecto del usuario
     */
    private static function getUserDefaultEmpresa(int $idLogin): ?array
    {
        $db = Database::getMasterConnection();

        // Primero buscar la empresa asignada al usuario
        $stmt = $db->prepare("
            SELECT e.* 
            FROM empresa e
            INNER JOIN sec_users u ON u.id_empresa = e.id_empresa
            WHERE u.id_login = ?
        ");
        $stmt->execute([$idLogin]);
        $empresa = $stmt->fetch();

        if ($empresa) {
            return $empresa;
        }

        // Fallback: primera empresa activa si el usuario no tiene asignada
        $stmt = $db->prepare("
            SELECT * 
            FROM empresa
            ORDER BY id_empresa 
            LIMIT 1
        ");
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    /**
     * Cerrar sesión
     */
    public static function logout(): void
    {
        // Registrar logout antes de destruir sesión
        if (Session::isLoggedIn()) {
            Security::logLogout(Session::getIdLogin(), $_SESSION['usuario'] ?? '');
            Security::deleteRememberToken(Session::getIdLogin());
        }

        // Limpiar cookie recordarme
        self::clearRememberCookie();

        Session::destroy();
        Database::closeAll();
    }

    /**
     * Usuario actual
     */
    public static function user(): ?array
    {
        if (!Session::isLoggedIn()) {
            return null;
        }

        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT * FROM sec_users WHERE id_login = ?");
        $stmt->execute([Session::getIdLogin()]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Buscar usuario por email (para recuperar contraseña)
     */
    public static function findByEmail(string $email): ?array
    {
        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("SELECT * FROM sec_users WHERE email = ? AND active = 'Y'");
            $stmt->execute([$email]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            error_log("[Auth] Error buscando por email: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Buscar usuario por login
     */
    public static function findByLogin(string $login): ?array
    {
        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("SELECT * FROM sec_users WHERE login = ? AND active = 'Y'");
            $stmt->execute([$login]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            error_log("[Auth] Error buscando por login: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar contraseña
     */
    public static function updatePassword(int $userId, string $newPassword): bool
    {
        try {
            $db = Database::getMasterConnection();

            // Usar MD5 para compatibilidad con sistema legacy
            $hashedPassword = md5($newPassword);

            $stmt = $db->prepare("UPDATE sec_users SET pswd = ? WHERE id_login = ?");
            $result = $stmt->execute([$hashedPassword, $userId]);

            if ($result) {
                Security::logAccess($userId, 'PASSWORD_CHANGE', 'auth', 'Contraseña actualizada');
            }

            return $result;
        } catch (Exception $e) {
            error_log("[Auth] Error actualizando contraseña: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Iniciar proceso de recuperación de contraseña
     */
    public static function initiatePasswordReset(string $emailOrLogin): array
    {
        // Buscar usuario por email o login
        $user = self::findByEmail($emailOrLogin);

        if (!$user) {
            $user = self::findByLogin($emailOrLogin);
        }

        if (!$user) {
            // Por seguridad, no revelar si el usuario existe
            return ['success' => true, 'message' => 'Si el usuario existe, recibirá un email con instrucciones.'];
        }

        if (empty($user['email'])) {
            return ['success' => false, 'error' => 'El usuario no tiene email configurado.'];
        }

        // Crear token de reset
        $token = Security::createPasswordResetToken($user['id_login'], $user['email']);

        if (!$token) {
            return ['success' => false, 'error' => 'Error generando token de recuperación.'];
        }

        // Log del intento
        Security::logAccess($user['id_login'], 'PASSWORD_RESET_REQUEST', 'auth', 'Solicitud de reset de contraseña');

        return [
            'success' => true,
            'message' => 'Si el usuario existe, recibirá un email con instrucciones.',
            'token' => $token, // Solo para desarrollo/testing
            'email' => $user['email'],
            'user_id' => $user['id_login']
        ];
    }

    /**
     * Completar reset de contraseña
     */
    public static function completePasswordReset(string $token, string $newPassword): array
    {
        $resetData = Security::validatePasswordResetToken($token);

        if (!$resetData) {
            return ['success' => false, 'error' => 'Token inválido o expirado.'];
        }

        // Actualizar contraseña
        $updated = self::updatePassword($resetData['user_id'], $newPassword);

        if (!$updated) {
            return ['success' => false, 'error' => 'Error actualizando contraseña.'];
        }

        // Marcar token como usado
        Security::markResetTokenUsed($token);

        // Eliminar tokens recordarme (forzar re-login en todos los dispositivos)
        Security::deleteRememberToken($resetData['user_id']);

        return ['success' => true, 'message' => 'Contraseña actualizada correctamente.'];
    }
}
