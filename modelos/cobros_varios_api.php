<?php

/**
 * Cobros Varios API - Alpine.js Backend
 * Handles client/account search and insert operations for extracto_cliente and extracto_cuenta
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$id_empresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);

// DB Connection
$masterDb = defined('MASTER_DB') ? MASTER_DB : ($_SESSION['dbu'] ?? 'serproc1');
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Get company database
    $stmtDB = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    if (!$dbName) {
        throw new Exception("Base de datos no encontrada para empresa ID: $id_empresa");
    }

    switch ($action) {
        // ===== SEARCH CLIENTS =====
        case 'search_clients':
            $query = trim($_GET['q'] ?? '');
            $limit = (int)($_GET['limit'] ?? 20);

            $sql = "SELECT c.id, c.nombre, c.numero as ruc, 
                           COALESCE((SELECT SUM(credito - debito) FROM $dbName.extracto_cliente WHERE codigo = c.id AND estado = 1), 0) as saldo
                    FROM $dbName.clientes c
                    WHERE (c.nombre LIKE :q OR c.numero LIKE :q)
                    ORDER BY c.nombre
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'clients' => $clients]);
            break;

        // ===== SEARCH ACCOUNTS =====
        case 'search_cuentas':
            $query = trim($_GET['q'] ?? '');
            $limit = (int)($_GET['limit'] ?? 20);

            $sql = "SELECT id, id_cuenta as codigo, cuenta as nombre
                    FROM $dbName.cuentas
                    WHERE (cuenta LIKE :q OR id_cuenta LIKE :q)
                    ORDER BY cuenta
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'cuentas' => $cuentas]);
            break;

        // ===== GET CATALOGOS =====
        case 'get_catalogos':
            // Operations
            $stmtOps = $pdo->query("SELECT id, operacion FROM " . MASTER_DB . ".operaciones WHERE operacion LIKE '%Entrada%' OR operacion LIKE '%Salida%' ORDER BY operacion");
            $operaciones = $stmtOps->fetchAll(PDO::FETCH_ASSOC);

            // References
            $stmtRefs = $pdo->query("SELECT id, referencia FROM " . MASTER_DB . ".referencia WHERE referencia LIKE '%Cobro%' OR referencia LIKE '%Ingreso%' ORDER BY referencia");
            $referencias = $stmtRefs->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'operaciones' => $operaciones,
                'referencias' => $referencias
            ]);
            break;

        // ===== INSERT COBRO CLIENTE =====
        case 'insert_cobro_cliente':
            $input = json_decode(file_get_contents('php://input'), true);

            $id_cliente = (int)($input['id_cliente'] ?? 0);
            $id_caja = (int)($input['id_caja'] ?? $_SESSION['id_caja_def'] ?? 1);
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');
            $medio_pago = strtoupper(trim($input['medio_pago'] ?? 'EFECTIVO'));
            $referencia_pago = trim($input['referencia_pago'] ?? '');

            if ($id_cliente <= 0) {
                throw new Exception("Debe seleccionar un cliente");
            }
            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }
            if (empty($concepto)) {
                $concepto = "Cobro Varios";
            }

            // Prepend payment method to concept
            $conceptoFull = $medio_pago . " | " . $concepto;
            if (!empty($referencia_pago)) {
                $conceptoFull .= " | Ref: " . $referencia_pago;
            }

            // 1. Insert into extracto_cliente
            // referencia = 16 (Cobro Varios), operacion = 2 (Entrada de Caja)
            $stmt = $pdo->prepare("
                INSERT INTO $dbName.extracto_cliente 
                (fecha, sucursal, referencia, operacion, codigo, concepto, credito, debito, login, estado, moneda, cambio, nro_comprobante)
                VALUES (NOW(), 1, 16, 2, :id_cliente, :concepto, :monto, 0, :id_login, 1, 1, 1, :comprobante)
            ");
            $stmt->execute([
                ':id_cliente' => $id_cliente,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':monto' => $monto,
                ':id_login' => $id_login,
                ':comprobante' => substr($referencia_pago, 0, 20)
            ]);

            $newId = $pdo->lastInsertId();

            // 2. Insert into extracto_caja (Entrada de dinero)
            $stmtCaja = $pdo->prepare("
                INSERT INTO $dbName.extracto_caja 
                (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante, medio_cobro, tabla_relacion, id_relacion)
                VALUES (1, :id_caja, :concepto, 0, :credito, NOW(), :login, :id_login, 1, 1, 1, 2, 16, :comprobante, :medio_cobro, 'clientes', :id_cliente)
            ");
            $stmtCaja->execute([
                ':id_caja' => $id_caja,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':credito' => $monto,
                ':login' => $id_login,
                ':id_login' => $id_login,
                ':comprobante' => substr($referencia_pago, 0, 20),
                ':medio_cobro' => $medio_pago,
                ':id_cliente' => $id_cliente
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Cobro registrado en Cliente y Caja',
                'id' => $newId
            ]);
            break;

        // ===== INSERT COBRO CUENTA =====
        case 'insert_cobro_cuenta':
            $input = json_decode(file_get_contents('php://input'), true);

            $id_cuenta = (int)($input['id_cuenta'] ?? 0);
            $id_caja = (int)($input['id_caja'] ?? $_SESSION['id_caja_def'] ?? 1);
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');
            $medio_pago = strtoupper(trim($input['medio_pago'] ?? 'EFECTIVO'));
            $referencia_pago = trim($input['referencia_pago'] ?? '');

            if ($id_cuenta <= 0) {
                throw new Exception("Debe seleccionar una cuenta");
            }
            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }
            if (empty($concepto)) {
                $concepto = "Ingreso Varios";
            }

            // Prepend payment method to concept
            $conceptoFull = $medio_pago . " | " . $concepto;
            if (!empty($referencia_pago)) {
                $conceptoFull .= " | Ref: " . $referencia_pago;
            }

            // 1. Insert into extracto_cuenta
            // referencia = 3 (Ingresos Varios), operacion = 2 (Entrada de Caja)
            $stmt = $pdo->prepare("
                INSERT INTO $dbName.extracto_cuenta 
                (fecha, id_sucursal, referencia, operacion, cuenta, concepto, credito, debito, login, estado, moneda, cambio)
                VALUES (NOW(), 1, 3, 2, :id_cuenta, :concepto, :monto, 0, :id_login, 1, 1, 1)
            ");
            $stmt->execute([
                ':id_cuenta' => $id_cuenta,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':monto' => $monto,
                ':id_login' => $id_login
            ]);

            $newId = $pdo->lastInsertId();

            // 2. Insert into extracto_caja (Entrada de dinero)
            $stmtCaja = $pdo->prepare("
                INSERT INTO $dbName.extracto_caja 
                (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante, medio_cobro)
                VALUES (1, :id_caja, :concepto, 0, :credito, NOW(), :login, :id_login, 1, 1, 1, 2, 3, :comprobante, :medio_cobro)
            ");
            $stmtCaja->execute([
                ':id_caja' => $id_caja,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':credito' => $monto,
                ':login' => $id_login,
                ':id_login' => $id_login,
                ':comprobante' => substr($referencia_pago, 0, 20),
                ':medio_cobro' => $medio_pago
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Ingreso registrado en Cuenta y Caja',
                'id' => $newId
            ]);
            break;

        default:
            throw new Exception("Acción no válida: $action");
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
