<?php
header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$id_empresa_session = (int)($_SESSION['id_empresa'] ?? 0);
$id_empresa_get = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : 0;
$id_empresa = $id_empresa_session > 0 ? $id_empresa_session : ($id_empresa_get > 0 ? $id_empresa_get : 169);

if (!$id_factura) {
    echo json_encode(['success' => false, 'message' => 'ID Factura requerido']);
    exit;
}

try {
    // Obtener conexión a la base de datos de la empresa
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    
    // 1. Get Header
    $stmtHeader = $pdo->prepare("
        SELECT 
            f.*, 
            COALESCE(f.forma_pago, 1) as forma_pago,
            c.id, c.nombre as cliente_nombre, 
            IF(LOCATE('-', c.numero) > 0, SUBSTRING_INDEX(c.numero, '-', 1), c.numero) as cliente_ruc,
            IF(LOCATE('-', c.numero) > 0, SUBSTRING_INDEX(c.numero, '-', -1), NULL) as cliente_dv, 
            IF(c.direccion IS NULL OR c.direccion = '', 'Sin Dirección', c.direccion) as direccion, 
            IF(c.telefono IS NULL OR c.telefono = '', 'Sin Teléfono', c.telefono) as telefono,
            IF(c.email IS NULL OR c.email = '', 'Sin Email', c.email) as email
        FROM $dbName.factura_ventas f
        LEFT JOIN $dbName.clientes c ON f.id_cliente = c.id
        WHERE f.id_factura = :id
    ");
    $stmtHeader->execute([':id' => $id_factura]);
    $factura = $stmtHeader->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        throw new Exception("Factura no encontrada");
    }

    // 2. Get Details (Items) from extracto_productos
    // Compatibilidad: tblproductos puede tener desproducto/cve_producto en lugar de descripcion/codigo
    $prodDescCol = 'desproducto';
    $prodCodeCol = 'cve_producto';
    try {
        $prodCols = [];
        $stmtCols = $pdo->query("DESCRIBE $dbName.tblproductos");
        while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
            $prodCols[] = strtolower((string)$col['Field']);
        }
        if (in_array('descripcion', $prodCols, true)) {
            $prodDescCol = 'descripcion';
        }
        if (in_array('codigo', $prodCols, true)) {
            $prodCodeCol = 'codigo';
        }
    } catch (Throwable $e) {
        // Continuar con defaults legacy
    }

    $stmtItems = $pdo->prepare("
        SELECT 
            d.idproducto as id, 
            d.salida as cantidad, 
            d.precio as precio,
            (d.salida * d.precio) as subtotal,
            COALESCE(d.descripcion, p.$prodDescCol, 'Producto') as descripcion, 
            COALESCE(d.codigo, p.$prodCodeCol, '') as codigo,
            COALESCE(d.tipo_iva, p.iva, 10) as iva
        FROM $dbName.extracto_productos d
        LEFT JOIN $dbName.tblproductos p ON d.idproducto = p.idproducto
        WHERE d.idfactura = :id AND d.salida > 0
    ");
    $stmtItems->execute([':id' => $id_factura]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Format items for POS Cart
    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['cantidad'] = (float)$item['cantidad'];
        $item['precio'] = (float)$item['precio'];
        // Ensure desc/code are not null
        $item['descripcion'] = $item['descripcion'] ?? 'Producto ' . $item['id'];
        $item['codigo'] = $item['codigo'] ?? '';
    }

    // Format Client
    $cliente = null;
    if ($factura['id_cliente']) {
        $cliente = [
            'id' => (int)$factura['id'], // Changed from idcliente to id (from SELECT c.id)
            'nombre' => $factura['cliente_nombre'],
            'ruc' => $factura['cliente_ruc'],
            'dv' => $factura['cliente_dv'],
            'direccion' => $factura['direccion'],
            'telefono' => $factura['telefono'],
            'email' => $factura['email']
        ];
    }

    // Determine doc type from CDC or other fields
    $docType = 'comun';
    if (!empty($factura['cdc'])) {
        $docType = 'electro';
    } elseif (!empty($factura['nro_factura'])) {
        $docType = 'auto';
    }

    echo json_encode([
        'success' => true,
        'factura' => [
            'id_factura' => $id_factura,
            'id' => $id_factura,
            'nro_factura' => $factura['nro_factura'],
            'fecha' => $factura['fecha'],
            'total' => $factura['total'],
            'forma_pago' => $factura['condicion_venta'], // 'contado', 'credito' usually
            'doc_type' => $docType,
            'cdc' => $factura['cdc'] ?? null,
            'prot_cons_lote_sifen' => $factura['prot_cons_lote_sifen'] ?? null,
            'estado_sifen' => $factura['estado_sifen'], /* For validation */
            'xml_respuesta' => $factura['xml_respuesta']
        ],
        'cliente' => $cliente,
        'items' => $items
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
