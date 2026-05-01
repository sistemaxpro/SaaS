<?php

/**
 * API para gestionar habilitacion_sifen_sucursales
 * Endpoints para CRUD de establecimientos por sucursal
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : (isset($_SESSION['id_empresa']) ? (int)$_SESSION['id_empresa'] : 0);

if (empty($id_empresa)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'id_empresa requerido']));
}

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $pdo->exec("SET NAMES utf8");
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Error de conexión']));
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'listSucursales':
        listSucursales($pdo, $masterDb, $id_empresa);
        break;
    case 'getSucursal':
        getSucursal($pdo, $masterDb, $id_empresa);
        break;
    case 'saveSucursal':
        saveSucursal($pdo, $masterDb, $id_empresa);
        break;
    case 'deleteSucursal':
        deleteSucursal($pdo, $masterDb, $id_empresa);
        break;
    case 'listPuntos':
        listPuntos($pdo, $masterDb);
        break;
    case 'savePunto':
        savePunto($pdo, $masterDb);
        break;
    case 'deletePunto':
        deletePunto($pdo, $masterDb);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
}

/**
 * Listar sucursales habilitadas para SIFEN
 */
function listSucursales($pdo, $masterDb, $id_empresa)
{
    try {
        // Obtener habilitación activa
        $stmtHab = $pdo->prepare("SELECT id FROM $masterDb.habilitacion_sifen 
            WHERE id_empresa = :id_empresa AND activo = 1 LIMIT 1");
        $stmtHab->execute([':id_empresa' => $id_empresa]);
        $hab = $stmtHab->fetch(PDO::FETCH_ASSOC);

        if (!$hab) {
            echo json_encode(['success' => false, 'error' => 'No hay habilitación SIFEN activa']);
            return;
        }

        $sql = "SELECT s.*, h.codigo_establecimiento, h.punto_expedicion_defecto, h.activo as habilitada
            FROM $masterDb.habilitacion_sifen_sucursales h
            LEFT JOIN sucursales s ON h.id_sucursal = s.id
            WHERE h.id_habilitacion = :id_hab
            ORDER BY h.codigo_establecimiento";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_hab' => $hab['id']]);
        $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $sucursales]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Obtener sucursal específica
 */
function getSucursal($pdo, $masterDb, $id_empresa)
{
    try {
        $id_suc = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;

        $stmt = $pdo->prepare("SELECT hs.* FROM $masterDb.habilitacion_sifen_sucursales hs
            INNER JOIN $masterDb.habilitacion_sifen h ON hs.id_habilitacion = h.id
            WHERE h.id_empresa = :id_empresa AND hs.id_sucursal = :id_suc");

        $stmt->execute([':id_empresa' => $id_empresa, ':id_suc' => $id_suc]);
        $sucursal = $stmt->fetch(PDO::FETCH_ASSOC);

        // Obtener puntos de expedición si existen
        if ($sucursal) {
            $stmtPtos = $pdo->prepare("SELECT * FROM $masterDb.habilitacion_sifen_puntos_expedicion 
                WHERE id_habilitacion_sucursal = :id ORDER BY codigo_punto");
            $stmtPtos->execute([':id' => $sucursal['id']]);
            $sucursal['puntos'] = $stmtPtos->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => (bool)$sucursal, 'data' => $sucursal]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Guardar/actualizar sucursal
 */
function saveSucursal($pdo, $masterDb, $id_empresa)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $id_sucursal = (int)($data['id_sucursal'] ?? 0);
        $codigo_establecimiento = $data['codigo_establecimiento'] ?? '001';
        $punto_expedicion_defecto = $data['punto_expedicion_defecto'] ?? '001';
        $descripcion = $data['descripcion'] ?? null;

        if (empty($id_sucursal)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id_sucursal requerido']);
            return;
        }

        // Obtener habilitación activa
        $stmtHab = $pdo->prepare("SELECT id FROM $masterDb.habilitacion_sifen 
            WHERE id_empresa = :id_empresa AND activo = 1 LIMIT 1");
        $stmtHab->execute([':id_empresa' => $id_empresa]);
        $hab = $stmtHab->fetch(PDO::FETCH_ASSOC);

        if (!$hab) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No hay habilitación SIFEN activa']);
            return;
        }

        // Verificar si ya existe
        $stmtCheck = $pdo->prepare("SELECT id FROM $masterDb.habilitacion_sifen_sucursales 
            WHERE id_habilitacion = :id_hab AND id_sucursal = :id_suc");
        $stmtCheck->execute([':id_hab' => $hab['id'], ':id_suc' => $id_sucursal]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update
            $stmt = $pdo->prepare("UPDATE $masterDb.habilitacion_sifen_sucursales 
                SET codigo_establecimiento = :est, punto_expedicion_defecto = :pto, descripcion = :desc
                WHERE id = :id");
            $stmt->execute([
                ':est' => $codigo_establecimiento,
                ':pto' => $punto_expedicion_defecto,
                ':desc' => $descripcion,
                ':id' => $existing['id']
            ]);
            $id_result = $existing['id'];
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO $masterDb.habilitacion_sifen_sucursales 
                (id_habilitacion, id_sucursal, codigo_establecimiento, punto_expedicion_defecto, descripcion, activo)
                VALUES (:id_hab, :id_suc, :est, :pto, :desc, 1)");
            $stmt->execute([
                ':id_hab' => $hab['id'],
                ':id_suc' => $id_sucursal,
                ':est' => $codigo_establecimiento,
                ':pto' => $punto_expedicion_defecto,
                ':desc' => $descripcion
            ]);
            $id_result = $pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'id' => $id_result, 'message' => 'Sucursal guardada correctamente']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Eliminar sucursal
 */
function deleteSucursal($pdo, $masterDb, $id_empresa)
{
    try {
        $id_sucursal = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;

        if (empty($id_sucursal)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id_sucursal requerido']);
            return;
        }

        $stmt = $pdo->prepare("DELETE FROM $masterDb.habilitacion_sifen_sucursales 
            WHERE id_sucursal = :id_suc 
            AND id_habilitacion = (SELECT id FROM $masterDb.habilitacion_sifen 
                WHERE id_empresa = :id_empresa AND activo = 1)");

        $stmt->execute([':id_suc' => $id_sucursal, ':id_empresa' => $id_empresa]);

        echo json_encode(['success' => true, 'message' => 'Sucursal eliminada correctamente']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Listar puntos de expedición
 */
function listPuntos($pdo, $masterDb)
{
    try {
        $id_sucursal_hab = isset($_GET['id_habilitacion_sucursal']) ? (int)$_GET['id_habilitacion_sucursal'] : 0;

        if (empty($id_sucursal_hab)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id_habilitacion_sucursal requerido']);
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM $masterDb.habilitacion_sifen_puntos_expedicion 
            WHERE id_habilitacion_sucursal = :id
            ORDER BY codigo_punto");

        $stmt->execute([':id' => $id_sucursal_hab]);
        $puntos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $puntos]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Guardar punto de expedición
 */
function savePunto($pdo, $masterDb)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $id_habilitacion_sucursal = (int)($data['id_habilitacion_sucursal'] ?? 0);
        $codigo_punto = $data['codigo_punto'] ?? '001';
        $descripcion = $data['descripcion'] ?? null;

        if (empty($id_habilitacion_sucursal)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id_habilitacion_sucursal requerido']);
            return;
        }

        // Verificar si ya existe
        $stmtCheck = $pdo->prepare("SELECT id FROM $masterDb.habilitacion_sifen_puntos_expedicion 
            WHERE id_habilitacion_sucursal = :id AND codigo_punto = :pto");
        $stmtCheck->execute([':id' => $id_habilitacion_sucursal, ':pto' => $codigo_punto]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update
            $stmt = $pdo->prepare("UPDATE $masterDb.habilitacion_sifen_puntos_expedicion 
                SET descripcion = :desc WHERE id = :id");
            $stmt->execute([':desc' => $descripcion, ':id' => $existing['id']]);
            $id_result = $existing['id'];
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO $masterDb.habilitacion_sifen_puntos_expedicion 
                (id_habilitacion_sucursal, codigo_punto, descripcion, activo)
                VALUES (:id, :pto, :desc, 1)");
            $stmt->execute([
                ':id' => $id_habilitacion_sucursal,
                ':pto' => $codigo_punto,
                ':desc' => $descripcion
            ]);
            $id_result = $pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'id' => $id_result, 'message' => 'Punto de expedición guardado']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Eliminar punto de expedición
 */
function deletePunto($pdo, $masterDb)
{
    try {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id requerido']);
            return;
        }

        $stmt = $pdo->prepare("DELETE FROM $masterDb.habilitacion_sifen_puntos_expedicion WHERE id = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Punto eliminado correctamente']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
