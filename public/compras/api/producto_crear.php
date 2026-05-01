<?php
/**
 * API - Crear Producto Rápido desde Compras
 * Crea un producto en tblproductos con datos mínimos
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/bootstrap.php';

Session::start();

Permission::requirePermission('app_grid_factura_compras', 'priv_insert');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty(trim($input['nombre'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El nombre del producto es requerido']);
    exit;
}

$id_empresa = Session::get('id_empresa', 169);

try {
    // Conexión a la BD de la empresa
    $masterPdo = Database::getMasterConnection();
    $stmt = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception("Empresa no encontrada");
    }

    $dbHost = !empty($empresa['server']) ? $empresa['server'] : 'localhost';
    $dbUser = !empty($empresa['user']) ? $empresa['user'] : 'sistemax';
    $dbPass = 'Armagedon123';
    $dbName = !empty($empresa['dbase']) ? $empresa['dbase'] : 'serproc1';

    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $nombre = trim($input['nombre']);
    $codigo = trim($input['codigo'] ?? '');
    $precioCompra = (float)($input['precio_compra'] ?? 0);
    $precioVenta = (float)($input['precio_venta'] ?? 0);
    $iva = (int)($input['iva'] ?? 10);

    // Auto-generar código si está vacío
    if (empty($codigo)) {
        $stmtMax = $pdo->query("SELECT MAX(CAST(cve_producto AS UNSIGNED)) AS max_code FROM {$dbName}.tblproductos WHERE cve_producto REGEXP '^[0-9]+$'");
        $maxCode = $stmtMax->fetch()['max_code'] ?? 0;
        $codigo = str_pad((int)$maxCode + 1, 6, '0', STR_PAD_LEFT);
    }

    // Verificar código duplicado
    $stmtCheck = $pdo->prepare("SELECT idproducto FROM {$dbName}.tblproductos WHERE cve_producto = :codigo LIMIT 1");
    $stmtCheck->execute([':codigo' => $codigo]);
    if ($stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => "Ya existe un producto con el código '{$codigo}'"]);
        exit;
    }

    // Insertar producto
    $stmtInsert = $pdo->prepare("
        INSERT INTO {$dbName}.tblproductos 
        (cve_producto, desproducto, precio_compra, precio_venta, iva, Estado, stock_minimo, stock_maximo)
        VALUES (:codigo, :nombre, :precio_compra, :precio_venta, :iva, 1, 0, 0)
    ");
    $stmtInsert->execute([
        ':codigo' => $codigo,
        ':nombre' => $nombre,
        ':precio_compra' => $precioCompra,
        ':precio_venta' => $precioVenta,
        ':iva' => $iva
    ]);

    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Producto creado correctamente',
        'producto' => [
            'id' => (int)$newId,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'precio_compra' => $precioCompra,
            'precio_venta' => $precioVenta,
            'iva' => $iva
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al crear producto: ' . $e->getMessage()
    ]);
}
