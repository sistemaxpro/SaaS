<?php

/**
 * Control de Acceso por Privilegios
 * Tabla: serproc1.sec_groups_apps
 * Clave: (id_grupo=id_empresa, group_id, app_name=permiso_base)
 */

class Permission
{
    /**
     * Obtener todos los permisos de una app para el usuario actual
     * Retorna array con priv_access, priv_insert, priv_update, priv_delete, priv_export, priv_print
     */
    public static function getAppPermissions(string $appName): array
    {
        $defaults = [
            'priv_access' => 'N', 'priv_insert' => 'N', 'priv_update' => 'N',
            'priv_delete' => 'N', 'priv_export' => 'N', 'priv_print' => 'N'
        ];

        // Admin tiene todo
        if (self::isUserAdmin()) {
            return array_map(fn() => 'Y', $defaults);
        }

        $idEmpresa = Session::getIdEmpresa();
        $groupId = self::getUserGroupId();
        if (!$idEmpresa || !$groupId) {
            return $defaults;
        }

        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("
                SELECT priv_access, priv_insert, priv_update, priv_delete, priv_export, priv_print 
                FROM sec_groups_apps 
                WHERE id_grupo = ? AND group_id = ? AND app_name = ?
            ");
            $stmt->execute([$idEmpresa, $groupId, $appName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                foreach ($defaults as $key => $val) {
                    $defaults[$key] = ($row[$key] === 'Y') ? 'Y' : 'N';
                }
            }
        } catch (Exception $e) {
            error_log("[Permission] Error getAppPermissions: " . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Verificar acceso a aplicación (priv_access)
     */
    public static function hasAccess(string $appName): bool
    {
        $perms = self::getAppPermissions($appName);
        return $perms['priv_access'] === 'Y';
    }

    /**
     * Verificar permiso de insertar
     */
    public static function canInsert(string $appName): bool
    {
        $perms = self::getAppPermissions($appName);
        return $perms['priv_access'] === 'Y' && $perms['priv_insert'] === 'Y';
    }

    /**
     * Verificar permiso de editar
     */
    public static function canUpdate(string $appName): bool
    {
        $perms = self::getAppPermissions($appName);
        return $perms['priv_access'] === 'Y' && $perms['priv_update'] === 'Y';
    }

    /**
     * Verificar permiso de eliminar
     */
    public static function canDelete(string $appName): bool
    {
        $perms = self::getAppPermissions($appName);
        return $perms['priv_access'] === 'Y' && $perms['priv_delete'] === 'Y';
    }

    /**
     * Verificar permiso de exportar
     */
    public static function canExport(string $appName): bool
    {
        $perms = self::getAppPermissions($appName);
        return $perms['priv_access'] === 'Y' && $perms['priv_export'] === 'Y';
    }

    /**
     * Verificar permiso de imprimir
     */
    public static function canPrint(string $appName): bool
    {
        $perms = self::getAppPermissions($appName);
        return $perms['priv_access'] === 'Y' && $perms['priv_print'] === 'Y';
    }

    /**
     * Requerir acceso (die con JSON 403 si no tiene)
     */
    public static function requireAccess(string $appName): void
    {
        if (!self::hasAccess($appName)) {
            http_response_code(403);
            if (self::isApiRequest()) {
                die(json_encode(['ok' => false, 'error' => 'No tiene permisos para acceder a esta aplicación']));
            } else {
                die(self::accessDeniedPage($appName));
            }
        }
    }

    /**
     * Requerir permiso específico (para APIs)
     */
    public static function requirePermission(string $appName, string $permission): void
    {
        $perms = self::getAppPermissions($appName);
        if ($perms['priv_access'] !== 'Y' || ($perms[$permission] ?? 'N') !== 'Y') {
            http_response_code(403);
            $labels = [
                'priv_insert' => 'crear', 'priv_update' => 'editar', 'priv_delete' => 'eliminar',
                'priv_export' => 'exportar', 'priv_print' => 'imprimir'
            ];
            $action = $labels[$permission] ?? $permission;
            die(json_encode(['ok' => false, 'error' => "No tiene permisos para $action en esta aplicación"]));
        }
    }

    /**
     * Obtener permisos como JSON para inyectar en el frontend
     */
    public static function getAppPermissionsJson(string $appName): string
    {
        return json_encode(self::getAppPermissions($appName));
    }

    /**
     * Obtener todas las apps permitidas del usuario
     */
    public static function getAllowedApps(): array
    {
        if (self::isUserAdmin()) {
            $db = Database::getMasterConnection();
            $stmt = $db->query("SELECT DISTINCT app_name FROM sec_groups_apps");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $idEmpresa = Session::getIdEmpresa();
        $groupId = self::getUserGroupId();
        if (!$idEmpresa || !$groupId) {
            return [];
        }

        $db = Database::getMasterConnection();
        $stmt = $db->prepare("
            SELECT app_name 
            FROM sec_groups_apps 
            WHERE id_grupo = ? AND group_id = ? AND priv_access = 'Y'
        ");
        $stmt->execute([$idEmpresa, $groupId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Obtener group_id del usuario desde sesión o DB
     */
    private static function getUserGroupId(): ?int
    {
        // Primero intentar desde sesión
        $groupId = Session::get('group_id');
        if ($groupId) return (int)$groupId;

        // Si no está en sesión, buscar en DB
        $login = Session::get('usuario');
        $idEmpresa = Session::getIdEmpresa();
        if (!$login || !$idEmpresa) return null;

        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("SELECT group_id FROM sec_users_groups WHERE login = ? AND id_grupo = ? LIMIT 1");
            $stmt->execute([$login, $idEmpresa]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                Session::set('group_id', $row['group_id']);
                return (int)$row['group_id'];
            }
        } catch (Exception $e) {
            error_log("[Permission] Error getUserGroupId: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Verificar si el usuario es admin
     */
    private static function isUserAdmin(): bool
    {
        $priv = Session::get('usr_priv_admin', 'N');
        return in_array($priv, ['Y', 'S', '1'], true);
    }

    /**
     * Detectar si es una petición de API (JSON)
     */
    private static function isApiRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isXhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        return $isXhr || str_contains($accept, 'application/json') || str_contains($contentType, 'application/json')
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    }

    /**
     * Página de acceso denegado HTML
     */
    private static function accessDeniedPage(string $appName): string
    {
        return '<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acceso Denegado</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head><body class="bg-gray-50 dark:bg-slate-900 min-h-screen flex items-center justify-center">
<div class="text-center p-8">
<div class="w-20 h-20 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
<i class="fas fa-lock text-3xl text-red-500"></i></div>
<h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Acceso Denegado</h1>
<p class="text-gray-500 dark:text-gray-400 mb-6">No tiene permisos para acceder a esta aplicación.<br>Contacte al administrador.</p>
<a href="/public/menu/menu.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold transition-colors">
<i class="fas fa-home"></i> Volver al Menú</a>
</div></body></html>';
    }
}
