<?php

/**
 * SuscripcionController - Gestión de Suscripciones SaaS
 * Modelo tipo factura: Cabecera (suscripción mensual) + Items (apps contratadas)
 */

if (!defined('SISTEMAX_V1')) {
    die('Acceso no autorizado');
}

class SuscripcionController
{
    // Días de gracia por defecto
    const DIAS_GRACIA_DEFAULT = 5;

    // Estados de suscripción
    const ESTADO_ACTIVA = 'activa';
    const ESTADO_GRACIA = 'gracia';
    const ESTADO_VENCIDA = 'vencida';
    const ESTADO_CANCELADA = 'cancelada';

    // Estados de pago
    const PAGO_PENDIENTE = 'pendiente';
    const PAGO_PAGADO = 'pagado';
    const PAGO_PARCIAL = 'parcial';
    const PAGO_ATRASADO = 'atrasado';
    const APPS_DEPRECADAS_CATALOGO = ['pos_autorrepuestos'];

    private static function obtenerSuscripcionVigenteRow(PDO $db, int $idEmpresa): ?array
    {
        $stmt = $db->prepare("
            SELECT id_suscripcion, periodo_inicio, periodo_fin, estado, estado_pago, dias_gracia
            FROM saas_suscripcion
            WHERE id_empresa = ?
              AND estado IN ('activa', 'gracia')
              AND CURDATE() BETWEEN periodo_inicio AND DATE_ADD(periodo_fin, INTERVAL dias_gracia DAY)
            ORDER BY periodo_inicio DESC, id_suscripcion DESC
            LIMIT 1
        ");
        $stmt->execute([$idEmpresa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function obtenerSuscripcionPeriodoRow(PDO $db, int $idEmpresa, string $periodoInicio): ?array
    {
        $stmt = $db->prepare("
            SELECT id_suscripcion, periodo_inicio, periodo_fin, estado, estado_pago, dias_gracia
            FROM saas_suscripcion
            WHERE id_empresa = ?
              AND periodo_inicio = ?
            ORDER BY id_suscripcion DESC
            LIMIT 1
        ");
        $stmt->execute([$idEmpresa, $periodoInicio]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function obtenerUltimaSuscripcionRow(PDO $db, int $idEmpresa): ?array
    {
        $stmt = $db->prepare("
            SELECT id_suscripcion, periodo_inicio, periodo_fin, estado, estado_pago, dias_gracia
            FROM saas_suscripcion
            WHERE id_empresa = ?
              AND estado <> 'cancelada'
            ORDER BY periodo_inicio DESC, id_suscripcion DESC
            LIMIT 1
        ");
        $stmt->execute([$idEmpresa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function catalogoHasColumn(PDO $db, string $column): bool
    {
        static $cache = [];
        $key = strtolower(trim($column));
        if ($key === '') {
            return false;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'saas_apps_catalogo'
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$key]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }

    private static function catalogoDisponibleFilter(PDO $db, string $alias = 'a'): string
    {
        $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'a';
        $extra = '';
        if (!empty(self::APPS_DEPRECADAS_CATALOGO)) {
            $quoted = array_map(static function ($code) use ($db) {
                return $db->quote((string)$code);
            }, self::APPS_DEPRECADAS_CATALOGO);
            $extra .= " AND {$safeAlias}.codigo NOT IN (" . implode(',', $quoted) . ")";
        }
        if (!self::catalogoHasColumn($db, 'disponible')) {
            return $extra;
        }
        return " AND COALESCE({$safeAlias}.disponible, 1) = 1{$extra}";
    }

    private static function ensureSolicitudesTable(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS saas_solicitudes (
                id_solicitud INT NOT NULL AUTO_INCREMENT,
                empresa VARCHAR(150) NOT NULL,
                contacto VARCHAR(120) NOT NULL,
                email VARCHAR(160) NOT NULL,
                telefono VARCHAR(40) NULL,
                ruc VARCHAR(40) NULL,
                mensaje VARCHAR(500) NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'nuevo',
                ip_origen VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                invite_token VARCHAR(80) NULL,
                invite_expires_at DATETIME NULL,
                invite_sent_at DATETIME NULL,
                invite_used_at DATETIME NULL,
                admin_login VARCHAR(80) NULL,
                admin_name VARCHAR(150) NULL,
                admin_user_id INT NULL,
                admin_created_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_solicitud),
                KEY idx_estado_fecha (estado, created_at),
                KEY idx_email (email),
                KEY idx_invite_token (invite_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $columns = [
            'invite_token' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_token VARCHAR(80) NULL AFTER user_agent",
            'invite_expires_at' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_expires_at DATETIME NULL AFTER invite_token",
            'invite_sent_at' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_sent_at DATETIME NULL AFTER invite_expires_at",
            'invite_used_at' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_used_at DATETIME NULL AFTER invite_sent_at",
            'admin_login' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_login VARCHAR(80) NULL AFTER invite_used_at",
            'admin_name' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_name VARCHAR(150) NULL AFTER admin_login",
            'admin_user_id' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_user_id INT NULL AFTER admin_name",
            'admin_created_at' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_created_at DATETIME NULL AFTER admin_user_id",
        ];

        foreach ($columns as $column => $sql) {
            $check = $db->prepare("
                SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'saas_solicitudes'
                  AND COLUMN_NAME = :col
                LIMIT 1
            ");
            $check->execute([':col' => $column]);
            if (!$check->fetchColumn()) {
                $db->exec($sql);
            }
        }
    }

    // =========================================================================
    // CATÁLOGO DE APPS
    // =========================================================================

    /**
     * Listar todas las apps del catálogo
     */
    public static function listarApps(array $params = []): array
    {
        try {
            $db = Database::getMasterConnection();

            $soloActivas = ($params['solo_activas'] ?? true) ? 1 : 0;
            $whereParts = [];
            if ($soloActivas) {
                $whereParts[] = "activo = 1";
            }
            if (self::catalogoHasColumn($db, 'disponible')) {
                $whereParts[] = "COALESCE(saas_apps_catalogo.disponible, 1) = 1";
            }
            if (!empty(self::APPS_DEPRECADAS_CATALOGO)) {
                $quoted = array_map(static function ($code) use ($db) {
                    return $db->quote((string)$code);
                }, self::APPS_DEPRECADAS_CATALOGO);
                $whereParts[] = "saas_apps_catalogo.codigo NOT IN (" . implode(',', $quoted) . ")";
            }
            $where = !empty($whereParts) ? "WHERE " . implode(' AND ', $whereParts) : "";

            $sql = "SELECT * FROM saas_apps_catalogo {$where} ORDER BY orden ASC";
            $stmt = $db->query($sql);
            $apps = $stmt->fetchAll();

            return ['success' => true, 'data' => $apps];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en listarApps: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo catálogo de apps'];
        }
    }

    public static function listarSolicitudes(array $params = []): array
    {
        try {
            $db = Database::getMasterConnection();
            self::ensureSolicitudesTable($db);

            $estado = strtolower(trim((string)($params['estado'] ?? '')));
            $q = trim((string)($params['q'] ?? ''));
            $limit = max(10, min(500, (int)($params['limit'] ?? 200)));

            $where = [];
            $bind = [];

            if ($estado !== '' && in_array($estado, ['nuevo', 'contactado', 'cerrado'], true)) {
                $where[] = "estado = :estado";
                $bind[':estado'] = $estado;
            }
            if ($q !== '') {
                $where[] = "(empresa LIKE :q OR contacto LIKE :q OR email LIKE :q OR ruc LIKE :q)";
                $bind[':q'] = '%' . $q . '%';
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = $db->prepare("
                SELECT id_solicitud, empresa, contacto, email, telefono, ruc, mensaje, estado, created_at
                FROM saas_solicitudes
                {$whereSql}
                ORDER BY created_at DESC
                LIMIT {$limit}
            ");
            $stmt->execute($bind);
            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error listarSolicitudes: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo solicitudes'];
        }
    }

    public static function actualizarEstadoSolicitud(int $idSolicitud, string $estado): array
    {
        try {
            $estado = strtolower(trim($estado));
            if (!in_array($estado, ['nuevo', 'contactado', 'cerrado'], true)) {
                return ['success' => false, 'error' => 'Estado inválido'];
            }
            $db = Database::getMasterConnection();
            self::ensureSolicitudesTable($db);

            $stmt = $db->prepare("UPDATE saas_solicitudes SET estado = :estado WHERE id_solicitud = :id");
            $stmt->execute([':estado' => $estado, ':id' => $idSolicitud]);
            if ($stmt->rowCount() <= 0) {
                return ['success' => false, 'error' => 'Solicitud no encontrada'];
            }
            return ['success' => true, 'message' => 'Estado actualizado'];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error actualizarEstadoSolicitud: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error actualizando solicitud'];
        }
    }

    public static function actualizarSolicitud(int $idSolicitud, array $data): array
    {
        try {
            if ($idSolicitud <= 0) {
                return ['success' => false, 'error' => 'ID de solicitud requerido'];
            }
            $db = Database::getMasterConnection();
            self::ensureSolicitudesTable($db);

            $empresa = trim((string)($data['empresa'] ?? ''));
            $contacto = trim((string)($data['contacto'] ?? ''));
            $email = trim((string)($data['email'] ?? ''));
            $telefono = trim((string)($data['telefono'] ?? ''));
            $ruc = trim((string)($data['ruc'] ?? ''));
            $mensaje = trim((string)($data['mensaje'] ?? ''));
            $estado = strtolower(trim((string)($data['estado'] ?? 'nuevo')));

            if ($empresa === '') return ['success' => false, 'error' => 'Empresa requerida'];
            if ($contacto === '') return ['success' => false, 'error' => 'Contacto requerido'];
            if (!in_array($estado, ['nuevo', 'contactado', 'cerrado'], true)) {
                return ['success' => false, 'error' => 'Estado inválido'];
            }

            $stmt = $db->prepare("
                UPDATE saas_solicitudes
                SET empresa = :empresa,
                    contacto = :contacto,
                    email = :email,
                    telefono = :telefono,
                    ruc = :ruc,
                    mensaje = :mensaje,
                    estado = :estado
                WHERE id_solicitud = :id
            ");
            $stmt->execute([
                ':empresa' => substr($empresa, 0, 150),
                ':contacto' => substr($contacto, 0, 120),
                ':email' => substr($email, 0, 160),
                ':telefono' => $telefono === '' ? null : substr($telefono, 0, 40),
                ':ruc' => $ruc === '' ? null : substr($ruc, 0, 40),
                ':mensaje' => $mensaje === '' ? null : substr($mensaje, 0, 500),
                ':estado' => $estado,
                ':id' => $idSolicitud
            ]);

            if ($stmt->rowCount() <= 0) {
                $check = $db->prepare("SELECT id_solicitud FROM saas_solicitudes WHERE id_solicitud = :id");
                $check->execute([':id' => $idSolicitud]);
                if (!$check->fetchColumn()) {
                    return ['success' => false, 'error' => 'Solicitud no encontrada'];
                }
            }

            return ['success' => true, 'message' => 'Solicitud actualizada'];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error actualizarSolicitud: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error actualizando solicitud'];
        }
    }

    public static function eliminarSolicitud(int $idSolicitud): array
    {
        try {
            if ($idSolicitud <= 0) {
                return ['success' => false, 'error' => 'ID de solicitud requerido'];
            }
            $db = Database::getMasterConnection();
            self::ensureSolicitudesTable($db);

            $stmt = $db->prepare("DELETE FROM saas_solicitudes WHERE id_solicitud = :id");
            $stmt->execute([':id' => $idSolicitud]);
            if ($stmt->rowCount() <= 0) {
                return ['success' => false, 'error' => 'Solicitud no encontrada'];
            }
            return ['success' => true, 'message' => 'Solicitud suprimida'];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error eliminarSolicitud: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error suprimiendo solicitud'];
        }
    }

    /**
     * Garantiza que la app de Gestión de Balanza exista en el catálogo.
     * Idempotente: no duplica si ya existe.
     */
    public static function ensureBalanzaApp(): array
    {
        try {
            $db = Database::getMasterConnection();
            $codigo = 'balanza_electronica';

            $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ?");
            $stmt->execute([$codigo]);
            $exist = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($exist) {
                return ['success' => true, 'created' => false, 'id_app' => (int)$exist['id_app']];
            }

            $sql = "INSERT INTO saas_apps_catalogo
                (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
                VALUES
                (:codigo, :nombre, :descripcion, :ruta_app, :icono, :color, :precio_mensual, :obligatoria, :requiere_modulo, :permiso_base, :orden, :activo, :en_desarrollo, :modulo, :negocio)";
            $stmtIns = $db->prepare($sql);
            $stmtIns->execute([
                ':codigo' => $codigo,
                ':nombre' => 'Gestión de Balanza',
                ':descripcion' => 'Configuración y prueba de códigos de balanza electrónica',
                ':ruta_app' => 'public/balanzas/index.php',
                ':icono' => 'beaker',
                ':color' => 'amber',
                ':precio_mensual' => 0,
                ':obligatoria' => 0,
                ':requiere_modulo' => null,
                ':permiso_base' => 'app_grid_balanza',
                ':orden' => 58,
                ':activo' => 1,
                ':en_desarrollo' => 0,
                ':modulo' => 'Inventario',
                ':negocio' => 'Comercial',
            ]);

            return ['success' => true, 'created' => true, 'id_app' => (int)$db->lastInsertId()];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error ensureBalanzaApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'No se pudo asegurar app de balanza'];
        }
    }

    /**
     * Garantiza que la app de Migrador DB exista en el catálogo.
     * Idempotente: no duplica si ya existe.
     */
    public static function ensureDbMigradorApp(): array
    {
        try {
            $db = Database::getMasterConnection();
            $codigo = 'db_migrador';

            $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ?");
            $stmt->execute([$codigo]);
            $exist = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($exist) {
                return ['success' => true, 'created' => false, 'id_app' => (int)$exist['id_app']];
            }

            $sql = "INSERT INTO saas_apps_catalogo
                (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
                VALUES
                (:codigo, :nombre, :descripcion, :ruta_app, :icono, :color, :precio_mensual, :obligatoria, :requiere_modulo, :permiso_base, :orden, :activo, :en_desarrollo, :modulo, :negocio)";
            $stmtIns = $db->prepare($sql);
            $stmtIns->execute([
                ':codigo' => $codigo,
                ':nombre' => 'Migrador DB',
                ':descripcion' => 'Migración de base de datos entre servidor origen y destino',
                ':ruta_app' => 'public/db_migrador/index.php',
                ':icono' => 'circle-stack',
                ':color' => 'indigo',
                ':precio_mensual' => 0,
                ':obligatoria' => 0,
                ':requiere_modulo' => null,
                ':permiso_base' => 'app_grid_db_migrador',
                ':orden' => 59,
                ':activo' => 1,
                ':en_desarrollo' => 0,
                ':modulo' => 'Administración',
                ':negocio' => 'Tecnología',
            ]);

            return ['success' => true, 'created' => true, 'id_app' => (int)$db->lastInsertId()];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error ensureDbMigradorApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'No se pudo asegurar app migrador DB'];
        }
    }

    /**
     * Obtener una app del catálogo
     */
    public static function getApp(int $idApp): array
    {
        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("SELECT * FROM saas_apps_catalogo WHERE id_app = ?");
            $stmt->execute([$idApp]);
            $app = $stmt->fetch();

            if (!$app) {
                return ['success' => false, 'error' => 'App no encontrada'];
            }

            return ['success' => true, 'data' => $app];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en getApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo app'];
        }
    }

    /**
     * Crear app en catálogo
     */
    public static function crearApp(array $data): array
    {
        try {
            $db = Database::getMasterConnection();

            // Validar campos requeridos
            $errors = [];
            if (empty($data['codigo'])) $errors['codigo'] = 'El código es requerido';
            if (empty($data['nombre'])) $errors['nombre'] = 'El nombre es requerido';
            if (empty($data['ruta_app'])) $errors['ruta_app'] = 'La ruta es requerida';

            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }

            // Verificar código único
            $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ?");
            $stmt->execute([$data['codigo']]);
            if ($stmt->fetch()) {
                return ['success' => false, 'errors' => ['codigo' => 'Este código ya existe']];
            }

            $columnValues = [
                'codigo' => $data['codigo'],
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'ruta_app' => $data['ruta_app'],
                'icono' => $data['icono'] ?? 'cube',
                'color' => $data['color'] ?? 'blue',
                'precio_mensual' => $data['precio_mensual'] ?? 0,
                'obligatoria' => $data['obligatoria'] ?? 0,
                'requiere_modulo' => $data['requiere_modulo'] ?? null,
                'permiso_base' => $data['permiso_base'] ?? null,
                'orden' => $data['orden'] ?? 100,
                'activo' => $data['activo'] ?? 1,
                'en_desarrollo' => $data['en_desarrollo'] ?? 0,
                'modulo' => $data['modulo'] ?? 'General',
                'negocio' => $data['negocio'] ?? 'Comercial',
            ];

            if (self::catalogoHasColumn($db, 'icono_source')) {
                $columnValues['icono_source'] = $data['icono_source'] ?? 'heroicon';
            }
            if (self::catalogoHasColumn($db, 'icono_svg')) {
                $columnValues['icono_svg'] = $data['icono_svg'] ?? null;
            }

            $columns = array_keys($columnValues);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO saas_apps_catalogo (" . implode(', ', $columns) . ") VALUES ({$placeholders})";

            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($columnValues));

            $idApp = $db->lastInsertId();

            return ['success' => true, 'data' => ['id_app' => $idApp], 'message' => 'App creada correctamente'];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en crearApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error creando app'];
        }
    }

    /**
     * Actualizar app del catálogo
     */
    public static function actualizarApp(int $idApp, array $data): array
    {
        try {
            $db = Database::getMasterConnection();

            // Verificar que existe
            $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE id_app = ?");
            $stmt->execute([$idApp]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'App no encontrada'];
            }

            $campos = [];
            $valores = [];

            $camposPermitidos = [
                'nombre', 'descripcion', 'ruta_app', 'icono', 'color',
                'precio_mensual', 'obligatoria', 'requiere_modulo', 'permiso_base', 'orden', 'activo', 'en_desarrollo', 'modulo', 'negocio'
            ];

            if (self::catalogoHasColumn($db, 'icono_source')) {
                $camposPermitidos[] = 'icono_source';
            }
            if (self::catalogoHasColumn($db, 'icono_svg')) {
                $camposPermitidos[] = 'icono_svg';
            }

            foreach ($camposPermitidos as $campo) {
                if (array_key_exists($campo, $data)) {
                    $campos[] = "{$campo} = ?";
                    $valores[] = $data[$campo];
                }
            }

            if (empty($campos)) {
                return ['success' => false, 'error' => 'No hay campos para actualizar'];
            }

            $valores[] = $idApp;
            $sql = "UPDATE saas_apps_catalogo SET " . implode(', ', $campos) . " WHERE id_app = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($valores);

            return ['success' => true, 'message' => 'App actualizada correctamente'];
        } catch (PDOException $e) {
            error_log("[SuscripcionController] Error PDO en actualizarApp (id={$idApp}): " . $e->getMessage() . " - Código: " . $e->getCode());
            error_log("[SuscripcionController] Datos enviados: " . json_encode($data));
            return ['success' => false, 'error' => 'Error actualizando app', 'debug' => $e->getMessage()];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error general en actualizarApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error actualizando app', 'debug' => $e->getMessage()];
        }
    }

    /**
     * Eliminar app del catálogo
     */
    public static function eliminarApp(int $idApp): array
    {
        try {
            $db = Database::getMasterConnection();

            // Verificar que existe
            $stmt = $db->prepare("SELECT id_app, codigo, nombre, obligatoria FROM saas_apps_catalogo WHERE id_app = ?");
            $stmt->execute([$idApp]);
            $app = $stmt->fetch();
            
            if (!$app) {
                return ['success' => false, 'error' => 'App no encontrada'];
            }

            // No permitir eliminar apps obligatorias
            if ($app['obligatoria'] == 1) {
                return ['success' => false, 'error' => 'No se puede eliminar una app obligatoria'];
            }

            // Verificar si hay suscripciones usando esta app
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM saas_suscripcion_apps WHERE id_app = ?");
            $stmt->execute([$idApp]);
            $resultado = $stmt->fetch();
            
            if ($resultado['total'] > 0) {
                return [
                    'success' => false, 
                    'error' => "No se puede eliminar: esta app está en {$resultado['total']} suscripciones activas"
                ];
            }

            // Eliminar la app
            $stmt = $db->prepare("DELETE FROM saas_apps_catalogo WHERE id_app = ?");
            $stmt->execute([$idApp]);

            return ['success' => true, 'message' => "App \"{$app['nombre']}\" eliminada correctamente"];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en eliminarApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error eliminando app'];
        }
    }

    // =========================================================================
    // SUSCRIPCIONES (CABECERA)
    // =========================================================================

    /**
     * Verificar acceso de una empresa (para menu.php)
     * Retorna estado: ok, gracia, bloqueado, sin_suscripcion
     */
    public static function verificarAcceso(int $idEmpresa): array
    {
        try {
            $db = Database::getMasterConnection();
            self::asegurarSuscripcionEmpresa($idEmpresa);
            $hasSponsor = self::columnExists($db, 'saas_suscripcion', 'es_sponsor');
            $sponsorSelect = $hasSponsor ? 'COALESCE(s.es_sponsor, 0) as es_sponsor,' : '0 as es_sponsor,';

            $sql = "
                SELECT 
                    s.id_suscripcion,
                    s.periodo_inicio,
                    s.periodo_fin,
                    s.estado,
                    s.estado_pago,
                    s.dias_gracia,
                    s.fecha_vencimiento,
                    s.total,
                    {$sponsorSelect}
                    DATEDIFF(s.fecha_vencimiento, CURDATE()) as dias_restantes
                FROM saas_suscripcion s
                WHERE s.id_empresa = ?
                  AND CURDATE() BETWEEN s.periodo_inicio AND DATE_ADD(s.periodo_fin, INTERVAL s.dias_gracia DAY)
                  AND s.estado NOT IN ('cancelada')
                ORDER BY s.periodo_inicio DESC
                LIMIT 1
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$idEmpresa]);
            $suscripcion = $stmt->fetch();

            if (!$suscripcion) {
                return [
                    'success' => true,
                    'estado_acceso' => 'sin_suscripcion',
                    'mensaje' => 'No tiene suscripción activa',
                    'puede_acceder' => false
                ];
            }

            $diasRestantes = (int)$suscripcion['dias_restantes'];
            $estado = $suscripcion['estado'];
            $estadoPago = $suscripcion['estado_pago'];
            $esSponsor = (int)($suscripcion['es_sponsor'] ?? 0) === 1;

            // Cuenta sponsor: no mostrar banner de pago pendiente.
            if ($esSponsor) {
                return [
                    'success' => true,
                    'estado_acceso' => 'ok',
                    'mensaje' => 'Cuenta sponsor activa',
                    'puede_acceder' => true,
                    'mostrar_banner' => false,
                    'suscripcion' => $suscripcion
                ];
            }

            // Determinar estado de acceso
            if ($estado === self::ESTADO_VENCIDA) {
                return [
                    'success' => true,
                    'estado_acceso' => 'bloqueado',
                    'mensaje' => 'Suscripción vencida',
                    'puede_acceder' => false,
                    'suscripcion' => $suscripcion
                ];
            }

            if ($estado === self::ESTADO_GRACIA || ($estadoPago !== self::PAGO_PAGADO && $diasRestantes <= 0)) {
                return [
                    'success' => true,
                    'estado_acceso' => 'gracia',
                    'mensaje' => "Período de gracia: {$diasRestantes} días restantes",
                    'dias_restantes' => max(0, $diasRestantes),
                    'puede_acceder' => true,
                    'mostrar_banner' => true,
                    'suscripcion' => $suscripcion
                ];
            }

            if ($estadoPago !== self::PAGO_PAGADO) {
                return [
                    'success' => true,
                    'estado_acceso' => 'pendiente_pago',
                    'mensaje' => 'Pago pendiente',
                    'dias_restantes' => $diasRestantes,
                    'puede_acceder' => true,
                    'mostrar_banner' => $diasRestantes <= 5,
                    'suscripcion' => $suscripcion
                ];
            }

            return [
                'success' => true,
                'estado_acceso' => 'ok',
                'mensaje' => 'Suscripción activa',
                'puede_acceder' => true,
                'mostrar_banner' => false,
                'suscripcion' => $suscripcion
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en verificarAcceso: " . $e->getMessage());
            // En caso de error, permitir acceso para no bloquear el sistema
            return [
                'success' => false,
                'estado_acceso' => 'error',
                'mensaje' => 'Error verificando suscripción',
                'puede_acceder' => true,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtener suscripción activa de una empresa
     */
    public static function getSuscripcionActiva(int $idEmpresa): array
    {
        try {
            $db = Database::getMasterConnection();
            self::asegurarSuscripcionEmpresa($idEmpresa);

            $sql = "
                SELECT s.*,
                       e.empresa as nombre_empresa,
                       e.ruc
                FROM saas_suscripcion s
                LEFT JOIN empresa e ON e.id_empresa = s.id_empresa
                WHERE s.id_empresa = ?
                  AND s.estado IN ('activa', 'gracia')
                  AND CURDATE() BETWEEN s.periodo_inicio AND DATE_ADD(s.periodo_fin, INTERVAL s.dias_gracia DAY)
                ORDER BY s.periodo_inicio DESC
                LIMIT 1
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$idEmpresa]);
            $suscripcion = $stmt->fetch();

            if (!$suscripcion) {
                return ['success' => false, 'error' => 'No hay suscripción activa'];
            }

            // Obtener items (apps)
            $stmtItems = $db->prepare("
                SELECT sa.*, a.icono, a.icono_source, a.icono_svg, a.color, a.ruta_app, a.orden, a.obligatoria
                FROM saas_suscripcion_apps sa
                JOIN saas_apps_catalogo a ON a.id_app = sa.id_app
                WHERE sa.id_suscripcion = ?
                ORDER BY a.orden ASC
            ");
            $stmtItems->execute([$suscripcion['id_suscripcion']]);
            $suscripcion['apps'] = $stmtItems->fetchAll();

            return ['success' => true, 'data' => $suscripcion];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en getSuscripcionActiva: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo suscripción'];
        }
    }

    public static function getSuscripcionGestionEmpresa(int $idEmpresa): array
    {
        $activa = self::getSuscripcionActiva($idEmpresa);
        if (($activa['success'] ?? false) && !empty($activa['data'])) {
            if (is_array($activa['data'])) {
                $activa['data']['es_vigente'] = true;
            }
            return $activa;
        }

        try {
            $db = Database::getMasterConnection();
            self::asegurarSuscripcionEmpresa($idEmpresa);

            $sql = "
                SELECT s.*,
                       e.empresa as nombre_empresa,
                       e.ruc
                FROM saas_suscripcion s
                LEFT JOIN empresa e ON e.id_empresa = s.id_empresa
                WHERE s.id_empresa = ?
                  AND s.estado <> 'cancelada'
                ORDER BY s.periodo_inicio DESC, s.id_suscripcion DESC
                LIMIT 1
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$idEmpresa]);
            $suscripcion = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$suscripcion) {
                return ['success' => false, 'error' => 'No hay suscripción registrada'];
            }

            $stmtItems = $db->prepare("
                SELECT sa.*, a.icono, a.icono_source, a.icono_svg, a.color, a.ruta_app, a.orden, a.obligatoria
                FROM saas_suscripcion_apps sa
                JOIN saas_apps_catalogo a ON a.id_app = sa.id_app
                WHERE sa.id_suscripcion = ?
                ORDER BY a.orden ASC
            ");
            $stmtItems->execute([$suscripcion['id_suscripcion']]);
            $suscripcion['apps'] = $stmtItems->fetchAll();
            $suscripcion['es_vigente'] = false;

            return ['success' => true, 'data' => $suscripcion];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en getSuscripcionGestionEmpresa: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo suscripción para gestión'];
        }
    }

    /**
     * Listar suscripciones de una empresa
     */
    public static function listarSuscripciones(int $idEmpresa, array $params = []): array
    {
        try {
            $db = Database::getMasterConnection();

            $page = max(1, (int)($params['page'] ?? 1));
            $perPage = min(50, max(10, (int)($params['per_page'] ?? 12)));
            $offset = ($page - 1) * $perPage;

            // Contar total
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM saas_suscripcion WHERE id_empresa = ?");
            $stmt->execute([$idEmpresa]);
            $total = (int)$stmt->fetch()['total'];

            // Obtener suscripciones
            $sql = "
                SELECT s.*,
                       (SELECT COUNT(*) FROM saas_suscripcion_apps WHERE id_suscripcion = s.id_suscripcion) as cantidad_apps
                FROM saas_suscripcion s
                WHERE s.id_empresa = ?
                ORDER BY s.periodo_inicio DESC
                LIMIT ? OFFSET ?
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$idEmpresa, $perPage, $offset]);
            $suscripciones = $stmt->fetchAll();

            return [
                'success' => true,
                'data' => $suscripciones,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => ceil($total / $perPage)
                ]
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en listarSuscripciones: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error listando suscripciones'];
        }
    }

    /**
     * Crear nueva suscripción mensual
     */
    public static function crearSuscripcion(int $idEmpresa, array $data = []): array
    {
        try {
            $db = Database::getMasterConnection();

            // Determinar período
            $periodoInicio = $data['periodo_inicio'] ?? date('Y-m-01');
            $periodoFin = $data['periodo_fin'] ?? date('Y-m-t', strtotime($periodoInicio));
            $diasGracia = $data['dias_gracia'] ?? self::DIAS_GRACIA_DEFAULT;
            $fechaVencimiento = date('Y-m-d', strtotime("{$periodoFin} + {$diasGracia} days"));

            // Verificar que no exista suscripción para este período
            $stmt = $db->prepare("
                SELECT id_suscripcion FROM saas_suscripcion 
                WHERE id_empresa = ? AND periodo_inicio = ?
            ");
            $stmt->execute([$idEmpresa, $periodoInicio]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Ya existe suscripción para este período'];
            }

            // Generar número de factura
            $anio = date('Y', strtotime($periodoInicio));
            $mes = date('m', strtotime($periodoInicio));
            $stmt = $db->query("SELECT COALESCE(MAX(id_suscripcion), 0) + 1 as siguiente FROM saas_suscripcion");
            $siguiente = $stmt->fetch()['siguiente'];
            $nroFactura = sprintf("SAAS-%s-%s-%04d", $anio, $mes, $siguiente);

            // Crear cabecera
            $sql = "INSERT INTO saas_suscripcion 
                    (id_empresa, periodo_inicio, periodo_fin, nro_factura, fecha_vencimiento, 
                     estado, estado_pago, dias_gracia, created_by)
                    VALUES (?, ?, ?, ?, ?, 'activa', 'pendiente', ?, ?)";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $idEmpresa,
                $periodoInicio,
                $periodoFin,
                $nroFactura,
                $fechaVencimiento,
                $diasGracia,
                $data['created_by'] ?? null
            ]);

            $idSuscripcion = $db->lastInsertId();

            return [
                'success' => true,
                'data' => [
                    'id_suscripcion' => $idSuscripcion,
                    'nro_factura' => $nroFactura
                ],
                'message' => 'Suscripción creada correctamente'
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en crearSuscripcion: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error creando suscripción'];
        }
    }

    private static function ensureEmpresasSuprimidasTable(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS saas_empresas_suprimidas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                id_empresa INT NOT NULL,
                motivo VARCHAR(120) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_empresa (id_empresa),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private static function hasEmpresasSuprimidasTable(PDO $db): bool
    {
        $stmt = $db->query("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'saas_empresas_suprimidas'
            LIMIT 1
        ");
        return (bool)$stmt->fetchColumn();
    }

    public static function prepararEmpresasSuprimidasSchema(): void
    {
        $db = Database::getMasterConnection();
        self::ensureEmpresasSuprimidasTable($db);
    }

    private static function empresaSuprimida(PDO $db, int $idEmpresa): bool
    {
        if ($idEmpresa <= 0) {
            return false;
        }
        if (!self::hasEmpresasSuprimidasTable($db)) {
            if (!$db->inTransaction()) {
                self::ensureEmpresasSuprimidasTable($db);
            } else {
                return false;
            }
        }
        $stmt = $db->prepare("SELECT 1 FROM saas_empresas_suprimidas WHERE id_empresa = ? LIMIT 1");
        $stmt->execute([$idEmpresa]);
        return (bool)$stmt->fetchColumn();
    }

    public static function marcarEmpresaSuprimida(int $idEmpresa, string $motivo = 'supresion_manual'): void
    {
        if ($idEmpresa <= 0) {
            return;
        }
        $db = Database::getMasterConnection();
        if (!self::hasEmpresasSuprimidasTable($db)) {
            if ($db->inTransaction()) {
                throw new RuntimeException('La tabla saas_empresas_suprimidas no está preparada');
            }
            self::ensureEmpresasSuprimidasTable($db);
        }
        $stmt = $db->prepare("
            INSERT INTO saas_empresas_suprimidas (id_empresa, motivo)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)
        ");
        $stmt->execute([$idEmpresa, $motivo]);
    }

    public static function asegurarSuscripcionEmpresa(int $idEmpresa, array $data = []): array
    {
        try {
            if ($idEmpresa <= 0) {
                return ['success' => false, 'error' => 'Empresa inválida'];
            }

            $db = Database::getMasterConnection();
            if (self::empresaSuprimida($db, $idEmpresa)) {
                return [
                    'success' => true,
                    'created' => false,
                    'suppressed' => true,
                    'data' => ['id_empresa' => $idEmpresa],
                    'message' => 'La empresa está marcada como suprimida y no puede auto-suscribirse'
                ];
            }
            $periodoInicio = $data['periodo_inicio'] ?? date('Y-m-01');

            $vigente = self::obtenerSuscripcionVigenteRow($db, $idEmpresa);
            if ($vigente) {
                return [
                    'success' => true,
                    'created' => false,
                    'data' => ['id_suscripcion' => (int)$vigente['id_suscripcion']],
                    'message' => 'La empresa ya tiene suscripción vigente'
                ];
            }

            $mismoPeriodo = self::obtenerSuscripcionPeriodoRow($db, $idEmpresa, $periodoInicio);
            if ($mismoPeriodo) {
                return [
                    'success' => true,
                    'created' => false,
                    'data' => ['id_suscripcion' => (int)$mismoPeriodo['id_suscripcion']],
                    'message' => 'La empresa ya tiene suscripción para el período actual'
                ];
            }

            $ultima = self::obtenerUltimaSuscripcionRow($db, $idEmpresa);
            $resultado = $ultima
                ? self::renovarMes($idEmpresa, (int)$ultima['id_suscripcion'])
                : self::crearSuscripcion($idEmpresa, $data);

            if (($resultado['success'] ?? false) === true) {
                return [
                    'success' => true,
                    'created' => true,
                    'data' => $resultado['data'] ?? [],
                    'message' => $ultima
                        ? 'Suscripción renovada automáticamente'
                        : 'Suscripción creada automáticamente'
                ];
            }

            $mismoPeriodo = self::obtenerSuscripcionPeriodoRow($db, $idEmpresa, $periodoInicio);
            if ($mismoPeriodo) {
                return [
                    'success' => true,
                    'created' => false,
                    'data' => ['id_suscripcion' => (int)$mismoPeriodo['id_suscripcion']],
                    'message' => 'La empresa ya quedó suscripta en el período actual'
                ];
            }

            return $resultado;
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en asegurarSuscripcionEmpresa: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error asegurando suscripción automática'];
        }
    }

    public static function asegurarSuscripcionesEmpresasRegistradas(array $data = []): array
    {
        try {
            $db = Database::getMasterConnection();
            $whereActivo = self::columnExists($db, 'empresa', 'activo')
                ? "WHERE COALESCE(activo, 1) = 1"
                : '';

            $stmt = $db->query("
                SELECT id_empresa
                FROM empresa
                {$whereActivo}
                ORDER BY id_empresa ASC
            ");
            $empresas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $empresas = array_values(array_filter($empresas, static function ($idEmpresa) use ($db) {
                return !self::empresaSuprimida($db, (int)$idEmpresa);
            }));

            $creadas = 0;
            $existentes = 0;
            $errores = [];

            foreach ($empresas as $idEmpresa) {
                $resultado = self::asegurarSuscripcionEmpresa($idEmpresa, $data);
                if (($resultado['success'] ?? false) !== true) {
                    $errores[] = [
                        'id_empresa' => $idEmpresa,
                        'error' => $resultado['error'] ?? 'Error desconocido'
                    ];
                    continue;
                }
                if (!empty($resultado['created'])) {
                    $creadas++;
                } else {
                    $existentes++;
                }
            }

            return [
                'success' => true,
                'data' => [
                    'total_empresas' => count($empresas),
                    'suscripciones_creadas' => $creadas,
                    'ya_suscriptas' => $existentes,
                    'errores' => $errores
                ]
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en asegurarSuscripcionesEmpresasRegistradas: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error suscribiendo empresas registradas'];
        }
    }

    /**
     * Renovar suscripción (copiar items del mes anterior)
     */
    public static function renovarMes(int $idEmpresa, ?int $idSuscripcionAnterior = null): array
    {
        try {
            $db = Database::getMasterConnection();
            $db->beginTransaction();

            // Obtener suscripción anterior si no se especifica
            if (!$idSuscripcionAnterior) {
                $stmt = $db->prepare("
                    SELECT id_suscripcion FROM saas_suscripcion 
                    WHERE id_empresa = ? AND estado != 'cancelada'
                    ORDER BY periodo_inicio DESC LIMIT 1
                ");
                $stmt->execute([$idEmpresa]);
                $anterior = $stmt->fetch();
                $idSuscripcionAnterior = $anterior ? $anterior['id_suscripcion'] : null;
            }

            // Crear nueva suscripción
            $resultado = self::crearSuscripcion($idEmpresa);
            if (!$resultado['success']) {
                $db->rollBack();
                return $resultado;
            }

            $idNuevaSuscripcion = $resultado['data']['id_suscripcion'];

            // Copiar items del mes anterior
            if ($idSuscripcionAnterior) {
                $sql = "
                    INSERT INTO saas_suscripcion_apps 
                        (id_suscripcion, id_app, codigo_app, nombre_app, cantidad, precio_unitario, descuento, subtotal, activo)
                    SELECT 
                        ?,
                        sa.id_app,
                        a.codigo,
                        a.nombre,
                        sa.cantidad,
                        a.precio_mensual,
                        0,
                        sa.cantidad * a.precio_mensual,
                        1
                    FROM saas_suscripcion_apps sa
                    JOIN saas_apps_catalogo a ON a.id_app = sa.id_app
                    WHERE sa.id_suscripcion = ? AND sa.activo = 1
                ";

                $stmt = $db->prepare($sql);
                $stmt->execute([$idNuevaSuscripcion, $idSuscripcionAnterior]);
            }

            // Actualizar totales
            self::recalcularTotal($idNuevaSuscripcion);

            $db->commit();

            return [
                'success' => true,
                'data' => ['id_suscripcion' => $idNuevaSuscripcion],
                'message' => 'Suscripción renovada correctamente'
            ];
        } catch (Exception $e) {
            $db->rollBack();
            error_log("[SuscripcionController] Error en renovarMes: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error renovando suscripción'];
        }
    }

    // =========================================================================
    // ITEMS DE SUSCRIPCIÓN (APPS CONTRATADAS)
    // =========================================================================

    /**
     * Agregar app a una suscripción
     */
    public static function agregarApp(int $idSuscripcion, int $idApp, array $data = []): array
    {
        try {
            $db = Database::getMasterConnection();

            // Verificar suscripción
            $stmt = $db->prepare("SELECT id_suscripcion, estado FROM saas_suscripcion WHERE id_suscripcion = ?");
            $stmt->execute([$idSuscripcion]);
            $suscripcion = $stmt->fetch();
            if (!$suscripcion) {
                return ['success' => false, 'error' => 'Suscripción no encontrada'];
            }

            // Obtener app del catálogo
            $filtroDisponible = self::catalogoDisponibleFilter($db, 'a');
            $stmt = $db->prepare("SELECT a.* FROM saas_apps_catalogo a WHERE a.id_app = ? AND a.activo = 1{$filtroDisponible}");
            $stmt->execute([$idApp]);
            $app = $stmt->fetch();
            if (!$app) {
                return ['success' => false, 'error' => 'App no encontrada o inactiva'];
            }

            // Verificar que no esté ya agregada
            $stmt = $db->prepare("SELECT id FROM saas_suscripcion_apps WHERE id_suscripcion = ? AND id_app = ?");
            $stmt->execute([$idSuscripcion, $idApp]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Esta app ya está en la suscripción'];
            }

            $cantidad = max(1, (int)($data['cantidad'] ?? 1));
            $precioUnitario = $data['precio_unitario'] ?? $app['precio_mensual'];
            $descuento = $data['descuento'] ?? 0;
            $subtotal = ($cantidad * $precioUnitario) - $descuento;

            $sql = "INSERT INTO saas_suscripcion_apps 
                    (id_suscripcion, id_app, codigo_app, nombre_app, cantidad, precio_unitario, descuento, subtotal, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $idSuscripcion,
                $idApp,
                $app['codigo'],
                $app['nombre'],
                $cantidad,
                $precioUnitario,
                $descuento,
                $subtotal
            ]);

            // Recalcular total de suscripción
            self::recalcularTotal($idSuscripcion);

            return ['success' => true, 'message' => "App '{$app['nombre']}' agregada correctamente"];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en agregarApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error agregando app'];
        }
    }

    /**
     * Quitar app de una suscripción
     */
    public static function quitarApp(int $idSuscripcion, int $idApp): array
    {
        try {
            $db = Database::getMasterConnection();

            // Verificar si la app es obligatoria
            $stmtCheck = $db->prepare("SELECT obligatoria, nombre FROM saas_apps_catalogo WHERE id_app = ?");
            $stmtCheck->execute([$idApp]);
            $app = $stmtCheck->fetch();
            
            if ($app && $app['obligatoria']) {
                return ['success' => false, 'error' => 'No se puede quitar una aplicación obligatoria'];
            }

            $stmt = $db->prepare("DELETE FROM saas_suscripcion_apps WHERE id_suscripcion = ? AND id_app = ?");
            $stmt->execute([$idSuscripcion, $idApp]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'App no encontrada en la suscripción'];
            }

            // Recalcular total
            self::recalcularTotal($idSuscripcion);

            return ['success' => true, 'message' => 'App eliminada de la suscripción'];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en quitarApp: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error quitando app'];
        }
    }

    /**
     * Obtener apps de una empresa (para el menú)
     * Combina apps de suscripción + apps obligatorias
     */
    public static function getAppsEmpresa(int $idEmpresa, int $modulo = 0): array
    {
        try {
            $db = Database::getMasterConnection();
            self::asegurarSuscripcionEmpresa($idEmpresa);
            $hasCancelarAlCierre = false;
            try {
                $stCol = $db->prepare("
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                      AND table_name = 'saas_suscripcion_apps'
                      AND column_name = 'cancelar_al_cierre'
                    LIMIT 1
                ");
                $stCol->execute();
                $hasCancelarAlCierre = (bool)$stCol->fetchColumn();
            } catch (Throwable $e) {
                $hasCancelarAlCierre = false;
            }
            $cancelarFilter = $hasCancelarAlCierre ? " AND COALESCE(sa.cancelar_al_cierre, 0) = 0 " : "";

            // Obtener apps de suscripción activa (excluyendo apps obligatorias)
            $sql = "
                SELECT DISTINCT
                    a.id_app,
                    a.codigo,
                    a.nombre as label,
                    a.ruta_app as app,
                    a.icono,
                    a.icono_source,
                    a.icono_svg,
                    a.color as color3d,
                    a.permiso_base,
                    a.requiere_modulo,
                    a.orden,
                    a.obligatoria
                FROM saas_suscripcion s
                INNER JOIN saas_suscripcion_apps sa ON sa.id_suscripcion = s.id_suscripcion
                INNER JOIN saas_apps_catalogo a ON a.id_app = sa.id_app
                WHERE s.id_empresa = ?
                  AND s.estado IN ('activa', 'gracia')
                  AND CURDATE() BETWEEN s.periodo_inicio AND DATE_ADD(s.periodo_fin, INTERVAL s.dias_gracia DAY)
                  AND sa.activo = 1
                  {$cancelarFilter}
                  AND a.activo = 1
                  AND COALESCE(a.obligatoria, 0) = 0
                  AND (a.requiere_modulo IS NULL OR a.requiere_modulo = ?)

                ORDER BY orden ASC
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$idEmpresa, $modulo]);
            $apps = $stmt->fetchAll();

            if (!empty($apps)) {
                return ['success' => true, 'data' => $apps];
            }

            // Fallback para gestión/entornos internos: usar la última suscripción no cancelada
            // cuando no exista una vigente por fecha actual (excluyendo apps obligatorias)
            $sqlFallback = "
                SELECT DISTINCT
                    a.id_app,
                    a.codigo,
                    a.nombre as label,
                    a.ruta_app as app,
                    a.icono,
                    a.icono_source,
                    a.icono_svg,
                    a.color as color3d,
                    a.permiso_base,
                    a.requiere_modulo,
                    a.orden,
                    a.obligatoria
                FROM saas_suscripcion s
                INNER JOIN saas_suscripcion_apps sa ON sa.id_suscripcion = s.id_suscripcion
                INNER JOIN saas_apps_catalogo a ON a.id_app = sa.id_app
                WHERE s.id_empresa = ?
                  AND s.estado <> 'cancelada'
                  AND sa.activo = 1
                  {$cancelarFilter}
                  AND a.activo = 1
                  AND COALESCE(a.obligatoria, 0) = 0
                  AND (a.requiere_modulo IS NULL OR a.requiere_modulo = ?)
                  AND s.id_suscripcion = (
                      SELECT s2.id_suscripcion
                      FROM saas_suscripcion s2
                      WHERE s2.id_empresa = ?
                        AND s2.estado <> 'cancelada'
                      ORDER BY s2.periodo_inicio DESC, s2.id_suscripcion DESC
                      LIMIT 1
                  )

                ORDER BY orden ASC
            ";

            $stmtFallback = $db->prepare($sqlFallback);
            $stmtFallback->execute([$idEmpresa, $modulo, $idEmpresa]);
            $apps = $stmtFallback->fetchAll();

            // Si aún no hay apps, retornar array vacío (sin apps obligatorias)
            return ['success' => true, 'data' => $apps];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en getAppsEmpresa: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo apps', 'data' => []];
        }
    }

    // =========================================================================
    // PAGOS
    // =========================================================================

    /**
     * Marcar suscripción como pagada
     */
    public static function marcarPagado(int $idSuscripcion, array $datosPago = []): array
    {
        try {
            $db = Database::getMasterConnection();

            $sql = "UPDATE saas_suscripcion SET 
                    estado_pago = 'pagado',
                    estado = 'activa',
                    fecha_pago = NOW(),
                    metodo_pago = ?,
                    ueno_payment_id = ?,
                    comprobante_pago = ?
                    WHERE id_suscripcion = ?";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $datosPago['metodo_pago'] ?? 'manual',
                $datosPago['ueno_payment_id'] ?? null,
                $datosPago['comprobante_pago'] ?? null,
                $idSuscripcion
            ]);

            // Registrar en historial
            if ($stmt->rowCount() > 0) {
                $stmtHist = $db->prepare("
                    INSERT INTO saas_pagos_historial 
                        (id_suscripcion, monto, metodo_pago, referencia, gateway, gateway_response)
                    SELECT s.total, ?, ?, 'ueno', ?
                    FROM saas_suscripcion s WHERE s.id_suscripcion = ?
                ");
                $stmtHist->execute([
                    $datosPago['metodo_pago'] ?? 'manual',
                    $datosPago['ueno_payment_id'] ?? $datosPago['comprobante_pago'] ?? null,
                    $datosPago['gateway_response'] ?? null,
                    $idSuscripcion
                ]);
            }

            return ['success' => true, 'message' => 'Pago registrado correctamente'];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en marcarPagado: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error registrando pago'];
        }
    }

    /**
     * Marcar pago por payment_id de Ueno (para webhook)
     */
    public static function marcarPagadoPorUeno(string $uenoPaymentId, array $datosPago = []): array
    {
        try {
            $db = Database::getMasterConnection();

            // Buscar suscripción por payment_id
            $stmt = $db->prepare("SELECT id_suscripcion FROM saas_suscripcion WHERE ueno_payment_id = ?");
            $stmt->execute([$uenoPaymentId]);
            $suscripcion = $stmt->fetch();

            if (!$suscripcion) {
                return ['success' => false, 'error' => 'Suscripción no encontrada'];
            }

            return self::marcarPagado($suscripcion['id_suscripcion'], $datosPago);
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en marcarPagadoPorUeno: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error procesando pago Ueno'];
        }
    }

    /**
     * Generar link de pago Ueno para una suscripción
     */
    public static function generarPagoUeno(int $idSuscripcion): array
    {
        try {
            $db = Database::getMasterConnection();

            // Obtener datos de suscripción
            $stmt = $db->prepare("
                SELECT s.*, e.empresa, e.ruc, e.email
                FROM saas_suscripcion s
                JOIN empresa e ON e.id_empresa = s.id_empresa
                WHERE s.id_suscripcion = ?
            ");
            $stmt->execute([$idSuscripcion]);
            $suscripcion = $stmt->fetch();

            if (!$suscripcion) {
                return ['success' => false, 'error' => 'Suscripción no encontrada'];
            }

            // Generar ID único para el pago
            $paymentId = 'SAAS_' . $idSuscripcion . '_' . time();

            // Guardar payment_id en la suscripción
            $stmt = $db->prepare("UPDATE saas_suscripcion SET ueno_payment_id = ? WHERE id_suscripcion = ?");
            $stmt->execute([$paymentId, $idSuscripcion]);

            // Preparar datos para Ueno (estructura similar a ueno_create_payment.php)
            $datosUeno = [
                'amount' => (int)$suscripcion['total'],
                'currency' => 'PYG',
                'description' => "Suscripción SistemaX - {$suscripcion['nro_factura']}",
                'reference' => $paymentId,
                'customer' => [
                    'name' => $suscripcion['empresa'],
                    'document' => $suscripcion['ruc'],
                    'email' => $suscripcion['email'] ?? ''
                ],
                'metadata' => [
                    'tipo' => 'suscripcion_saas',
                    'id_suscripcion' => $idSuscripcion,
                    'id_empresa' => $suscripcion['id_empresa'],
                    'periodo' => $suscripcion['periodo_inicio'] . ' a ' . $suscripcion['periodo_fin']
                ]
            ];

            return [
                'success' => true,
                'data' => [
                    'payment_id' => $paymentId,
                    'datos_ueno' => $datosUeno,
                    'suscripcion' => $suscripcion
                ]
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en generarPagoUeno: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error generando pago'];
        }
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    /**
     * Recalcular totales de una suscripción
     */
    private static function recalcularTotal(int $idSuscripcion): void
    {
        $db = Database::getMasterConnection();
        $hasSponsor = self::columnExists($db, 'saas_suscripcion', 'es_sponsor');

        if ($hasSponsor) {
            $sql = "
                UPDATE saas_suscripcion s
                SET
                    s.subtotal = CASE
                        WHEN COALESCE(s.es_sponsor, 0) = 1 THEN 0
                        ELSE COALESCE((
                            SELECT SUM(subtotal) FROM saas_suscripcion_apps WHERE id_suscripcion = s.id_suscripcion
                        ), 0)
                    END,
                    s.total = CASE
                        WHEN COALESCE(s.es_sponsor, 0) = 1 THEN 0
                        ELSE GREATEST(0, COALESCE((
                            SELECT SUM(subtotal) FROM saas_suscripcion_apps WHERE id_suscripcion = s.id_suscripcion
                        ), 0) - COALESCE(s.descuento, 0))
                    END,
                    s.estado_pago = CASE
                        WHEN COALESCE(s.es_sponsor, 0) = 1 THEN 'pagado'
                        ELSE s.estado_pago
                    END
                WHERE s.id_suscripcion = ?
            ";
        } else {
            $sql = "
                UPDATE saas_suscripcion s
                SET
                    s.subtotal = COALESCE((
                        SELECT SUM(subtotal) FROM saas_suscripcion_apps WHERE id_suscripcion = s.id_suscripcion
                    ), 0),
                    s.total = GREATEST(0, COALESCE((
                        SELECT SUM(subtotal) FROM saas_suscripcion_apps WHERE id_suscripcion = s.id_suscripcion
                    ), 0) - COALESCE(s.descuento, 0))
                WHERE s.id_suscripcion = ?
            ";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([$idSuscripcion]);
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        try {
            $stmt = $db->prepare("
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
                LIMIT 1
            ");
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Actualizar estados de suscripciones (para cron)
     * Marca como 'gracia' o 'vencida' según corresponda
     */
    public static function actualizarEstados(): array
    {
        try {
            $db = Database::getMasterConnection();
            $actualizados = 0;

            // Marcar como 'gracia' las que pasaron el periodo_fin pero no el vencimiento
            $sql = "
                UPDATE saas_suscripcion 
                SET estado = 'gracia'
                WHERE estado = 'activa'
                  AND estado_pago != 'pagado'
                  AND CURDATE() > periodo_fin
                  AND CURDATE() <= fecha_vencimiento
            ";
            $stmt = $db->query($sql);
            $actualizados += $stmt->rowCount();

            // Marcar como 'vencida' las que pasaron el vencimiento
            $sql = "
                UPDATE saas_suscripcion 
                SET estado = 'vencida'
                WHERE estado IN ('activa', 'gracia')
                  AND estado_pago != 'pagado'
                  AND CURDATE() > fecha_vencimiento
            ";
            $stmt = $db->query($sql);
            $actualizados += $stmt->rowCount();

            return [
                'success' => true,
                'message' => "Estados actualizados: {$actualizados} suscripciones"
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en actualizarEstados: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error actualizando estados'];
        }
    }

    /**
     * Obtener suscripciones para renovar (cron del día 1)
     */
    public static function getSuscripcionesParaRenovar(): array
    {
        try {
            $db = Database::getMasterConnection();

            // Obtener mes anterior
            $mesAnteriorInicio = date('Y-m-01', strtotime('-1 month'));

            $sql = "
                SELECT DISTINCT s.id_empresa, s.id_suscripcion
                FROM saas_suscripcion s
                WHERE s.periodo_inicio = ?
                  AND s.estado != 'cancelada'
                  AND NOT EXISTS (
                      SELECT 1 FROM saas_suscripcion s2 
                      WHERE s2.id_empresa = s.id_empresa 
                        AND s2.periodo_inicio = DATE_FORMAT(CURDATE(), '%Y-%m-01')
                  )
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$mesAnteriorInicio]);

            return ['success' => true, 'data' => $stmt->fetchAll()];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en getSuscripcionesParaRenovar: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error obteniendo suscripciones'];
        }
    }

    /**
     * Listar todas las suscripciones (admin)
     */
    public static function listarTodas(array $params = []): array
    {
        try {
            $db = Database::getMasterConnection();

            $fetchAll = (int)($params['all'] ?? 0) === 1;
            $page = max(1, (int)($params['page'] ?? 1));
            $perPage = min(100, max(10, (int)($params['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            $where = [];
            $bindings = [];

            if (!empty($params['estado'])) {
                $where[] = "LOWER(TRIM(COALESCE(s.estado, ''))) = ?";
                $bindings[] = strtolower(trim((string)$params['estado']));
            }

            $estadoRegistro = strtolower(trim((string)($params['estado_registro'] ?? '')));
            if ($estadoRegistro === 'activo') {
                $where[] = "LOWER(TRIM(COALESCE(s.estado, ''))) <> 'cancelada'";
            } elseif ($estadoRegistro === 'anulado') {
                $where[] = "LOWER(TRIM(COALESCE(s.estado, ''))) = 'cancelada'";
            }

            if (!empty($params['estado_pago'])) {
                $where[] = "s.estado_pago = ?";
                $bindings[] = $params['estado_pago'];
            }

            if (!empty($params['id_empresa'])) {
                $where[] = "s.id_empresa = ?";
                $bindings[] = $params['id_empresa'];
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            // Contar
            $countSql = "SELECT COUNT(*) as total FROM saas_suscripcion s {$whereClause}";
            $stmt = $db->prepare($countSql);
            $stmt->execute($bindings);
            $total = (int)$stmt->fetch()['total'];

            // Obtener
            $sql = "
                SELECT s.*, 
                       e.empresa as nombre_empresa,
                       e.ruc,
                       e.dbase,
                       (SELECT COUNT(*) FROM saas_suscripcion_apps WHERE id_suscripcion = s.id_suscripcion) as cantidad_apps
                FROM saas_suscripcion s
                LEFT JOIN empresa e ON e.id_empresa = s.id_empresa
                {$whereClause}
                ORDER BY s.created_at DESC
            ";
            if (!$fetchAll) {
                $sql .= " LIMIT ? OFFSET ?";
                $bindings[] = $perPage;
                $bindings[] = $offset;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($bindings);

            $effectivePerPage = $fetchAll ? max($total, 1) : $perPage;
            $effectivePage = $fetchAll ? 1 : $page;
            $effectiveTotalPages = $fetchAll ? 1 : ceil($total / $perPage);

            return [
                'success' => true,
                'data' => $stmt->fetchAll(),
                'pagination' => [
                    'total' => $total,
                    'page' => $effectivePage,
                    'per_page' => $effectivePerPage,
                    'total_pages' => $effectiveTotalPages
                ]
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en listarTodas: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error listando suscripciones'];
        }
    }

    public static function toggleAnulacionSuscripcion(int $idSuscripcion, bool $anular): array
    {
        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("
                SELECT id_suscripcion, estado
                FROM saas_suscripcion
                WHERE id_suscripcion = ?
                LIMIT 1
            ");
            $stmt->execute([$idSuscripcion]);
            $suscripcion = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$suscripcion) {
                return ['success' => false, 'error' => 'Suscripción no encontrada'];
            }

            $estadoActual = strtolower(trim((string)($suscripcion['estado'] ?? '')));
            if ($anular) {
                if ($estadoActual === 'cancelada') {
                    return ['success' => false, 'error' => 'La suscripción ya está anulada'];
                }
                $nuevoEstado = 'cancelada';
            } else {
                if ($estadoActual !== 'cancelada') {
                    return ['success' => false, 'error' => 'La suscripción no está anulada'];
                }
                $nuevoEstado = 'activa';
            }

            $upd = $db->prepare("UPDATE saas_suscripcion SET estado = ? WHERE id_suscripcion = ?");
            $upd->execute([$nuevoEstado, $idSuscripcion]);

            $stmt = $db->prepare("
                SELECT s.*, 
                       e.empresa AS nombre_empresa,
                       e.ruc,
                       (SELECT COUNT(*) FROM saas_suscripcion_apps WHERE id_suscripcion = s.id_suscripcion) AS cantidad_apps
                FROM saas_suscripcion s
                LEFT JOIN empresa e ON e.id_empresa = s.id_empresa
                WHERE s.id_suscripcion = ?
                LIMIT 1
            ");
            $stmt->execute([$idSuscripcion]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'message' => $anular ? 'Suscripción anulada' : 'Suscripción desanulada',
                'data' => $row ?: ['id_suscripcion' => $idSuscripcion, 'estado' => $nuevoEstado]
            ];
        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en toggleAnulacionSuscripcion: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error actualizando estado de suscripción'];
        }
    }

    /**
     * Descubrir automáticamente todas las apps del proyecto
     * Escanea el directorio /public para encontrar apps
     */
    private static function discoverProjectApps(): array
    {
        $apps = [];
        $publicDir = dirname(__DIR__) . '/../../public';

        if (!is_dir($publicDir)) {
            return $apps;
        }

        // Directorios a ignorar
        $ignoreDirs = ['_lib', 'api', 'assets', 'menu', 'lang', 'shared', 'setup', 'consultas',
                       'db_migrador', 'helpwire', 'i18n_admin', 'interpretar_codigo',
                       'soporte_remoto', 'soporte_shadow', 'video_assets'];

        // Escanear carpetas en /public
        $iterator = scandir($publicDir);
        foreach ($iterator as $dir) {
            // Saltar directorios especiales
            if (in_array($dir, ['.', '..']) || in_array($dir, $ignoreDirs)) {
                continue;
            }

            $fullPath = $publicDir . '/' . $dir;
            if (!is_dir($fullPath)) {
                continue;
            }

            // Verificar si tiene index.php
            if (!file_exists($fullPath . '/index.php')) {
                continue;
            }

            // Intentar extraer información de la app
            $codigo = $dir;
            $nombre = self::formatearNombreApp($dir);

            $apps[] = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'ruta_app' => 'public/' . $dir . '/index.php',
                'icono' => 'app-window',
                'color' => 'slate',
                'precio_mensual' => 0,
                'obligatoria' => 0,
                'modulo' => 'General',
                'negocio' => null,
                'orden' => 100
            ];
        }

        return $apps;
    }

    /**
     * Formatear nombre de app desde código
     * Convierte "estacion-tanques" a "Estación Tanques"
     */
    private static function formatearNombreApp(string $codigo): string
    {
        // Reemplazar guiones con espacios
        $nombre = str_replace('-', ' ', $codigo);
        $nombre = str_replace('_', ' ', $nombre);

        // Capitalizar cada palabra
        $nombre = ucwords($nombre);

        // Algunos casos especiales
        $replacements = [
            'db' => 'DB',
            'sifen' => 'SIFEN',
            'fe' => 'FE',
            'pos' => 'POS',
            'api' => 'API',
        ];

        foreach ($replacements as $word => $replacement) {
            $nombre = preg_replace('/\b' . $word . '\b/i', $replacement, $nombre);
        }

        return trim($nombre);
    }

    /**
     * Sincronizar todas las apps del catálogo
     * Detecta y agrega TODAS las apps creadas en el proyecto sin filtro
     */
    public static function ensureAllCatalogApps(): array
    {
        $results = [
            'success' => true,
            'synced' => [],
            'errors' => [],
            'total' => 0,
            'discovered' => 0
        ];

        try {
            $db = Database::getMasterConnection();

            // Descubrir todas las apps del proyecto
            $discoveredApps = self::discoverProjectApps();
            $results['discovered'] = count($discoveredApps);

            // Sincronizar apps descubiertas
            foreach ($discoveredApps as $appData) {
                try {
                    // Verificar si existe
                    $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ?");
                    $stmt->execute([$appData['codigo']]);
                    $existe = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existe) {
                        $results['synced'][] = [
                            'codigo' => $appData['codigo'],
                            'nombre' => $appData['nombre'],
                            'status' => 'exists'
                        ];
                    } else {
                        // Crear si no existe
                        $insStmt = $db->prepare("
                            INSERT INTO saas_apps_catalogo
                            (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)
                        ");
                        $insStmt->execute([
                            $appData['codigo'],
                            $appData['nombre'],
                            '',
                            $appData['ruta_app'],
                            $appData['icono'],
                            $appData['color'],
                            $appData['precio_mensual'],
                            $appData['obligatoria'],
                            'app_' . $appData['codigo'],
                            $appData['orden'],
                            $appData['modulo'],
                            $appData['negocio']
                        ]);
                        $results['synced'][] = [
                            'codigo' => $appData['codigo'],
                            'nombre' => $appData['nombre'],
                            'status' => 'created'
                        ];
                    }
                    $results['total']++;
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'codigo' => $appData['codigo'],
                        'error' => $e->getMessage()
                    ];
                }
            }

        } catch (Exception $e) {
            error_log("[SuscripcionController] Error en ensureAllCatalogApps: " . $e->getMessage());
            $results['success'] = false;
            $results['errors'][] = [
                'global' => $e->getMessage()
            ];
        }

        return $results;
    }
}
