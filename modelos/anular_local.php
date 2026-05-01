<?php

/**
 * anular_local.php
 * Anulación puramente local para facturas RECHAZADAS por SIFEN.
 * No intenta conectar con SIFEN.
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Recibir datos (JSON o POST)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?: $_POST;

$id_factura = isset($input['id_factura']) ? (int)$input['id_factura'] : 0;

if (!$id_factura) {
    echo json_encode(['success' => false, 'message' => 'ID de factura requerido']);
    exit;
}

// Configuración de BD desde sesión
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';
$id_empresa = isset($input['id_empresa']) ? (int)$input['id_empresa'] : ($_SESSION['id_empresa'] ?? 169);

require_once __DIR__ . '/../lib/producto_stock_snapshot.php';

function anularLocalTableExists(PDO $pdo, string $db, string $table): bool
{
    static $cache = [];
    $key = $db . '.' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = :db
          AND table_name = :table
    ");
    $stmt->execute([
        ':db' => $db,
        ':table' => $table,
    ]);
    $cache[$key] = (int)$stmt->fetchColumn() > 0;
    return $cache[$key];
}

function anularLocalColumnExists(PDO $pdo, string $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $db . '.' . $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = :db
          AND table_name = :table
          AND column_name = :column
    ");
    $stmt->execute([
        ':db' => $db,
        ':table' => $table,
        ':column' => $column,
    ]);
    $cache[$key] = (int)$stmt->fetchColumn() > 0;
    return $cache[$key];
}

function anularLocalStockSnapshotReady(PDO $pdo, string $db): bool
{
    return sxProductoStockSnapshotReady($pdo, $db);
}

function anularLocalStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
{
    sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
}

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Obtener el nombre de la base de datos de la empresa
    $stmtDB = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    if (!$dbName) throw new Exception("Base de datos de la empresa no encontrada");

    // 2. Ejecutar la anulación local
    // estado = 0 (Inactiva/Anulada)
    $sql = "UPDATE $dbName.factura_ventas 
            SET estado = 0, 
                est_res_anul = 'Anulado Localmente (Rechazado SIFEN/Común)',
                estado_sifen = 'Anulado',
                mensaje_sifen = CONCAT('Anulación Manual: ', IFNULL(mensaje_sifen, ''))
            WHERE id_factura = :id";

    $stmtUpdate = $pdo->prepare($sql);
    $stmtUpdate->execute([':id' => $id_factura]);

    // 3. Anular Extracto de Caja
    $sqlCaja = "UPDATE $dbName.extracto_caja SET estado = 0 WHERE tabla_relacion = 'factura_ventas' AND id_relacion = :id";
    $pdo->prepare($sqlCaja)->execute([':id' => $id_factura]);

    // 4. Anular Extracto de Cliente (Cuenta Corriente y Pagos)
    $sqlCli = "UPDATE $dbName.extracto_cliente SET estado = 0 WHERE id_factura = :id";
    $pdo->prepare($sqlCli)->execute([':id' => $id_factura]);

    // 5. Anular Documentos a Crédito (Cuotas)
    try {
        $sqlDoc = "UPDATE $dbName.documentos SET estado = 0 WHERE id_factura = :id";
        $pdo->prepare($sqlDoc)->execute([':id' => $id_factura]);
    } catch (Exception $eDoc) { /* Ignore if table doesn't exist */
    }

    // 6. Restaurar Stock y Anular Extracto de Productos
    // Primero obtenemos los items para saber qué devolver
    // Asumimos que 'salida' fue la cantidad vendida
    $stmtItems = $pdo->prepare("
        SELECT idproducto,
               COALESCE(NULLIF(id_sucursal, 0), 1) AS id_sucursal,
               salida
        FROM $dbName.extracto_productos
        WHERE idfactura = :id
          AND estado = 1
    ");
    $stmtItems->execute([':id' => $id_factura]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        if ($item['idproducto'] && $item['salida'] > 0) {
            // Devolver al stock
            $updStock = $pdo->prepare("UPDATE $dbName.tblproductos SET saldo = saldo + :cant WHERE idproducto = :prod");
            $updStock->execute([
                ':cant' => $item['salida'],
                ':prod' => $item['idproducto']
            ]);
            anularLocalStockSnapshotAdjust(
                $pdo,
                $dbName,
                (int)$item['idproducto'],
                (int)($item['id_sucursal'] ?? 1),
                (float)$item['salida']
            );
        }
    }

    // Finalmente marcamos como anulados en el extracto
    $sqlProd = "UPDATE $dbName.extracto_productos SET estado = 0 WHERE idfactura = :id";
    $pdo->prepare($sqlProd)->execute([':id' => $id_factura]);

    echo json_encode([
        'success' => true,
        'message' => 'Factura anulada localmente con éxito. Stock restaurado y movimientos cancelados.'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
