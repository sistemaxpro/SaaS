<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
ini_set('display_errors', '0');
ob_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ERROR | E_PARSE);

try {
    require_once __DIR__ . '/../../../config/bootstrap.php';

    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];

    $sessionOk = Session::isLoggedIn();
    $idEmpresaReq = (int)($data['id_empresa'] ?? 0);
    $idUsuarioReq = (int)($data['id_usuario'] ?? 0);
    if (!$sessionOk && ($idEmpresaReq <= 0 || $idUsuarioReq <= 0)) {
        throw new Exception('Sesión no válida');
    }

    if (!$data) {
        throw new Exception('Datos de entrada inválidos');
    }

    $id_empresa = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
    $id_usuario = (int)($data['id_usuario'] ?? Session::getIdLogin());
    $id_sucursal = (int)($data['id_sucursal'] ?? Session::get('id_sucursal', 1));
    $items = $data['items'] ?? [];
    $id_cliente = (int)($data['id_cliente'] ?? 0);
    $cliente_nombre = trim((string)($data['cliente_nombre'] ?? ''));
    $cliente_ruc = trim((string)($data['cliente_ruc'] ?? ''));

    if ($id_empresa <= 0 || $id_usuario <= 0) {
        throw new Exception('Empresa o usuario inválido');
    }
    if (empty($items) || !is_array($items)) {
        throw new Exception('El pedido está vacío');
    }

    $pdo = Database::getMasterConnection();
    $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
    $stmt->execute([$id_empresa]);
    $dbase = (string)$stmt->fetchColumn();
    if ($dbase === '') {
        throw new Exception('Empresa no encontrada');
    }

    ensurePedidoTables($pdo, $dbase);

    if ($id_cliente > 0 && ($cliente_nombre === '' || $cliente_ruc === '')) {
        try {
            $stmtCliente = $pdo->prepare("SELECT nombre, numero FROM {$dbase}.clientes WHERE id = :id LIMIT 1");
            $stmtCliente->execute([':id' => $id_cliente]);
            $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($cliente_nombre === '') {
                $cliente_nombre = trim((string)($cliente['nombre'] ?? ''));
            }
            if ($cliente_ruc === '') {
                $cliente_ruc = trim((string)($cliente['numero'] ?? ''));
            }
        } catch (Throwable $e) {
        }
    }

    if ($cliente_nombre === '') $cliente_nombre = 'Proveedor pendiente';
    if ($cliente_ruc === '') $cliente_ruc = '-';

    $pdo->beginTransaction();
    try {
        $fecha = date('Y-m-d H:i:s');
        $subtotal = 0.0;
        $itemsPayload = [];

        foreach ($items as $item) {
            $idProducto = (int)($item['id'] ?? $item['id_producto'] ?? 0);
            $cantidad = (float)($item['cantidad'] ?? 0);
            $precio = (float)($item['precio'] ?? 0);
            if ($cantidad <= 0) continue;
            $importe = round($cantidad * $precio, 2);
            $subtotal += $importe;
            $itemsPayload[] = [
                'id_producto' => $idProducto,
                'codigo' => (string)($item['codigo'] ?? ''),
                'descripcion' => (string)($item['descripcion'] ?? 'Producto'),
                'cantidad' => $cantidad,
                'precio' => $precio,
                'importe' => $importe,
            ];
        }

        if ($subtotal <= 0 || empty($itemsPayload)) {
            throw new Exception('El total del pedido es inválido');
        }

        $stmtPedido = $pdo->prepare("
            INSERT INTO {$dbase}.pedidos
                (fecha, id_sucursal, id_login, id_cliente, importe)
            VALUES
                (:fecha, :id_sucursal, :id_login, :id_cliente, :importe)
        ");
        $stmtPedido->execute([
            ':fecha' => $fecha,
            ':id_sucursal' => $id_sucursal,
            ':id_login' => $id_usuario,
            ':id_cliente' => $id_cliente > 0 ? $id_cliente : null,
            ':importe' => round($subtotal, 2),
        ]);

        $id_pedido = (int)$pdo->lastInsertId();
        if ($id_pedido <= 0) {
            throw new Exception('No se pudo generar el pedido');
        }

        $stmtItem = $pdo->prepare("
            INSERT INTO {$dbase}.item_pedidos
                (id_pedido, id_sucursal, id_cliente, fecha, id_producto, cantidad, precio, importe, id_login)
            VALUES
                (:id_pedido, :id_sucursal, :id_cliente, :fecha, :id_producto, :cantidad, :precio, :importe, :id_login)
        ");

        foreach ($itemsPayload as $item) {
            $stmtItem->execute([
                ':id_pedido' => $id_pedido,
                ':id_sucursal' => $id_sucursal,
                ':id_cliente' => $id_cliente > 0 ? $id_cliente : null,
                ':fecha' => $fecha,
                ':id_producto' => $item['id_producto'] > 0 ? $item['id_producto'] : null,
                ':cantidad' => $item['cantidad'],
                ':precio' => round($item['precio'], 2),
                ':importe' => round($item['importe'], 2),
                ':id_login' => $id_usuario,
            ]);
        }

        $pdo->commit();

        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Pedido guardado correctamente',
            'data' => [
                'id_factura' => $id_pedido,
                'id_pedido' => $id_pedido,
                'nro_factura' => 'PED-' . str_pad((string)$id_pedido, 7, '0', STR_PAD_LEFT),
                'fecha' => $fecha,
                'total' => round($subtotal, 2),
                'cliente' => $cliente_nombre,
                'cliente_ruc' => $cliente_ruc,
                'tipo_documento' => 'pedido',
                'medio_cobro' => 'pedido',
                'items' => $itemsPayload,
            ],
        ]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('POS Pedido Error: ' . $e->getMessage());
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function ensurePedidoTables(PDO $pdo, string $dbase): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbase}.pedidos (
            id_pedido int NOT NULL AUTO_INCREMENT,
            fecha datetime DEFAULT NULL,
            id_sucursal int DEFAULT NULL,
            id_login int DEFAULT NULL,
            id_cliente int DEFAULT NULL,
            importe decimal(12,2) DEFAULT NULL,
            PRIMARY KEY (id_pedido)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbase}.item_pedidos (
            id_item int NOT NULL AUTO_INCREMENT,
            id_pedido int DEFAULT NULL,
            id_sucursal int DEFAULT NULL,
            id_cliente int DEFAULT NULL,
            fecha datetime DEFAULT NULL,
            id_producto int DEFAULT NULL,
            cantidad decimal(12,2) DEFAULT NULL,
            precio decimal(12,2) DEFAULT NULL,
            importe decimal(12,2) DEFAULT NULL,
            id_login int DEFAULT NULL,
            PRIMARY KEY (id_item)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1
    ");
}
