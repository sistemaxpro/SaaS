<?php
/**
 * POS API - Kardex por Producto y Sucursal
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

$idProducto = (int)($_GET['id'] ?? 0);
$idSucursal = (int)($_GET['id_sucursal'] ?? 0);
$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

if ($idProducto <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

try {
    $masterConn = getMasterConnection();
    $idLogin = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
    if ($idLogin > 0) {
        $stmtUser = $masterConn->prepare('SELECT id_empresa FROM sec_users WHERE id_login = :id LIMIT 1');
        $stmtUser->execute([':id' => $idLogin]);
        $emp = (int)$stmtUser->fetchColumn();
        if ($emp > 0) {
            $idEmpresa = $emp;
        }
    }

    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

    $stmtProd = $pdo->prepare("SELECT idproducto, cve_producto AS codigo, desproducto AS descripcion FROM $dbName.tblproductos WHERE idproducto = :id LIMIT 1");
    $stmtProd->execute([':id' => $idProducto]);
    $producto = $stmtProd->fetch(PDO::FETCH_ASSOC);
    if (!$producto) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
        exit;
    }

    $stmtStockSuc = $pdo->prepare("
        SELECT 
            s.id_sucursal,
            s.sucursal AS nombre,
            s.ciudad,
            COALESCE(ep.stock, 0) AS stock
        FROM $dbName.sucursales s
        LEFT JOIN (
            SELECT id_sucursal, SUM(entrada - salida) AS stock
            FROM $dbName.extracto_productos
            WHERE idproducto = :id_producto
            GROUP BY id_sucursal
        ) ep ON ep.id_sucursal = s.id_sucursal
        ORDER BY s.sucursal
    ");
    $stmtStockSuc->execute([':id_producto' => $idProducto]);
    $sucursales = $stmtStockSuc->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sucursal = null;
    if ($idSucursal > 0) {
        foreach ($sucursales as $suc) {
            if ((int)($suc['id_sucursal'] ?? 0) === $idSucursal) {
                $sucursal = $suc;
                break;
            }
        }
        if (!$sucursal) {
            $stmtSuc = $pdo->prepare("SELECT id_sucursal, sucursal AS nombre, ciudad FROM $dbName.sucursales WHERE id_sucursal = :id LIMIT 1");
            $stmtSuc->execute([':id' => $idSucursal]);
            $sucursal = $stmtSuc->fetch(PDO::FETCH_ASSOC) ?: [
                'id_sucursal' => $idSucursal,
                'nombre' => 'Sucursal ' . $idSucursal,
                'ciudad' => '',
                'stock' => 0
            ];
        }
    }

    $cols = [];
    $stmtCols = $pdo->query("DESCRIBE $dbName.extracto_productos");
    while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = strtolower((string)$col['Field']);
    }

    $has = static function (string $name) use ($cols): bool {
        return in_array(strtolower($name), $cols, true);
    };

    // Normaliza id_sucursal faltante usando la factura relacionada (idfactura -> factura_ventas.id_factura).
    if ($has('id_sucursal') && $has('idfactura')) {
        try {
            $fvCols = [];
            $stmtFvCols = $pdo->query("DESCRIBE $dbName.factura_ventas");
            while ($col = $stmtFvCols->fetch(PDO::FETCH_ASSOC)) {
                $fvCols[] = strtolower((string)$col['Field']);
            }
            $fvHas = static function (string $name) use ($fvCols): bool {
                return in_array(strtolower($name), $fvCols, true);
            };

            $fvSucursalCol = $fvHas('id_sucursal') ? 'id_sucursal' : ($fvHas('idsucursal') ? 'idsucursal' : '');
            if ($fvSucursalCol !== '') {
                $sqlFixSucursal = "UPDATE $dbName.extracto_productos ep
                    INNER JOIN $dbName.factura_ventas fv ON fv.id_factura = ep.idfactura
                    SET ep.id_sucursal = fv.$fvSucursalCol
                    WHERE ep.idproducto = :id_producto
                      AND ep.idfactura IS NOT NULL
                      AND ep.idfactura > 0
                      AND (ep.id_sucursal IS NULL OR ep.id_sucursal = 0 OR TRIM(CAST(ep.id_sucursal AS CHAR)) = '')
                      AND fv.$fvSucursalCol IS NOT NULL
                      AND fv.$fvSucursalCol > 0";
                $stmtFixSucursal = $pdo->prepare($sqlFixSucursal);
                $stmtFixSucursal->execute([':id_producto' => $idProducto]);
            }

            $fvFechaCol = $fvHas('fecha') ? 'fecha' : ($fvHas('fecha_emision') ? 'fecha_emision' : '');
            if ($has('fecha') && $fvFechaCol !== '') {
                $sqlFixFecha = "UPDATE $dbName.extracto_productos ep
                    INNER JOIN $dbName.factura_ventas fv ON fv.id_factura = ep.idfactura
                    SET ep.fecha = fv.$fvFechaCol
                    WHERE ep.idproducto = :id_producto
                      AND ep.idfactura IS NOT NULL
                      AND ep.idfactura > 0
                      AND (ep.fecha IS NULL OR TRIM(CAST(ep.fecha AS CHAR)) = '' OR ep.fecha = '0000-00-00' OR ep.fecha = '0000-00-00 00:00:00')
                      AND fv.$fvFechaCol IS NOT NULL
                      AND TRIM(CAST(fv.$fvFechaCol AS CHAR)) <> ''
                      AND fv.$fvFechaCol <> '0000-00-00'
                      AND fv.$fvFechaCol <> '0000-00-00 00:00:00'";
                $stmtFixFecha = $pdo->prepare($sqlFixFecha);
                $stmtFixFecha->execute([':id_producto' => $idProducto]);
            }
        } catch (Throwable $ignoreFix) {
            // No bloquear el kardex si el ajuste preventivo falla en una empresa con esquema distinto.
        }
    }

    $select = [];
    $select[] = $has('id') ? 'ep.id AS id_mov' : '0 AS id_mov';
    $select[] = $has('fecha') ? 'ep.fecha' : 'NOW() AS fecha';
    $select[] = $has('codigo') ? 'ep.codigo' : '"" AS codigo';
    $select[] = $has('descripcion') ? 'ep.descripcion' : '"" AS descripcion';
    $select[] = $has('entrada') ? 'ep.entrada' : '0 AS entrada';
    $select[] = $has('salida') ? 'ep.salida' : '0 AS salida';
    $select[] = $has('precio') ? 'ep.precio' : '0 AS precio';
    $select[] = $has('costo') ? 'ep.costo' : '0 AS costo';
    $select[] = $has('idfactura') ? 'ep.idfactura' : 'NULL AS idfactura';
    $select[] = $has('id_login') ? 'ep.id_login' : 'NULL AS id_login';
    $select[] = $has('id_sucursal') ? 'COALESCE(ep.id_sucursal, 0) AS id_sucursal' : '0 AS id_sucursal';
    $select[] = $has('id_sucursal') ? "COALESCE(s.sucursal, CONCAT('Sucursal ', COALESCE(ep.id_sucursal, 0))) AS sucursal_nombre" : "'Global' AS sucursal_nombre";

    $where = [
        'ep.idproducto = :id_producto'
    ];
    if ($idSucursal > 0 && $has('id_sucursal')) {
        $where[] = 'ep.id_sucursal = :id_sucursal';
    }
    if ($has('estado')) {
        $where[] = "(
            ep.estado = 1 OR
            ep.estado = '1' OR
            LOWER(TRIM(CAST(ep.estado AS CHAR))) = 'activo' OR
            LOWER(TRIM(CAST(ep.estado AS CHAR))) = 'activa'
        )";
    }
    if ($has('anulado')) {
        $where[] = "(
            ep.anulado = 0 OR
            ep.anulado = '0' OR
            ep.anulado IS NULL OR
            TRIM(CAST(ep.anulado AS CHAR)) = '' OR
            LOWER(TRIM(CAST(ep.anulado AS CHAR))) = 'no'
        )";
    }

    $orderBy = $has('fecha') ? 'ep.fecha DESC' : ($has('id') ? 'ep.id DESC' : 'ep.idproducto DESC');

    $joinSucursal = $has('id_sucursal')
        ? "LEFT JOIN $dbName.sucursales s ON s.id_sucursal = ep.id_sucursal\n"
        : '';

    $sql = "SELECT " . implode(', ', $select) . "\n"
        . "FROM $dbName.extracto_productos ep\n"
        . $joinSucursal
        . "WHERE " . implode(' AND ', $where) . "\n"
        . "ORDER BY $orderBy\n"
        . "LIMIT 200";

    $stmtMov = $pdo->prepare($sql);
    $paramsMov = [':id_producto' => $idProducto];
    if ($idSucursal > 0 && $has('id_sucursal')) {
        $paramsMov[':id_sucursal'] = $idSucursal;
    }
    $stmtMov->execute($paramsMov);
    $movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

    // Stock actual de referencia (misma lógica que modal de detalle: estado = 1).
    $stockActual = 0.0;
    try {
        $stockWhere = [
            'ep.idproducto = :id_producto'
        ];
        if ($idSucursal > 0 && $has('id_sucursal')) {
            $stockWhere[] = 'ep.id_sucursal = :id_sucursal';
        }
        if ($has('estado')) {
            $stockWhere[] = 'ep.estado = 1';
        }
        $sqlStock = "SELECT COALESCE(SUM(COALESCE(ep.entrada,0) - COALESCE(ep.salida,0)),0) AS stock_actual
            FROM $dbName.extracto_productos ep
            WHERE " . implode(' AND ', $stockWhere);
        $stmtStock = $pdo->prepare($sqlStock);
        $paramsStock = [':id_producto' => $idProducto];
        if ($idSucursal > 0 && $has('id_sucursal')) {
            $paramsStock[':id_sucursal'] = $idSucursal;
        }
        $stmtStock->execute($paramsStock);
        $stockActual = (float)$stmtStock->fetchColumn();
    } catch (Throwable $ignoreStock) {
        $stockActual = 0.0;
    }

    // Enriquecer con datos de factura y usuario para visualización.
    $facturaIds = [];
    $userIds = [];
    foreach ($movimientos as $mov) {
        $fid = (int)($mov['idfactura'] ?? 0);
        if ($fid > 0) $facturaIds[$fid] = true;
        $uid = (int)($mov['id_login'] ?? 0);
        if ($uid > 0) $userIds[$uid] = true;
    }

    $facturaMap = [];
    if (!empty($facturaIds)) {
        $fCols = [];
        $stmtFCols = $pdo->query("DESCRIBE $dbName.factura_ventas");
        while ($col = $stmtFCols->fetch(PDO::FETCH_ASSOC)) {
            $fCols[] = strtolower((string)$col['Field']);
        }
        $fHas = static function (string $name) use ($fCols): bool {
            return in_array(strtolower($name), $fCols, true);
        };
        $nroCol = $fHas('nro_factura') ? 'nro_factura' : ($fHas('numero') ? 'numero' : 'id_factura');
        $idCliCol = $fHas('id_cliente') ? 'id_cliente' : ($fHas('idcliente') ? 'idcliente' : '');
        $cliCol = $fHas('cliente') ? 'cliente' : ($fHas('nombre_cliente') ? 'nombre_cliente' : '');

        $ids = array_keys($facturaIds);
        $place = implode(',', array_fill(0, count($ids), '?'));

        $joinClientes = false;
        $nombreClienteExpr = "''";
        if ($idCliCol !== '') {
            try {
                $cCols = [];
                $stmtCCols = $pdo->query("DESCRIBE $dbName.clientes");
                while ($col = $stmtCCols->fetch(PDO::FETCH_ASSOC)) {
                    $cCols[] = strtolower((string)$col['Field']);
                }
                $cHas = static function (string $name) use ($cCols): bool {
                    return in_array(strtolower($name), $cCols, true);
                };
                if ($cHas('id')) {
                    $joinClientes = true;
                    if ($cHas('nombre')) {
                        $nombreClienteExpr = "COALESCE(c.nombre, '')";
                    } elseif ($cHas('razon_social')) {
                        $nombreClienteExpr = "COALESCE(c.razon_social, '')";
                    } elseif ($cHas('cliente')) {
                        $nombreClienteExpr = "COALESCE(c.cliente, '')";
                    } else {
                        $nombreClienteExpr = "''";
                    }
                }
            } catch (Throwable $ignore) {
                $joinClientes = false;
            }
        }

        if (!$joinClientes && $cliCol !== '') {
            $nombreClienteExpr = "COALESCE(f.$cliCol, '')";
        } elseif (!$joinClientes) {
            $nombreClienteExpr = "''";
        }

        $sqlFact = "SELECT f.id_factura, f.$nroCol AS nro_factura, $nombreClienteExpr AS cliente_nombre"
            . " FROM $dbName.factura_ventas f";
        if ($joinClientes && $idCliCol !== '') {
            $sqlFact .= " LEFT JOIN $dbName.clientes c ON c.id = f.$idCliCol";
        }
        $sqlFact .= " WHERE f.id_factura IN ($place)";

        $stmtFact = $pdo->prepare($sqlFact);
        $stmtFact->execute($ids);
        while ($r = $stmtFact->fetch(PDO::FETCH_ASSOC)) {
            $idF = (int)($r['id_factura'] ?? 0);
            if ($idF <= 0) continue;
            $nro = trim((string)($r['nro_factura'] ?? ''));
            $cli = trim((string)($r['cliente_nombre'] ?? ''));
            $facturaMap[$idF] = [
                'nro_factura' => $nro,
                'cliente_nombre' => $cli,
                'factura_text' => $nro !== '' ? ($nro . ($cli !== '' ? (' · ' . $cli) : '')) : ('ID ' . $idF)
            ];
        }
    }

    $userMap = [];
    if (!empty($userIds)) {
        $uCols = [];
        $stmtUCols = $masterConn->query("DESCRIBE sec_users");
        while ($col = $stmtUCols->fetch(PDO::FETCH_ASSOC)) {
            $uCols[] = strtolower((string)$col['Field']);
        }
        $uHas = static function (string $name) use ($uCols): bool {
            return in_array(strtolower($name), $uCols, true);
        };

        $displayExpr = 'CAST(id_login AS CHAR)';
        if ($uHas('nombre') && $uHas('login')) {
            $displayExpr = "TRIM(CONCAT(COALESCE(nombre,''), CASE WHEN COALESCE(nombre,'') <> '' AND COALESCE(login,'') <> '' THEN ' (' ELSE '' END, COALESCE(login,''), CASE WHEN COALESCE(nombre,'') <> '' AND COALESCE(login,'') <> '' THEN ')' ELSE '' END))";
        } elseif ($uHas('nombre')) {
            $displayExpr = "COALESCE(nombre, CAST(id_login AS CHAR))";
        } elseif ($uHas('login')) {
            $displayExpr = "COALESCE(login, CAST(id_login AS CHAR))";
        } elseif ($uHas('usuario')) {
            $displayExpr = "COALESCE(usuario, CAST(id_login AS CHAR))";
        }

        $ids = array_keys($userIds);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $sqlUser = "SELECT id_login, $displayExpr AS usuario_nombre FROM sec_users WHERE id_login IN ($place)";
        $stmtUserNames = $masterConn->prepare($sqlUser);
        $stmtUserNames->execute($ids);
        while ($r = $stmtUserNames->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)($r['id_login'] ?? 0);
            if ($uid <= 0) continue;
            $name = trim((string)($r['usuario_nombre'] ?? ''));
            $userMap[$uid] = $name !== '' ? $name : ('ID ' . $uid);
        }
    }

    foreach ($movimientos as &$mov) {
        $entrada = (float)($mov['entrada'] ?? 0);
        $salida = (float)($mov['salida'] ?? 0);
        if ($entrada > 0) {
            $mov['tipo_mov'] = 'Entrada';
            $mov['cantidad'] = $entrada;
        } elseif ($salida > 0) {
            $mov['tipo_mov'] = 'Salida';
            $mov['cantidad'] = $salida;
        } else {
            $mov['tipo_mov'] = 'Ajuste';
            $mov['cantidad'] = 0;
        }

        $fid = (int)($mov['idfactura'] ?? 0);
        $mov['factura_nro'] = $facturaMap[$fid]['nro_factura'] ?? '';
        $mov['factura_cliente'] = $facturaMap[$fid]['cliente_nombre'] ?? '';
        $mov['factura_text'] = $facturaMap[$fid]['factura_text'] ?? ($fid > 0 ? ('ID ' . $fid) : '-');

        $uid = (int)($mov['id_login'] ?? 0);
        $mov['usuario_nombre'] = $userMap[$uid] ?? ($uid > 0 ? ('ID ' . $uid) : '-');
    }
    unset($mov);

    echo json_encode([
        'success' => true,
        'scope' => $idSucursal > 0 ? 'sucursal' : 'global',
        'producto' => $producto,
        'sucursal' => $sucursal,
        'sucursales' => $sucursales,
        'stock_actual' => $stockActual,
        'movimientos' => $movimientos
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
