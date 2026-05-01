<?php
/**
 * Productos API - Catálogos auxiliares CRUD
 * GET/POST: ?tabla=grupos|marcas|modelos|colores|medidas|referencias&action=list|create|update|delete
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';
if (!function_exists('posProductosMercaderiasBypassAllowed')) {
    function posProductosMercaderiasBypassAllowed(): bool
    {
        try {
            $idLogin = (int)($_SESSION['id_login'] ?? $_SESSION['user_id'] ?? 0);
            $idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
            if ($idLogin <= 0 || $idEmpresa <= 0) {
                return false;
            }

            $pdo = Database::getMasterConnection();
            $masterDb = Database::getMasterDbName();
            $stmt = $pdo->prepare("
                SELECT priv_admin, role
                FROM {$masterDb}.sec_users
                WHERE id_login = :id_login
                  AND id_empresa = :id_empresa
                LIMIT 1
            ");
            $stmt->execute([':id_login' => $idLogin, ':id_empresa' => $idEmpresa]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                return false;
            }
            $privAdmin = strtoupper(trim((string)($row['priv_admin'] ?? 'N')));
            $role = strtoupper(trim((string)($row['role'] ?? '')));
            return in_array($privAdmin, ['Y', 'S', '1', 'TRUE'], true)
                || in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
                || strpos($role, 'ADMIN') !== false
                || strpos($role, 'SUPERVIS') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!posProductosMercaderiasBypassAllowed()) {
    Permission::requireAccess('app_grid_mercaderias');
}

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

// Mapeo de tablas permitidas
function ensureProductosCatalogTable(PDO $pdo, string $db, string $table): void
{
    if (!in_array($table, ['producto_aplic_marca_cod_conversion', 'producto_aplic_marca_aplicacion', 'producto_aplic_anio', 'producto_aplic_modelo', 'producto_aplic_motor', 'producto_aplic_codigo_motor'], true)) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`{$table}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(120) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_nombre` (`nombre`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$tablaMap = [
    'grupos'      => ['table' => 'mercaderia_grupo',      'field' => 'grupo'],
    'marcas'      => ['table' => 'mercaderia_marca',      'field' => 'marca'],
    'modelos'     => ['table' => 'mercaderia_modelo',     'field' => 'modelo'],
    'colores'     => ['table' => 'mercaderia_color',      'field' => 'color'],
    'medidas'     => ['table' => 'mercaderia_medida',     'field' => 'medida'],
    'referencias' => ['table' => 'mercaderia_referencia', 'field' => 'referencia'],
    'precios'     => ['table' => 'tipo_precio',           'field' => 'tipo'],
    'marcas_cod_conversion' => ['table' => 'producto_aplic_marca_cod_conversion', 'field' => 'nombre'],
    'marcas_aplicacion' => ['table' => 'producto_aplic_marca_aplicacion', 'field' => 'nombre'],
    'anios_aplicacion' => ['table' => 'producto_aplic_anio', 'field' => 'nombre'],
    'modelos_aplicacion' => ['table' => 'producto_aplic_modelo', 'field' => 'nombre'],
    'motores_aplicacion' => ['table' => 'producto_aplic_motor', 'field' => 'nombre'],
    'codigos_motor_aplicacion' => ['table' => 'producto_aplic_codigo_motor', 'field' => 'nombre'],
];

$action = $_GET['action'] ?? '';

// Para POST, leer body JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? $action;
    if (isset($input['id_empresa'])) {
        $id_empresa = (int)$input['id_empresa'];
    }
}

$tablaKey = $_GET['tabla'] ?? $_POST['tabla'] ?? ($input['tabla'] ?? '');
$config = $tablaMap[$tablaKey] ?? null;

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $resolveIdColumn = function(string $fullTable) use ($pdo): string {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM {$fullTable}")->fetchAll(PDO::FETCH_ASSOC);
            if (!$cols) return 'id';
            foreach ($cols as $col) {
                if (strtolower((string)($col['Field'] ?? '')) === 'id') return (string)$col['Field'];
            }
            foreach ($cols as $col) {
                if (strtoupper((string)($col['Key'] ?? '')) === 'PRI') return (string)$col['Field'];
            }
            return (string)($cols[0]['Field'] ?? 'id');
        } catch (Throwable $e) {
            return 'id';
        }
    };

    $resolveNameColumn = function(string $fullTable, string $preferred) use ($pdo): string {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM {$fullTable}")->fetchAll(PDO::FETCH_ASSOC);
            if (!$cols) return $preferred;

            $names = array_map(fn($c) => strtolower((string)($c['Field'] ?? '')), $cols);
            if (in_array(strtolower($preferred), $names, true)) return $preferred;

            $candidates = ['nombre', 'descripcion', 'detalle', 'texto', 'label', 'titulo', 'color', 'medida', 'marca', 'modelo', 'grupo', 'referencia'];
            foreach ($candidates as $cand) {
                if (in_array($cand, $names, true)) {
                    foreach ($cols as $col) {
                        if (strtolower((string)$col['Field']) === $cand) return (string)$col['Field'];
                    }
                }
            }

            $idCol = null;
            foreach ($cols as $col) {
                if (strtoupper((string)($col['Key'] ?? '')) === 'PRI') {
                    $idCol = strtolower((string)$col['Field']);
                    break;
                }
            }
            foreach ($cols as $col) {
                $f = (string)($col['Field'] ?? '');
                $fl = strtolower($f);
                if ($fl !== 'id' && $fl !== $idCol) return $f;
            }
            return $preferred;
        } catch (Throwable $e) {
            return $preferred;
        }
    };

    $tableHasColumn = function(string $table, string $column) use ($pdo, $db): bool {
        static $cache = [];
        $key = $db . '.' . $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl AND column_name = :col LIMIT 1");
        $stmt->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    };

    $fetchCatalogRows = function(string $key, string $search = '') use ($tablaMap, $pdo, $db, $resolveIdColumn, $resolveNameColumn): array {
        $cfg = $tablaMap[$key] ?? null;
        if (!$cfg) {
            return [];
        }
        ensureProductosCatalogTable($pdo, $db, $cfg['table']);
        $table = "{$db}.{$cfg['table']}";
        $field = $resolveNameColumn($table, $cfg['field']);
        $idCol = $resolveIdColumn($table);
        $sql = "SELECT {$idCol} AS id, {$field} AS nombre, {$field} AS {$field} FROM {$table}";
        $params = [];
        if ($search !== '') {
            $sql .= " WHERE {$field} LIKE :s";
            $params[':s'] = "%{$search}%";
        }
        $sql .= " ORDER BY {$field}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    };

    switch ($action) {
        case 'bootstrap':
            $keys = [
                'grupos',
                'marcas',
                'modelos',
                'colores',
                'medidas',
                'referencias',
                'marcas_cod_conversion',
                'marcas_aplicacion',
                'anios_aplicacion',
                'modelos_aplicacion',
                'motores_aplicacion',
                'codigos_motor_aplicacion',
            ];
            $data = [];
            foreach ($keys as $key) {
                $data[$key] = $fetchCatalogRows($key);
            }

            try {
                $sucursalCols = ['id_sucursal', 'sucursal'];
                if ($tableHasColumn('sucursales', 'estado')) {
                    $sucursalCols[] = 'estado';
                }
                if ($tableHasColumn('sucursales', 'activo')) {
                    $sucursalCols[] = 'activo';
                }
                $stmtSuc = $pdo->query("SELECT " . implode(', ', $sucursalCols) . " FROM {$db}.sucursales ORDER BY id_sucursal");
                $data['sucursales'] = $stmtSuc->fetchAll() ?: [];
            } catch (Throwable $e) {
                $data['sucursales'] = [];
            }

            try {
                $stmtTp = $pdo->query("SELECT id, tipo, porcentaje, moneda, descuento FROM {$db}.tipo_precio ORDER BY id");
                $data['tipos_precio'] = $stmtTp->fetchAll() ?: [];
            } catch (Throwable $e) {
                $data['tipos_precio'] = [];
            }

            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'seed_defaults':
            Permission::requirePermission('app_grid_mercaderias', 'priv_insert');
            $defaults = [
                'grupos' => [
                    'Alimentos', 'Bebidas', 'Limpieza', 'Higiene Personal', 'Farmacia',
                    'Electrónica', 'Ferretería', 'Librería', 'Ropa y Calzados', 'Repuestos',
                    'Servicios', 'Papelería'
                ],
                'marcas' => [
                    'Genérica', 'Samsung', 'LG', 'Philips', 'Unilever', 'Nestle',
                    'Coca-Cola', 'Pepsi', 'P&G', 'BIC', 'Nike', 'Adidas', 'Toyota', 'Shell'
                ],
                'modelos' => [
                    'Estándar', 'Premium', 'Económico', 'Pro', 'Mini', 'Max',
                    'Hogar', 'Industrial', '2024', '2025'
                ],
                'colores' => [
                    'Negro', 'Blanco', 'Rojo', 'Azul', 'Verde', 'Amarillo',
                    'Gris', 'Plateado', 'Dorado', 'Transparente'
                ],
                'medidas' => [
                    'Unidad', 'Caja', 'Paquete', 'Docena', 'Par', 'Kg',
                    'Gramo', 'Litro', 'Mililitro', 'Metro', 'Centímetro'
                ],
                'referencias' => [
                    'General', 'Mayorista', 'Minorista', 'Promoción', 'Temporal',
                    'Alta rotación', 'Baja rotación', 'Servicio', 'Repuesto', 'Consumo interno'
                ],
            ];

            $inserted = 0;
            foreach ($defaults as $key => $items) {
                $cfg = $tablaMap[$key];
                $tbl = "{$db}.{$cfg['table']}";
                $fld = $resolveNameColumn($tbl, $cfg['field']);
                $idCol = $resolveIdColumn($tbl);

                $stmtExists = $pdo->prepare("SELECT {$idCol} FROM {$tbl} WHERE LOWER(TRIM({$fld})) = LOWER(TRIM(:n)) LIMIT 1");
                $stmtInsert = $pdo->prepare("INSERT INTO {$tbl} ({$fld}) VALUES (:n)");

                foreach ($items as $name) {
                    $name = trim($name);
                    if ($name === '') continue;
                    $stmtExists->execute([':n' => $name]);
                    if (!$stmtExists->fetch()) {
                        $stmtInsert->execute([':n' => $name]);
                        $inserted++;
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Datos base cargados. Nuevos registros: {$inserted}",
                'inserted' => $inserted
            ]);
            break;

        case 'list':
            if (!$config) {
                echo json_encode(['success' => false, 'error' => 'Tabla no válida. Opciones: ' . implode(', ', array_keys($tablaMap))]);
                exit;
            }
            $search = trim($_GET['search'] ?? '');
            echo json_encode(['success' => true, 'data' => $fetchCatalogRows($tablaKey, $search)]);
            break;

        case 'create':
            if (!$config) {
                echo json_encode(['success' => false, 'error' => 'Tabla no válida. Opciones: ' . implode(', ', array_keys($tablaMap))]);
                exit;
            }
            ensureProductosCatalogTable($pdo, $db, $config['table']);
            $table = "{$db}.{$config['table']}";
            $field = $resolveNameColumn($table, $config['field']);
            $idCol = $resolveIdColumn($table);
            $nombre = trim($input['nombre'] ?? '');
            if (empty($nombre)) {
                echo json_encode(['success' => false, 'error' => 'Nombre requerido']);
                exit;
            }
            // Verificar duplicado
            $stmt = $pdo->prepare("SELECT {$idCol} FROM {$table} WHERE {$field} = :n");
            $stmt->execute([':n' => $nombre]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Ya existe un registro con ese nombre']);
                exit;
            }
            $pdo->prepare("INSERT INTO {$table} ({$field}) VALUES (:n)")->execute([':n' => $nombre]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Creado correctamente']);
            break;

        case 'update':
            if (!$config) {
                echo json_encode(['success' => false, 'error' => 'Tabla no válida. Opciones: ' . implode(', ', array_keys($tablaMap))]);
                exit;
            }
            ensureProductosCatalogTable($pdo, $db, $config['table']);
            $table = "{$db}.{$config['table']}";
            $field = $resolveNameColumn($table, $config['field']);
            $idCol = $resolveIdColumn($table);
            $id = (int)($input['id'] ?? 0);
            $nombre = trim($input['nombre'] ?? '');
            if ($id <= 0 || empty($nombre)) {
                echo json_encode(['success' => false, 'error' => 'ID y nombre requeridos']);
                exit;
            }
            // Verificar duplicado excluyendo el actual
            $stmt = $pdo->prepare("SELECT {$idCol} FROM {$table} WHERE {$field} = :n AND {$idCol} != :id");
            $stmt->execute([':n' => $nombre, ':id' => $id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Ya existe otro registro con ese nombre']);
                exit;
            }
            $pdo->prepare("UPDATE {$table} SET {$field} = :n WHERE {$idCol} = :id")->execute([':n' => $nombre, ':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Actualizado correctamente']);
            break;

        case 'delete':
            if (!$config) {
                echo json_encode(['success' => false, 'error' => 'Tabla no válida. Opciones: ' . implode(', ', array_keys($tablaMap))]);
                exit;
            }
            ensureProductosCatalogTable($pdo, $db, $config['table']);
            $table = "{$db}.{$config['table']}";
            $field = $resolveNameColumn($table, $config['field']);
            $idCol = $resolveIdColumn($table);
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID requerido']);
                exit;
            }
            // Verificar si está en uso (para grupos y marcas)
            $fkField = null;
            if ($tablaKey === 'grupos') $fkField = 'grupo';
            elseif ($tablaKey === 'marcas') $fkField = 'marca';
            elseif ($tablaKey === 'modelos') $fkField = 'modelo';

            if ($fkField) {
                $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM {$db}.tblproductos WHERE {$fkField} = :id AND Estado = 1");
                $stmt->execute([':id' => $id]);
                $count = $stmt->fetch()['c'];
                if ($count > 0) {
                    echo json_encode(['success' => false, 'error' => "No se puede eliminar: {$count} producto(s) activo(s) usan este registro"]);
                    exit;
                }
            }

            $pdo->prepare("DELETE FROM {$table} WHERE {$idCol} = :id")->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Eliminado correctamente']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida. Opciones: list, create, update, delete, seed_defaults']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
