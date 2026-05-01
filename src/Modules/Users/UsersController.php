<?php

/**
 * UsersController - CRUD de Usuarios
 */

if (!defined('SISTEMAX_V1')) {
    die('Acceso no autorizado');
}

class UsersController
{
    /**
     * Listar usuarios con paginación y filtros
     */
    public static function list(array $params = []): array
    {
        try {
            $db = Database::getMasterConnection();

            $page = max(1, (int)($params['page'] ?? 1));
            $perPage = min(100, max(10, (int)($params['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            $search = trim($params['search'] ?? '');
            $status = $params['status'] ?? ''; // 'Y', 'N' o ''
            $idGrupo = (int)($params['id_grupo'] ?? 0);
            $idEmpresa = (int)($params['id_empresa'] ?? 0);

            // Construir WHERE
            $where = [];
            $bindings = [];

            if (!empty($search)) {
                $where[] = "(u.login LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
                $searchTerm = "%{$search}%";
                $bindings[] = $searchTerm;
                $bindings[] = $searchTerm;
                $bindings[] = $searchTerm;
            }

            if ($status === 'Y' || $status === 'N') {
                $where[] = "u.active = ?";
                $bindings[] = $status;
            }

            if ($idGrupo > 0) {
                $where[] = "u.id_grupo = ?";
                $bindings[] = $idGrupo;
            }

            if ($idEmpresa > 0) {
                $where[] = "u.id_empresa = ?";
                $bindings[] = $idEmpresa;
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            // Contar total
            $countSql = "SELECT COUNT(*) as total FROM sec_users u {$whereClause}";
            $stmt = $db->prepare($countSql);
            $stmt->execute($bindings);
            $total = (int)$stmt->fetch()['total'];

            // Obtener usuarios
            $sql = "
                SELECT u.id_login, u.login, u.name, u.email, u.active, u.priv_admin,
                       u.id_empresa, u.id_grupo, u.created_at, u.updated_at,
                       e.nombre as empresa_nombre,
                       g.description as grupo_nombre
                FROM sec_users u
                LEFT JOIN empresa e ON u.id_empresa = e.id_empresa
                LEFT JOIN sec_groups g ON u.id_grupo = g.id_grupo AND g.group_id = 1
                {$whereClause}
                ORDER BY u.name ASC
                LIMIT ? OFFSET ?
            ";

            $bindings[] = $perPage;
            $bindings[] = $offset;

            $stmt = $db->prepare($sql);
            $stmt->execute($bindings);
            $users = $stmt->fetchAll();

            return [
                'success' => true,
                'data' => $users,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => ceil($total / $perPage)
                ]
            ];
        } catch (Exception $e) {
            error_log("[UsersController] Error en list: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo usuarios'];
        }
    }

    /**
     * Obtener un usuario por ID
     */
    public static function get(int $id): array
    {
        try {
            $db = Database::getMasterConnection();

            $stmt = $db->prepare("
                SELECT u.*, 
                       e.nombre as empresa_nombre,
                       g.description as grupo_nombre
                FROM sec_users u
                LEFT JOIN empresa e ON u.id_empresa = e.id_empresa
                LEFT JOIN sec_groups g ON u.id_grupo = g.id_grupo AND g.group_id = 1
                WHERE u.id_login = ?
            ");
            $stmt->execute([$id]);
            $user = $stmt->fetch();

            if (!$user) {
                return ['success' => false, 'error' => 'Usuario no encontrado'];
            }

            // No exponer la contraseña
            unset($user['pswd']);

            return ['success' => true, 'data' => $user];
        } catch (Exception $e) {
            error_log("[UsersController] Error en get: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo usuario'];
        }
    }

    /**
     * Crear usuario
     */
    public static function create(array $data): array
    {
        try {
            $db = Database::getMasterConnection();

            // Validaciones
            $errors = self::validate($data, true);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }

            // Verificar login único
            $stmt = $db->prepare("SELECT id_login FROM sec_users WHERE login = ?");
            $stmt->execute([$data['login']]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'El usuario ya existe'];
            }

            // Verificar email único
            if (!empty($data['email'])) {
                $stmt = $db->prepare("SELECT id_login FROM sec_users WHERE email = ?");
                $stmt->execute([$data['email']]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'error' => 'El email ya está registrado'];
                }
            }

            // Hashear contraseña (MD5 para compatibilidad legacy)
            $hashedPassword = md5($data['password']);

            $stmt = $db->prepare("
                INSERT INTO sec_users 
                (login, pswd, name, email, active, priv_admin, id_empresa, id_grupo, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");

            $stmt->execute([
                $data['login'],
                $hashedPassword,
                $data['name'] ?? $data['login'],
                $data['email'] ?? null,
                $data['active'] ?? 'Y',
                $data['priv_admin'] ?? 'N',
                $data['id_empresa'] ?? null,
                $data['id_grupo'] ?? null
            ]);

            $newId = (int)$db->lastInsertId();

            // Log
            if (Session::isLoggedIn()) {
                Security::logAccess(
                    Session::getIdLogin(),
                    'CREATE_USER',
                    'users',
                    "Creado usuario: {$data['login']} (ID: {$newId})"
                );
            }

            return [
                'success' => true,
                'message' => 'Usuario creado correctamente',
                'id' => $newId
            ];
        } catch (Exception $e) {
            error_log("[UsersController] Error en create: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error creando usuario'];
        }
    }

    /**
     * Actualizar usuario
     */
    public static function update(int $id, array $data): array
    {
        try {
            $db = Database::getMasterConnection();

            // Verificar que existe
            $stmt = $db->prepare("SELECT * FROM sec_users WHERE id_login = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                return ['success' => false, 'error' => 'Usuario no encontrado'];
            }

            // Validaciones
            $errors = self::validate($data, false);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }

            // Verificar login único (si cambió)
            if (!empty($data['login']) && $data['login'] !== $existing['login']) {
                $stmt = $db->prepare("SELECT id_login FROM sec_users WHERE login = ? AND id_login != ?");
                $stmt->execute([$data['login'], $id]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'error' => 'El usuario ya existe'];
                }
            }

            // Verificar email único (si cambió)
            if (!empty($data['email']) && $data['email'] !== $existing['email']) {
                $stmt = $db->prepare("SELECT id_login FROM sec_users WHERE email = ? AND id_login != ?");
                $stmt->execute([$data['email'], $id]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'error' => 'El email ya está registrado'];
                }
            }

            // Construir UPDATE dinámico
            $updates = [];
            $bindings = [];

            $allowedFields = ['login', 'name', 'email', 'active', 'priv_admin', 'id_empresa', 'id_grupo'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[] = "{$field} = ?";
                    $bindings[] = $data[$field];
                }
            }

            // Actualizar contraseña solo si se proporciona
            if (!empty($data['password'])) {
                $updates[] = "pswd = ?";
                $bindings[] = md5($data['password']);
            }

            if (empty($updates)) {
                return ['success' => false, 'error' => 'No hay datos para actualizar'];
            }

            $updates[] = "updated_at = NOW()";
            $bindings[] = $id;

            $sql = "UPDATE sec_users SET " . implode(", ", $updates) . " WHERE id_login = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($bindings);

            // Log
            if (Session::isLoggedIn()) {
                Security::logAccess(
                    Session::getIdLogin(),
                    'UPDATE_USER',
                    'users',
                    "Actualizado usuario: {$existing['login']} (ID: {$id})"
                );
            }

            return ['success' => true, 'message' => 'Usuario actualizado correctamente'];
        } catch (Exception $e) {
            error_log("[UsersController] Error en update: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error actualizando usuario'];
        }
    }

    /**
     * Eliminar usuario (soft delete - desactivar)
     */
    public static function delete(int $id, bool $hardDelete = false): array
    {
        try {
            $db = Database::getMasterConnection();

            // Verificar que existe
            $stmt = $db->prepare("SELECT * FROM sec_users WHERE id_login = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();

            if (!$user) {
                return ['success' => false, 'error' => 'Usuario no encontrado'];
            }

            // No permitir eliminar al usuario actual
            if (Session::isLoggedIn() && Session::getIdLogin() === $id) {
                return ['success' => false, 'error' => 'No puedes eliminar tu propio usuario'];
            }

            if ($hardDelete) {
                // Eliminación física (usar con cuidado)
                $stmt = $db->prepare("DELETE FROM sec_users WHERE id_login = ?");
                $stmt->execute([$id]);
                $action = 'DELETE_USER';
                $message = 'Usuario eliminado permanentemente';
            } else {
                // Soft delete: desactivar
                $stmt = $db->prepare("UPDATE sec_users SET active = 'N', updated_at = NOW() WHERE id_login = ?");
                $stmt->execute([$id]);
                $action = 'DEACTIVATE_USER';
                $message = 'Usuario desactivado correctamente';
            }

            // Log
            if (Session::isLoggedIn()) {
                Security::logAccess(
                    Session::getIdLogin(),
                    $action,
                    'users',
                    "Usuario: {$user['login']} (ID: {$id})"
                );
            }

            return ['success' => true, 'message' => $message];
        } catch (Exception $e) {
            error_log("[UsersController] Error en delete: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error eliminando usuario'];
        }
    }

    /**
     * Activar usuario
     */
    public static function activate(int $id): array
    {
        try {
            $db = Database::getMasterConnection();

            $stmt = $db->prepare("UPDATE sec_users SET active = 'Y', updated_at = NOW() WHERE id_login = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Usuario no encontrado'];
            }

            // Log
            if (Session::isLoggedIn()) {
                Security::logAccess(Session::getIdLogin(), 'ACTIVATE_USER', 'users', "ID: {$id}");
            }

            return ['success' => true, 'message' => 'Usuario activado correctamente'];
        } catch (Exception $e) {
            error_log("[UsersController] Error en activate: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error activando usuario'];
        }
    }

    /**
     * Cambiar contraseña de un usuario
     */
    public static function changePassword(int $id, string $newPassword): array
    {
        try {
            if (strlen($newPassword) < 6) {
                return ['success' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres'];
            }

            $db = Database::getMasterConnection();

            $hashedPassword = md5($newPassword);

            $stmt = $db->prepare("UPDATE sec_users SET pswd = ?, updated_at = NOW() WHERE id_login = ?");
            $stmt->execute([$hashedPassword, $id]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Usuario no encontrado'];
            }

            // Eliminar tokens recordarme
            Security::deleteRememberToken($id);

            // Log
            if (Session::isLoggedIn()) {
                Security::logAccess(Session::getIdLogin(), 'CHANGE_PASSWORD', 'users', "Usuario ID: {$id}");
            }

            return ['success' => true, 'message' => 'Contraseña actualizada correctamente'];
        } catch (Exception $e) {
            error_log("[UsersController] Error en changePassword: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error cambiando contraseña'];
        }
    }

    /**
     * Obtener grupos disponibles
     */
    public static function getGroups(int $idEmpresa = 0): array
    {
        try {
            $db = Database::getMasterConnection();

            $sql = "SELECT DISTINCT id_grupo, description FROM sec_groups WHERE group_id = 1";
            $bindings = [];

            if ($idEmpresa > 0) {
                $sql .= " AND id_grupo = ?";
                $bindings[] = $idEmpresa;
            }

            $sql .= " ORDER BY description";

            $stmt = $db->prepare($sql);
            $stmt->execute($bindings);

            return ['success' => true, 'data' => $stmt->fetchAll()];
        } catch (Exception $e) {
            error_log("[UsersController] Error en getGroups: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo grupos'];
        }
    }

    /**
     * Obtener empresas disponibles
     */
    public static function getEmpresas(): array
    {
        try {
            $db = Database::getMasterConnection();

            $stmt = $db->prepare("SELECT id_empresa, nombre FROM empresa WHERE active = 'Y' ORDER BY nombre");
            $stmt->execute();

            return ['success' => true, 'data' => $stmt->fetchAll()];
        } catch (Exception $e) {
            error_log("[UsersController] Error en getEmpresas: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo empresas'];
        }
    }

    /**
     * Validar datos de usuario
     */
    private static function validate(array $data, bool $isNew): array
    {
        $errors = [];

        if ($isNew) {
            if (empty($data['login'])) {
                $errors['login'] = 'El usuario es requerido';
            } elseif (strlen($data['login']) < 3) {
                $errors['login'] = 'El usuario debe tener al menos 3 caracteres';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $data['login'])) {
                $errors['login'] = 'El usuario solo puede contener letras, números, guiones y puntos';
            }

            if (empty($data['password'])) {
                $errors['password'] = 'La contraseña es requerida';
            } elseif (strlen($data['password']) < 6) {
                $errors['password'] = 'La contraseña debe tener al menos 6 caracteres';
            }
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El email no es válido';
        }

        if (!empty($data['active']) && !in_array($data['active'], ['Y', 'N'])) {
            $errors['active'] = 'El estado debe ser Y o N';
        }

        if (!empty($data['priv_admin']) && !in_array($data['priv_admin'], ['Y', 'N'])) {
            $errors['priv_admin'] = 'El privilegio debe ser Y o N';
        }

        return $errors;
    }
}
