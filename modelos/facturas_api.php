<?php

/**
 * API de Facturas - Endpoint separado para evitar conflictos con Five Server
 * Este archivo SOLO devuelve JSON, nunca HTML.
 */

// Permitir CORS para peticiones locales
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Siempre devolver JSON puro
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Obtener datos de la petición (POST, GET, o JSON body)
$requestData = $_POST;
if (empty($requestData)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $requestData = $json;
        } else {
            parse_str($rawInput, $parsed);
            if (!empty($parsed)) $requestData = $parsed;
        }
    }
}
if (empty($requestData)) $requestData = $_GET;

// Validar acción
$action = $requestData['action'] ?? '';

if ($action !== 'get_rows' && $action !== 'get_detail' && $action !== 'update_sifen_status' && $action !== 'save_grid_state' && $action !== 'load_grid_state') {
    echo json_encode(['error' => 'Acción no válida', 'action_received' => $action]);
    exit;
}

// Handler para detalles (cobros)
if ($action === 'get_detail') {
    $id_factura = (int)($requestData['id_factura'] ?? 0);
    $id_empresa = (int)($requestData['id_empresa'] ?? 0);

    // Credenciales DB (repetidas por simplicidad, idealmente refactorizar)
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");

        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if (!$dbName) throw new Exception("DB no encontrada");

        // Array consolidado de cobros
        $allRows = [];

        // 1. Buscar en recibos_cobro (Legacy / Cobros Manuales)
        try {
            $sql = "SELECT id, nro_recibo, fecha, monto, forma_pago, observacion 
                    FROM $dbName.recibos_cobro 
                    WHERE id_factura = :id 
                    ORDER BY fecha DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id_factura]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $allRows[] = $r;
            }
        } catch (Exception $e) { /* Ignore */
        }

        // 2. Buscar en extracto_cliente (Pagos POS vinculados)
        try {
            $stmtPagos = $pdo->prepare("
                SELECT id, fecha, credito as monto, 'POS/Caja' as forma_pago, concepto as observacion, 
                       COALESCE(medio_cobro, 'EFECTIVO') as medio_cobro
                FROM $dbName.extracto_cliente 
                WHERE id_factura = :id AND estado = 1 AND credito > 0
                ORDER BY fecha DESC
            ");
            $stmtPagos->execute([':id' => $id_factura]);
            $posRows = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);
            foreach ($posRows as $r) {
                $allRows[] = [
                    'id' => 'EC-' . $r['id'],
                    'nro_recibo' => 'POS #' . $r['id'],
                    'fecha' => $r['fecha'],
                    'monto' => $r['monto'],
                    'forma_pago' => $r['medio_cobro'] ?: 'EFECTIVO',
                    'observacion' => $r['observacion']
                ];
            }
        } catch (Exception $e) { /* Ignore */
        }

        // 3. Buscar en extracto_caja (Fallback por coincidencia de Nro Factura en concepto)
        if (empty($allRows)) {
            try {
                // Obtener número de factura primero
                $stmtNr = $pdo->prepare("SELECT nro_factura FROM $dbName.factura_ventas WHERE id_factura = :id");
                $stmtNr->execute([':id' => $id_factura]);
                $nroFactura = $stmtNr->fetchColumn();

                if ($nroFactura) {
                    $stmtCaja = $pdo->prepare("
                        SELECT id, fecha, credito as monto, 'Caja' as forma_pago, concepto as observacion,
                               COALESCE(medio_cobro, 'EFECTIVO') as medio_cobro, comprobante
                        FROM $dbName.extracto_caja 
                        WHERE concepto LIKE :nro AND estado = 1 AND credito > 0
                        ORDER BY fecha DESC
                    ");
                    $stmtCaja->execute([':nro' => '%' . $nroFactura . '%']);
                    $cajaRows = $stmtCaja->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($cajaRows as $r) {
                        // Evitar duplicados si ya vino de extracto_cliente (aunque IDs son distintos, conceptualmente es el mismo pago)
                        // Por simplicidad agregamos todo
                        $allRows[] = [
                            'id' => 'CJ-' . $r['id'],
                            'nro_recibo' => $r['comprobante'] ? 'Ref: ' . $r['comprobante'] : 'Caja #' . $r['id'],
                            'fecha' => $r['fecha'],
                            'monto' => $r['monto'],
                            'forma_pago' => $r['medio_cobro'] ?: 'EFECTIVO',
                            'observacion' => $r['observacion']
                        ];
                    }
                }
            } catch (Exception $e) { /* Ignore */
            }
        }

        echo json_encode(['rows' => $allRows]);
        exit;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            echo json_encode(['rows' => []]);
            exit;
        }
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handler para actualizar estado SIFEN
if ($action === 'update_sifen_status') {
    // ... (existing code) ...
    $id_factura = (int)($requestData['id_factura'] ?? 0);
    $id_empresa = (int)($requestData['id_empresa'] ?? 0);
    $estado = $requestData['estado'] ?? '';
    $xml = $requestData['xml'] ?? '';
    $mensaje = $requestData['mensaje'] ?? '';

    if (!$id_factura || !$estado) {
        echo json_encode(['success' => false, 'message' => 'Faltan datos']);
        exit;
    }

    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");

        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if (!$dbName) throw new Exception("DB no encontrada");

        // Actualizar factura
        $sql = "UPDATE $dbName.factura_ventas 
                SET estado_sifen = :estado, 
                    xml_respuesta = :xml,
                    mensaje_sifen = :msg
                WHERE id_factura = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':estado' => $estado,
            ':xml' => $xml,
            ':msg' => $mensaje,
            ':id' => $id_factura
        ]);

        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// PERSISTENCIA DE GRID (GUARDAR)
if ($action === 'save_grid_state') {
    $id_empresa = (int)($requestData['id_empresa'] ?? 0);
    $grid_name = $requestData['grid_name'] ?? 'facturas_sifen';
    $state_json = $requestData['state'] ?? '';
    $id_usuario = isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : 0;

    if (!$id_empresa || !$state_json) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        exit;
    }

    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if (!$dbName) throw new Exception("DB no encontrada");

        // Crear tabla si no existe
        $pdo->exec("CREATE TABLE IF NOT EXISTS $dbName.user_grid_states (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            grid_name VARCHAR(50) NOT NULL,
            state_json TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_user_grid (id_usuario, grid_name)
        )");

        // Insertar o actualizar (Upsert)
        $sql = "INSERT INTO $dbName.user_grid_states (id_usuario, grid_name, state_json) 
                VALUES (:user, :grid, :state)
                ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user' => $id_usuario,
            ':grid' => $grid_name,
            ':state' => $state_json
        ]);

        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// PERSISTENCIA DE GRID (CARGAR)
if ($action === 'load_grid_state') {
    $id_empresa = (int)($requestData['id_empresa'] ?? 0);
    $grid_name = $requestData['grid_name'] ?? 'facturas_sifen';
    $id_usuario = isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : 0;

    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if (!$dbName) throw new Exception("DB no encontrada");

        // Intentar leer
        // Check table exists first or use try/catch
        try {
            $stmt = $pdo->prepare("SELECT state_json FROM $dbName.user_grid_states WHERE id_usuario = :user AND grid_name = :grid");
            $stmt->execute([':user' => $id_usuario, ':grid' => $grid_name]);
            $state = $stmt->fetchColumn();

            echo json_encode(['success' => true, 'state' => $state]);
        } catch (Exception $e) {
            // Tabla no existe o error SQL
            echo json_encode(['success' => true, 'state' => null]);
        }
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Parámetros
$startRow = (int)($requestData['startRow'] ?? 0);
$endRow = (int)($requestData['endRow'] ?? 50);
$id_empresa = (int)($requestData['id_empresa'] ?? 0);
$limit_records = (int)($requestData['limit_records'] ?? 0); // Límite global solicitado por el usuario
$type_filter = (int)($requestData['type'] ?? 0); // Filtro por tipo_documento (ej: 3 para Electrónica)

$limit = $endRow - $startRow;

// Credenciales DB
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Obtener base de datos de la empresa
    $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    if (!$dbName) {
        throw new Exception("Base de datos no encontrada para empresa ID: $id_empresa");
    }

    // Parámetros de fecha
    $startDate = $requestData['startDate'] ?? '';
    $endDate = $requestData['endDate'] ?? '';

    // Construir WHERE
    $whereClauses = ["1=1"];
    $params = [];

    if (!empty($startDate)) {
        $whereClauses[] = "fv.fecha >= :startDate";
        $params[':startDate'] = $startDate . ' 00:00:00';
    }

    if (!empty($endDate)) {
        $whereClauses[] = "fv.fecha <= :endDate";
        $params[':endDate'] = $endDate . ' 23:59:59';
    }

    if ($type_filter > 0) {
        $whereClauses[] = "fv.tipo_documento = :typeFilter";
        $params[':typeFilter'] = $type_filter;
    }

    $whereSql = implode(' AND ', $whereClauses);

    // Contar total con filtros
    $sqlCount = "SELECT COUNT(*) FROM $dbName.factura_ventas fv WHERE $whereSql";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $rowCount = (int)$stmtCount->fetchColumn();

    // Si hay un limite global, el rowCount real para AG Grid debe ser el menor entre el total y el limite
    if ($limit_records > 0 && $rowCount > $limit_records) {
        $rowCount = $limit_records;
    }

    // Obtener filas (optimizado)
    $sql = "
    SELECT 
        fv.id_factura,
        fv.nro_factura,
        fv.fecha,
        fv.total,
        fv.forma_pago,
        fv.tipo_venta,
        fv.tipo_documento,
        fv.estado,
        fv.cdc,
        fv.xml_respuesta,
        fv.xml_firmado,
        fv.est_res_anul,
        fv.estado_sifen,
        fv.mensaje_sifen,
        c.nombre AS nombre_cliente,
        c.numero AS ruc_cliente
    FROM $dbName.factura_ventas fv
    LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
    WHERE $whereSql
    ORDER BY fv.id_factura DESC
    LIMIT $limit OFFSET $startRow
    ";

    $stmt = $pdo->prepare($sql);
    // Bind limit/offset manually or emulate
    // PDO prepare doesn't easy handle LIMIT with string calc in older versions but safe here if we concat.
    // However params array is for WHERE.
    // Let's bind all.
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    // Note: PDO can have issues binding LIMIT in some drivers/versions if not int type explicit.
    // Easier to interpolate limit/offset since they are cast to int above (lines 50-51).

    $stmt->execute();
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear filas
    $formattedRows = array_map(function ($row) {
        $r = array_change_key_case($row, CASE_LOWER);
        $forma = $r['forma_pago'] ?? $r['tipo_venta'] ?? 'Contado';
        if (is_numeric($forma)) $forma = ($forma == 1) ? 'Contado' : 'Crédito';

        return [
            'id_factura' => (int)$r['id_factura'],
            'nro_factura' => $r['nro_factura'] ?? '',
            'fecha' => $r['fecha'],
            'nombre_cliente' => $r['nombre_cliente'] ?? 'Sin Nombre',
            'ruc_cliente' => $r['ruc_cliente'] ?? '',
            'total' => (float)($r['total'] ?? 0),
            'saldo' => (float)($r['total'] ?? 0), // Usar total como saldo por defecto
            'forma_pago' => $forma,
            'cdc' => $r['cdc'] ?? '',
            'estado' => $r['estado'] ?? '',
            'xml_respuesta' => $r['xml_respuesta'] ?? '',
            'est_res_anul' => $r['est_res_anul'] ?? '',
            'estado_sifen' => $r['estado_sifen'] ?? '',
            'mensaje_sifen' => $r['mensaje_sifen'] ?? ''
        ];
    }, $rawRows);

    echo json_encode([
        'rows' => $formattedRows,
        'lastRow' => $rowCount
    ]);
} catch (Exception $e) {
    http_response_code(500);

    // Detailed error for debugging
    $errorDetails = [
        'rows' => [],
        'lastRow' => 0,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ];

    // Add more context if available
    if (isset($dbName)) {
        $errorDetails['dbName'] = $dbName;
    }
    if (isset($id_empresa)) {
        $errorDetails['id_empresa'] = $id_empresa;
    }

    echo json_encode($errorDetails);
}
