<?php
header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../lib/producto_stock_snapshot.php';

if (!function_exists('reenviarStockSnapshotReady')) {
    function reenviarStockSnapshotReady(PDO $pdo, string $db): bool
    {
        return sxProductoStockSnapshotReady($pdo, $db);
    }
}

if (!function_exists('reenviarStockSnapshotAdjust')) {
    function reenviarStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
    {
        sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
    }
}
require_once 'sifen_lib.php';

// Init user data
$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : 169;
$usuario = $_SESSION['usuario'] ?? 'api';

// Read JSON Input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit;
}

$id_factura = (int)$input['id_factura'];
if (!$id_factura) {
    echo json_encode(['success' => false, 'message' => 'ID Factura requerido para reenvío']);
    exit;
}

$items = $input['items'];
$total = (float)$input['total'];
$id_cliente = (int)$input['id_cliente'];
$doc_type = $input['doc_type']; // 'electro', 'auto', 'comun'
$payment_method = $input['payment_method'];
$cash_received = (float)($input['cash_received'] ?? 0);
$cash_change = (float)($input['cash_change'] ?? 0);
$id_caja = (int)($input['id_caja'] ?? 0);
$idUsuario = (int)($input['id_usuario'] ?? 0);

try {
    // Obtener conexión a la base de datos de la empresa
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    $idSucursal = (int)($_POST['id_sucursal'] ?? $_GET['id_sucursal'] ?? $_SESSION['id_sucursal'] ?? 1);

    $pdo->beginTransaction();

    if (!$dbName) {
        throw new Exception("Base de datos de la empresa no encontrada");
    }

    // 1. REVERSE OLD STOCK
    // Get old items from extracto_productos
    $stmtOld = $pdo->prepare("SELECT idproducto as id_producto, id_sucursal, salida as cantidad FROM $dbName.extracto_productos WHERE idfactura = :id AND salida > 0");
    $stmtOld->execute([':id' => $id_factura]);
    $oldItems = $stmtOld->fetchAll(PDO::FETCH_ASSOC);

    $sqlExtractoIn = "INSERT INTO $dbName.extracto_productos
                (id_sucursal, idproducto, entrada, salida, fecha, id_login, idfactura)
                VALUES
                (:id_sucursal, :idproducto, :cantidad, 0, NOW(), :id_login, :id_factura)";
    $stmtExtractoIn = $pdo->prepare($sqlExtractoIn);

    $stmtStockUp = $pdo->prepare("UPDATE $dbName.tblproductos SET saldo = saldo + :qty WHERE idproducto = :id"); // saldo vs stock? venta.php used 'saldo' in line 316

    foreach ($oldItems as $old) {
        // Log movement (Return to stock)
        $stmtExtractoIn->execute([
            ':idproducto' => $old['id_producto'],
            ':id_sucursal' => (int)($old['id_sucursal'] ?? $idSucursal),
            ':cantidad' => $old['cantidad'],
            ':id_login' => $usuario, // Or ID
            ':id_factura' => $id_factura
        ]);

        // Update Stock
        $stmtStockUp->execute([':qty' => $old['cantidad'], ':id' => $old['id_producto']]);
        reenviarStockSnapshotAdjust($pdo, $dbName, (int)$old['id_producto'], (int)($old['id_sucursal'] ?? $idSucursal), (float)$old['cantidad']);
    }

    // 2. UPDATE HEADER
    $condicion = ($payment_method === 'credito') ? 2 : 1; // 1: Contado, 2: Credito

    // Determine payment type text
    $forma_pago_txt = 'Efectivo';
    if ($payment_method === 'tarjeta') $forma_pago_txt = 'Tarjeta';
    if ($payment_method === 'transferencia') $forma_pago_txt = 'Transferencia';
    if ($payment_method === 'qr' || $payment_method === 'pix') $forma_pago_txt = 'PIX';
    if ($payment_method === 'credito') $forma_pago_txt = 'Crédito';

    // Reset fields for re-emission
    $stmtUpd = $pdo->prepare("
        UPDATE $dbName.factura_ventas SET 
            id_cliente = :cli,
            total = :total,
            iva10 = :iva10,
            iva5 = :iva5,
            exenta = :exenta,
            condicion_venta = :cond,
            forma_pago = :fp,
            estado_sifen = 'Pendiente',
            xml_respuesta = NULL,
            cdc = NULL,
            qr_sifen = NULL
        WHERE id_factura = :id
    ");

    // Calculate totals again
    $totalIva10 = 0;
    $totalIva5 = 0;
    $totalExento = 0;
    foreach ($items as $itm) {
        $sub = $itm['precio'] * $itm['cantidad'];
        $tasa = $itm['tasa_iva'] ?? 10;
        if ($tasa == 10) $totalIva10 += round($sub / 11);
        elseif ($tasa == 5) $totalIva5 += round($sub / 21);
        else $totalExento += $sub;
    }

    $stmtUpd->execute([
        ':cli' => $id_cliente,
        ':total' => $total,
        ':iva10' => $totalIva10,
        ':iva5' => $totalIva5,
        ':exenta' => $totalExento,
        ':cond' => $condicion,
        ':fp' => $forma_pago_txt,
        ':id' => $id_factura
    ]);

    // 3. DELETE OLD DETAILS (Only salida movements for this invoice)
    $stmtDel = $pdo->prepare("DELETE FROM $dbName.extracto_productos WHERE idfactura = :id AND salida > 0");
    $stmtDel->execute([':id' => $id_factura]);

    // 4. INSERT NEW DETAILS & UPDATE NEW STOCK (Handled by extracto_productos loop below)
    /*
    $stmtDet = $pdo->prepare("
        INSERT INTO $dbName.item_mercaderia_venta 
        (id_factura, id_operacion, id_referencia, codigo, cantidad, precio, tipo_iva, porc_iva, valor_iva, importe, id_sucursal, id_login, fecha)
        VALUES 
        (:id_factura, 1, :id_referencia, :codigo, :cantidad, :precio, :tipo_iva, :porc_iva, :valor_iva, :importe, 1, :id_login, NOW())
    ");
    */

    $sqlExtractoOut = "INSERT INTO $dbName.extracto_productos
                (id_sucursal, idproducto, entrada, salida, fecha, id_login, idfactura)
                VALUES
                (:id_sucursal, :idproducto, 0, :cantidad, NOW(), :id_login, :id_factura)";
    $stmtExtractoOut = $pdo->prepare($sqlExtractoOut);

    $stmtStockDown = $pdo->prepare("UPDATE $dbName.tblproductos SET saldo = saldo - :qty WHERE idproducto = :id");

    foreach ($items as $item) {
        $subtotal = $item['precio'] * $item['cantidad'];

        // Log movement (Exit from stock)
        $stmtExtractoOut->execute([
            ':idproducto' => $item['id'],
            ':id_sucursal' => $idSucursal,
            ':cantidad' => $item['cantidad'],
            ':id_login' => $idUsuario,
            ':id_factura' => $id_factura
        ]);

        // Deduct Stock
        $stmtStockDown->execute([':qty' => $item['cantidad'], ':id' => $item['id']]);
        reenviarStockSnapshotAdjust($pdo, $dbName, (int)$item['id'], $idSucursal, -1 * (float)$item['cantidad']);
    }

    $pdo->commit();

    // 5. EMIT SIFEN if Document Type is Electronic
    $sifenResult = [];
    if ($doc_type === 'electro') {
        // Require sifen_lib function
        // $pdo is already available
        // Need to fetch fresh data or pass $id_factura

        // Note: emitirFacturaElectronica inside sifen_lib.php expects $idFactura, $pdo, $dbName, $idEmpresa
        // We need to make sure sifen_lib is loaded and function is available.
        // I included 'sifen_lib.php' at top.

        $sifenResult = emitirFacturaElectronica($id_factura, $pdo, $dbName, $id_empresa);

        if (!$sifenResult['success']) {
            // If SIFEN fails, we still kept the DB update?
            // Yes, user can try again.
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Factura actualizada y reenviada',
        'data' => [
            'id_factura' => $id_factura,
            'sifen' => $sifenResult
        ]
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
