<?php

/**
 * API de Vehículos
 * Gestión de vehículos para notas de remisión electrónica
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

// Configuración de base de datos (igual que nr_api.php)
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$id_empresa = intval($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? 0);

if (!$id_empresa) {
    echo json_encode(['success' => false, 'error' => 'id_empresa requerido']);
    exit;
}

// Obtener nombre de la base de datos de la empresa
function getDbName($pdo, $masterDb, $id_empresa)
{
    $stmt = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    return $stmt->fetchColumn();
}

// Asegurar que la tabla vehiculos exista
function ensureVehiculosTable($pdo, $dbName)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbName}.vehiculos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(50) NOT NULL COMMENT 'Tipo: Camión, Camioneta, Motocicleta, etc.',
            marca VARCHAR(100) NOT NULL,
            modelo VARCHAR(100) DEFAULT NULL,
            chapa VARCHAR(20) NOT NULL COMMENT 'Número de placa',
            color VARCHAR(50) DEFAULT NULL,
            anho INT DEFAULT NULL COMMENT 'Año del vehículo',
            capacidad_kg DECIMAL(10,2) DEFAULT NULL COMMENT 'Capacidad de carga en kg',
            conductor_id INT DEFAULT NULL COMMENT 'Conductor asignado por defecto',
            conductor_nombre VARCHAR(200) DEFAULT NULL,
            conductor_documento VARCHAR(50) DEFAULT NULL,
            observaciones TEXT DEFAULT NULL,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_chapa (chapa),
            INDEX idx_tipo (tipo),
            INDEX idx_activo (activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$dbName = getDbName($pdo, $masterDb, $id_empresa);
if (!$dbName) {
    echo json_encode(['success' => false, 'error' => 'Empresa no encontrada']);
    exit;
}

// Crear tabla si no existe
ensureVehiculosTable($pdo, $dbName);

switch ($action) {
    case 'list':
        // Listar todos los vehículos
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
        $where = $includeInactive ? '' : 'WHERE activo = 1';

        $stmt = $pdo->query("
            SELECT 
                id,
                tipo,
                marca,
                modelo,
                chapa,
                color,
                anho,
                capacidad_kg,
                conductor_id,
                conductor_nombre,
                conductor_documento,
                observaciones,
                activo,
                created_at,
                updated_at
            FROM {$dbName}.vehiculos
            {$where}
            ORDER BY tipo, marca, chapa
        ");

        $vehiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $vehiculos, 'total' => count($vehiculos)]);
        break;

    case 'get':
        // Obtener un vehículo por ID
        $id = intval($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID requerido']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM {$dbName}.vehiculos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $vehiculo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($vehiculo) {
            echo json_encode(['success' => true, 'data' => $vehiculo]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Vehículo no encontrado']);
        }
        break;

    case 'buscar':
        // Buscar vehículos por término (chapa, marca, tipo)
        $term = $_GET['term'] ?? '';

        if (strlen($term) < 1) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT 
                id,
                tipo,
                marca,
                modelo,
                chapa,
                color,
                conductor_nombre,
                conductor_documento
            FROM {$dbName}.vehiculos
            WHERE activo = 1 
              AND (chapa LIKE :term1 OR marca LIKE :term2 OR tipo LIKE :term3 OR modelo LIKE :term4)
            ORDER BY chapa
            LIMIT 15
        ");
        $stmt->execute([
            ':term1' => "%$term%",
            ':term2' => "%$term%",
            ':term3' => "%$term%",
            ':term4' => "%$term%"
        ]);
        $vehiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $vehiculos]);
        break;

    case 'create':
        // Crear nuevo vehículo
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }

        $tipo = trim($input['tipo'] ?? '');
        $marca = trim($input['marca'] ?? '');
        $modelo = trim($input['modelo'] ?? '');
        $chapa = strtoupper(trim($input['chapa'] ?? ''));
        $color = trim($input['color'] ?? '');
        $anho = intval($input['anho'] ?? 0) ?: null;
        $capacidad_kg = floatval($input['capacidad_kg'] ?? 0) ?: null;
        $conductor_id = intval($input['conductor_id'] ?? 0) ?: null;
        $conductor_nombre = trim($input['conductor_nombre'] ?? '');
        $conductor_documento = trim($input['conductor_documento'] ?? '');
        $observaciones = trim($input['observaciones'] ?? '');

        if (!$tipo || !$marca || !$chapa) {
            echo json_encode(['success' => false, 'error' => 'Tipo, Marca y Chapa son requeridos']);
            exit;
        }

        // Verificar si la chapa ya existe
        $stmtCheck = $pdo->prepare("SELECT id FROM {$dbName}.vehiculos WHERE chapa = :chapa");
        $stmtCheck->execute([':chapa' => $chapa]);
        if ($stmtCheck->fetch()) {
            echo json_encode(['success' => false, 'error' => "Ya existe un vehículo con la chapa {$chapa}"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO {$dbName}.vehiculos 
                (tipo, marca, modelo, chapa, color, anho, capacidad_kg, conductor_id, conductor_nombre, conductor_documento, observaciones)
                VALUES 
                (:tipo, :marca, :modelo, :chapa, :color, :anho, :capacidad_kg, :conductor_id, :conductor_nombre, :conductor_documento, :observaciones)
            ");

            $stmt->execute([
                ':tipo' => $tipo,
                ':marca' => $marca,
                ':modelo' => $modelo,
                ':chapa' => $chapa,
                ':color' => $color,
                ':anho' => $anho,
                ':capacidad_kg' => $capacidad_kg,
                ':conductor_id' => $conductor_id,
                ':conductor_nombre' => $conductor_nombre,
                ':conductor_documento' => $conductor_documento,
                ':observaciones' => $observaciones
            ]);

            $newId = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Vehículo creado correctamente']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Error al crear: ' . $e->getMessage()]);
        }
        break;

    case 'update':
        // Actualizar vehículo
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }

        $id = intval($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID requerido']);
            exit;
        }

        $tipo = trim($input['tipo'] ?? '');
        $marca = trim($input['marca'] ?? '');
        $modelo = trim($input['modelo'] ?? '');
        $chapa = strtoupper(trim($input['chapa'] ?? ''));
        $color = trim($input['color'] ?? '');
        $anho = intval($input['anho'] ?? 0) ?: null;
        $capacidad_kg = floatval($input['capacidad_kg'] ?? 0) ?: null;
        $conductor_id = intval($input['conductor_id'] ?? 0) ?: null;
        $conductor_nombre = trim($input['conductor_nombre'] ?? '');
        $conductor_documento = trim($input['conductor_documento'] ?? '');
        $observaciones = trim($input['observaciones'] ?? '');
        $activo = isset($input['activo']) ? intval($input['activo']) : 1;

        if (!$tipo || !$marca || !$chapa) {
            echo json_encode(['success' => false, 'error' => 'Tipo, Marca y Chapa son requeridos']);
            exit;
        }

        // Verificar si la chapa ya existe en otro vehículo
        $stmtCheck = $pdo->prepare("SELECT id FROM {$dbName}.vehiculos WHERE chapa = :chapa AND id != :id");
        $stmtCheck->execute([':chapa' => $chapa, ':id' => $id]);
        if ($stmtCheck->fetch()) {
            echo json_encode(['success' => false, 'error' => "Ya existe otro vehículo con la chapa {$chapa}"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE {$dbName}.vehiculos SET
                    tipo = :tipo,
                    marca = :marca,
                    modelo = :modelo,
                    chapa = :chapa,
                    color = :color,
                    anho = :anho,
                    capacidad_kg = :capacidad_kg,
                    conductor_id = :conductor_id,
                    conductor_nombre = :conductor_nombre,
                    conductor_documento = :conductor_documento,
                    observaciones = :observaciones,
                    activo = :activo
                WHERE id = :id
            ");

            $stmt->execute([
                ':tipo' => $tipo,
                ':marca' => $marca,
                ':modelo' => $modelo,
                ':chapa' => $chapa,
                ':color' => $color,
                ':anho' => $anho,
                ':capacidad_kg' => $capacidad_kg,
                ':conductor_id' => $conductor_id,
                ':conductor_nombre' => $conductor_nombre,
                ':conductor_documento' => $conductor_documento,
                ':observaciones' => $observaciones,
                ':activo' => $activo,
                ':id' => $id
            ]);

            echo json_encode(['success' => true, 'message' => 'Vehículo actualizado correctamente']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()]);
        }
        break;

    case 'delete':
        // Eliminar (desactivar) vehículo
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID requerido']);
            exit;
        }

        try {
            // Soft delete
            $stmt = $pdo->prepare("UPDATE {$dbName}.vehiculos SET activo = 0 WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['success' => true, 'message' => 'Vehículo eliminado correctamente']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()]);
        }
        break;

    case 'restore':
        // Restaurar vehículo
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID requerido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE {$dbName}.vehiculos SET activo = 1 WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['success' => true, 'message' => 'Vehículo restaurado correctamente']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Error al restaurar: ' . $e->getMessage()]);
        }
        break;

    case 'tipos':
        // Listar tipos de vehículos únicos
        $stmt = $pdo->query("
            SELECT DISTINCT tipo 
            FROM {$dbName}.vehiculos 
            WHERE activo = 1 AND tipo IS NOT NULL AND tipo != ''
            ORDER BY tipo
        ");
        $tipos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Agregar tipos predefinidos si no existen
        $tiposPredefinidos = ['Camión', 'Camioneta', 'Furgón', 'Motocicleta', 'Automóvil', 'Semi-remolque', 'Trailer'];
        $tiposFinales = array_unique(array_merge($tiposPredefinidos, $tipos));
        sort($tiposFinales);

        echo json_encode(['success' => true, 'data' => $tiposFinales]);
        break;

    case 'marcas':
        // Listar marcas únicas
        $stmt = $pdo->query("
            SELECT DISTINCT marca 
            FROM {$dbName}.vehiculos 
            WHERE activo = 1 AND marca IS NOT NULL AND marca != ''
            ORDER BY marca
        ");
        $marcas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'data' => $marcas]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        break;
}
