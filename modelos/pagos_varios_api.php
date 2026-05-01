<?php

/**
 * Pagos Varios API - Backend for Cash Withdrawals
 * Handles supplier/account search and insert operations for payments
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$id_empresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);

// DB Connection
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
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
        // ===== SEARCH PROVEEDORES =====
        case 'search_proveedores':
            $query = trim($_GET['q'] ?? '');
            $limit = (int)($_GET['limit'] ?? 20);

            $sql = "SELECT p.id, p.nombre, p.numero as ruc
                    FROM $dbName.proveedores p
                    WHERE (p.nombre LIKE :q OR p.numero LIKE :q)
                    ORDER BY p.nombre
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'proveedores' => $proveedores]);
            break;

        // ===== SEARCH FACTURAS COMPRA =====
        case 'search_facturas_compra':
            $id_proveedor = (int)($_GET['id_proveedor'] ?? 0);
            $query = trim($_GET['q'] ?? '');
            $limit = (int)($_GET['limit'] ?? 20);

            $whereClause = "fc.pendiente > 0 AND fc.estado = 1";
            if ($id_proveedor > 0) {
                $whereClause .= " AND fc.id_cliente = $id_proveedor";
            }
            if (!empty($query)) {
                $whereClause .= " AND (fc.nro_factura LIKE :q OR p.nombre LIKE :q)";
            }

            $sql = "SELECT fc.id_factura, fc.nro_factura, fc.fecha, fc.total, fc.pagado, fc.pendiente,
                           p.nombre as proveedor, p.id as id_proveedor
                    FROM $dbName.factura_compras fc
                    LEFT JOIN $dbName.proveedores p ON fc.id_cliente = p.id
                    WHERE $whereClause
                    ORDER BY fc.fecha DESC
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            if (!empty($query)) {
                $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'facturas' => $facturas]);
            break;

        // ===== SEARCH PERSONAS (CLIENTES) =====
        case 'search_personas':
            $query = trim($_GET['q'] ?? '');
            $limit = (int)($_GET['limit'] ?? 20);

            $sql = "SELECT c.id, c.nombre, c.numero as ruc
                    FROM $dbName.clientes c
                    WHERE (c.nombre LIKE :q OR c.numero LIKE :q)
                    ORDER BY c.nombre
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $personas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'personas' => $personas]);
            break;

        // ===== SEARCH CUENTAS =====
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

        // ===== INSERT PAGO FACTURA COMPRA =====
        case 'insert_pago_factura':
            $input = json_decode(file_get_contents('php://input'), true);

            $id_factura = (int)($input['id_factura'] ?? 0);
            $id_proveedor = (int)($input['id_proveedor'] ?? 0);
            $id_caja = (int)($input['id_caja'] ?? $_SESSION['id_caja_def'] ?? 1);
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');
            $medio_pago = strtoupper(trim($input['medio_pago'] ?? 'EFECTIVO'));
            $nro_factura = trim($input['nro_factura'] ?? '');
            $referencia_pago = trim($input['referencia_pago'] ?? '');

            if ($id_factura <= 0) {
                throw new Exception("Debe seleccionar una factura");
            }
            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }
            if (empty($concepto)) {
                $concepto = "Pago Factura Compra #$nro_factura";
            }

            $conceptoFull = $medio_pago;
            if ($referencia_pago) {
                $conceptoFull .= " ($referencia_pago)";
            }
            $conceptoFull .= " | " . $concepto;

            // 1. Update factura_compras (pagado, pendiente)
            $stmtUpd = $pdo->prepare("
                UPDATE $dbName.factura_compras 
                SET pagado = pagado + :monto, pendiente = pendiente - :monto2
                WHERE id_factura = :id_factura
            ");
            $stmtUpd->execute([
                ':monto' => $monto,
                ':monto2' => $monto,
                ':id_factura' => $id_factura
            ]);

            // 2. Insert into pago_factura_compra
            $stmtPago = $pdo->prepare("
                INSERT INTO $dbName.pago_factura_compra 
                (id_factura, fecha, monto, id_login, concepto)
                VALUES (:id_factura, NOW(), :monto, :id_login, :concepto)
            ");
            $stmtPago->execute([
                ':id_factura' => $id_factura,
                ':monto' => $monto,
                ':id_login' => $id_login,
                ':concepto' => substr($conceptoFull, 0, 100)
            ]);

            $newId = $pdo->lastInsertId();

            // 3. Insert into extracto_caja (Salida de dinero)
            // operacion = 1 (Salida de Caja), referencia = 39 (Pago de Factura de Compra)
            $stmtCaja = $pdo->prepare("
                INSERT INTO $dbName.extracto_caja 
                (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante, medio_cobro, tabla_relacion, id_relacion)
                VALUES (1, :id_caja, :concepto, :debito, 0, NOW(), :login, :id_login, 1, 1, 1, 1, 39, :comprobante, :medio_cobro, 'factura_compras', :id_factura)
            ");
            $stmtCaja->execute([
                ':id_caja' => $id_caja,
                ':concepto' => substr($conceptoFull, 0, 100),
                ':debito' => $monto,
                ':login' => $id_login,
                ':id_login' => $id_login,
                ':comprobante' => substr($nro_factura, 0, 20),
                ':medio_cobro' => $medio_pago,
                ':id_factura' => $id_factura
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Pago de factura registrado',
                'id' => $newId
            ]);
            break;

        // ===== INSERT PAGO VARIOS (Persona) =====
        case 'insert_pago_persona':
            $input = json_decode(file_get_contents('php://input'), true);

            $id_persona = (int)($input['id_persona'] ?? 0);
            $id_caja = (int)($input['id_caja'] ?? $_SESSION['id_caja_def'] ?? 1);
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');
            $medio_pago = strtoupper(trim($input['medio_pago'] ?? 'EFECTIVO'));

            if ($id_persona <= 0) {
                throw new Exception("Debe seleccionar una persona");
            }
            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }
            if (empty($concepto)) {
                $concepto = "Pago Varios";
            }

            $conceptoFull = $medio_pago . " | " . $concepto;

            // 1. Insert into extracto_cliente (debito = pago a cliente)
            // referencia = 27 (Pago a Cliente), operacion = 1 (Salida de Caja)
            $stmt = $pdo->prepare("
                INSERT INTO $dbName.extracto_cliente 
                (fecha, sucursal, referencia, operacion, codigo, concepto, debito, credito, login, estado, moneda, cambio)
                VALUES (NOW(), 1, 27, 1, :id_persona, :concepto, :monto, 0, :id_login, 1, 1, 1)
            ");
            $stmt->execute([
                ':id_persona' => $id_persona,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':monto' => $monto,
                ':id_login' => $id_login
            ]);

            $newId = $pdo->lastInsertId();

            // 2. Insert into extracto_caja (Salida de dinero)
            $stmtCaja = $pdo->prepare("
                INSERT INTO $dbName.extracto_caja 
                (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, medio_cobro, tabla_relacion, id_relacion)
                VALUES (1, :id_caja, :concepto, :debito, 0, NOW(), :login, :id_login, 1, 1, 1, 1, 27, :medio_cobro, 'clientes', :id_persona)
            ");
            $stmtCaja->execute([
                ':id_caja' => $id_caja,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':debito' => $monto,
                ':login' => $id_login,
                ':id_login' => $id_login,
                ':medio_cobro' => $medio_pago,
                ':id_persona' => $id_persona
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Pago registrado en Persona y Caja',
                'id' => $newId
            ]);
            break;

        // ===== INSERT PAGO VARIOS (Cuenta) =====
        case 'insert_pago_cuenta':
            $input = json_decode(file_get_contents('php://input'), true);

            $id_cuenta = (int)($input['id_cuenta'] ?? 0);
            $id_caja = (int)($input['id_caja'] ?? $_SESSION['id_caja_def'] ?? 1);
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');
            $medio_pago = strtoupper(trim($input['medio_pago'] ?? 'EFECTIVO'));

            if ($id_cuenta <= 0) {
                throw new Exception("Debe seleccionar una cuenta");
            }
            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }
            if (empty($concepto)) {
                $concepto = "Egreso Varios";
            }

            $conceptoFull = $medio_pago . " | " . $concepto;

            // 1. Insert into extracto_cuenta (debito = egreso)
            // referencia = 15 (Pago Varios), operacion = 1 (Salida de Caja)
            $stmt = $pdo->prepare("
                INSERT INTO $dbName.extracto_cuenta 
                (fecha, id_sucursal, referencia, operacion, cuenta, concepto, debito, credito, login, estado, moneda, cambio)
                VALUES (NOW(), 1, 15, 1, :id_cuenta, :concepto, :monto, 0, :id_login, 1, 1, 1)
            ");
            $stmt->execute([
                ':id_cuenta' => $id_cuenta,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':monto' => $monto,
                ':id_login' => $id_login
            ]);

            $newId = $pdo->lastInsertId();

            // 2. Insert into extracto_caja (Salida de dinero)
            $stmtCaja = $pdo->prepare("
                INSERT INTO $dbName.extracto_caja 
                (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, medio_cobro)
                VALUES (1, :id_caja, :concepto, :debito, 0, NOW(), :login, :id_login, 1, 1, 1, 1, 15, :medio_cobro)
            ");
            $stmtCaja->execute([
                ':id_caja' => $id_caja,
                ':concepto' => substr($conceptoFull, 0, 60),
                ':debito' => $monto,
                ':login' => $id_login,
                ':id_login' => $id_login,
                ':medio_cobro' => $medio_pago
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Egreso registrado en Cuenta y Caja',
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
