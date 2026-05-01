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
    $doc_type_raw = strtolower(trim((string)($data['doc_type'] ?? $data['tipo_documento'] ?? 'comun')));
    $tipo_documento_map = ['comun' => 0, 'auto' => 1, 'electro' => 3];
    $tipo_documento = $tipo_documento_map[$doc_type_raw] ?? (is_numeric($doc_type_raw) ? (int)$doc_type_raw : 0);
    $forma_pago_raw = strtolower(trim((string)($data['payment_method'] ?? $data['forma_pago'] ?? 'efectivo')));
    $forma_pago_map = ['efectivo' => 1, 'tarjeta' => 2, 'transferencia' => 3, 'qr' => 4, 'pix' => 4, 'credito' => 5, 'contado' => 1, 'pendiente' => 1];
    $forma_pago = $forma_pago_map[$forma_pago_raw] ?? (is_numeric($forma_pago_raw) ? (int)$forma_pago_raw : 1);

    if ($id_empresa <= 0 || $id_usuario <= 0) {
        throw new Exception('Empresa o usuario inválido');
    }
    if (empty($items) || !is_array($items)) {
        throw new Exception('El presupuesto está vacío');
    }

    $pdo = Database::getMasterConnection();
    $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
    $stmt->execute([$id_empresa]);
    $dbase = (string)$stmt->fetchColumn();
    if ($dbase === '') {
        throw new Exception('Empresa no encontrada');
    }

    ensurePresupuestoTables($pdo, $dbase);

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

    if ($cliente_nombre === '') $cliente_nombre = 'Cliente ocasional';
    if ($cliente_ruc === '') $cliente_ruc = '-';

    $pdo->beginTransaction();
    try {
        $subtotal = 0.0;
        $cantidadTotal = 0.0;
        $iva5 = 0.0;
        $iva10 = 0.0;
        $exenta = 0.0;
        $gravada5 = 0.0;
        $gravada10 = 0.0;
        $costoTotal = 0.0;

        foreach ($items as $item) {
            $precio = (float)($item['precio'] ?? 0);
            $cantidad = (float)($item['cantidad'] ?? 0);
            if ($cantidad <= 0) continue;
            $tasa = normalizeIvaRate($item['tasa_iva'] ?? 10);
            $importe = round($precio * $cantidad, 2);
            $subtotal += $importe;
            $cantidadTotal += $cantidad;
            $costoTotal += ((float)($item['costo'] ?? 0) * $cantidad);

            if ($tasa === 10) {
                $gravada10 += $importe;
                $iva10 += round($importe / 11, 2);
            } elseif ($tasa === 5) {
                $gravada5 += $importe;
                $iva5 += round($importe / 21, 2);
            } else {
                $exenta += $importe;
            }
        }

        if ($subtotal <= 0) {
            throw new Exception('El total del presupuesto es inválido');
        }

        $stmtNext = $pdo->query("SELECT COALESCE(MAX(id_factura), 0) + 1 FROM {$dbase}.presupuesto");
        $nextId = (int)$stmtNext->fetchColumn();
        if ($nextId <= 0) $nextId = 1;

        $nro_presupuesto = 'PRES-' . str_pad((string)$nextId, 7, '0', STR_PAD_LEFT);
        $fecha = date('Y-m-d');

        $stmtHeader = $pdo->prepare("
            INSERT INTO {$dbase}.presupuesto
            (
                forma_pago, estado, tipo_venta, tipo_documento, id_empresa,
                id_sucursal, id_login, fecha, vencimiento, nro_factura,
                tipo_cliente, id_cliente, ruc, cantidad, id_moneda, cambio,
                exenta, iva5, iva10, piva10, piva5, importe_gs, timbrado,
                pagado, pendiente, tipo_precio, descuento, total, costo
            ) VALUES (
                :forma_pago, 1, 1, :tipo_documento, :id_empresa,
                :id_sucursal, :id_login, :fecha_emision, :fecha_vencimiento, :nro_factura,
                1, :id_cliente, :ruc, :cantidad, 1, 1,
                :exenta, :iva5, :iva10, :piva10, :piva5, :importe_gs, '',
                0, :pendiente, 1, 0, :total, :costo
            )
        ");
        $stmtHeader->execute([
            ':forma_pago' => $forma_pago,
            ':tipo_documento' => $tipo_documento,
            ':id_empresa' => $id_empresa,
            ':id_sucursal' => $id_sucursal,
            ':id_login' => $id_usuario,
            ':fecha_emision' => $fecha,
            ':fecha_vencimiento' => $fecha,
            ':nro_factura' => $nro_presupuesto,
            ':id_cliente' => $id_cliente > 0 ? $id_cliente : null,
            ':ruc' => substr($cliente_ruc, 0, 20),
            ':cantidad' => round($cantidadTotal, 2),
            ':exenta' => round($exenta, 2),
            ':iva5' => round($iva5, 2),
            ':iva10' => round($iva10, 2),
            ':piva10' => round($gravada10, 2),
            ':piva5' => round($gravada5, 2),
            ':importe_gs' => round($subtotal, 2),
            ':pendiente' => round($subtotal, 2),
            ':total' => round($subtotal, 2),
            ':costo' => round($costoTotal, 2),
        ]);

        $id_presupuesto = (int)$pdo->lastInsertId();
        if ($id_presupuesto <= 0) {
            $stmtResolve = $pdo->prepare("SELECT id_factura FROM {$dbase}.presupuesto WHERE nro_factura = :nro ORDER BY id_factura DESC LIMIT 1");
            $stmtResolve->execute([':nro' => $nro_presupuesto]);
            $id_presupuesto = (int)$stmtResolve->fetchColumn();
        }
        if ($id_presupuesto <= 0) {
            throw new Exception('No se pudo resolver el ID del presupuesto');
        }

        $stmtItem = $pdo->prepare("
            INSERT INTO {$dbase}.presupuesto_item
            (
                idproducto, estado, id_sucursal, fecha, referencia, codigo, descripcion,
                entrada, salida, id_moneda, cambio, costo_gs, costo, precio_gs, precio,
                importe_gs, tipo_iva, importe, exenta, iva5, iva10, idfactura, id_login,
                id_sesion, obs, id_precio, descuento, total
            ) VALUES (
                :idproducto, 1, :id_sucursal, :fecha_item, NULL, :codigo, :descripcion,
                0, :salida, 1, 1, :costo_gs, :costo_unit, :precio_gs, :precio_unit,
                :importe_gs, :tipo_iva, :importe_total, :exenta, :iva5, :iva10, :idfactura, :id_login,
                :id_sesion, :obs, NULL, 0, :total
            )
        ");

        foreach ($items as $item) {
            $cantidad = (float)($item['cantidad'] ?? 0);
            if ($cantidad <= 0) continue;
            $precio = (float)($item['precio'] ?? 0);
            $costo = (float)($item['costo'] ?? 0);
            $importe = round($precio * $cantidad, 2);
            $tasa = normalizeIvaRate($item['tasa_iva'] ?? 10);
            $tipoIva = $tasa === 5 ? 2 : ($tasa === 10 ? 3 : 1);
            $itemExenta = 0.0;
            $itemIva5 = 0.0;
            $itemIva10 = 0.0;

            if ($tasa === 10) {
                $itemIva10 = round($importe / 11, 2);
            } elseif ($tasa === 5) {
                $itemIva5 = round($importe / 21, 2);
            } else {
                $itemExenta = round($importe, 2);
            }

            $stmtItem->execute([
                ':idproducto' => (int)($item['id'] ?? $item['id_producto'] ?? 0),
                ':id_sucursal' => $id_sucursal,
                ':fecha_item' => $fecha,
                ':codigo' => substr((string)($item['codigo'] ?? ''), 0, 20),
                ':descripcion' => substr((string)($item['descripcion'] ?? 'Producto'), 0, 100),
                ':salida' => $cantidad,
                ':costo_gs' => round($costo, 2),
                ':costo_unit' => round($costo, 2),
                ':precio_gs' => round($precio, 2),
                ':precio_unit' => round($precio, 2),
                ':importe_gs' => $importe,
                ':importe_total' => $importe,
                ':tipo_iva' => $tipoIva,
                ':exenta' => $itemExenta,
                ':iva5' => $itemIva5,
                ':iva10' => $itemIva10,
                ':idfactura' => $id_presupuesto,
                ':id_login' => $id_usuario,
                ':id_sesion' => (int)(preg_replace('/\D+/', '', (string)session_id()) ?: 0),
                ':obs' => '',
                ':total' => $importe,
            ]);
        }

        $pdo->commit();

        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Presupuesto guardado correctamente',
            'data' => [
                'id_factura' => $id_presupuesto,
                'nro_factura' => $nro_presupuesto,
                'total' => round($subtotal, 2),
                'iva' => round($iva5 + $iva10, 2),
                'fecha' => date('Y-m-d H:i:s'),
                'cliente' => $cliente_nombre,
                'cliente_ruc' => $cliente_ruc,
                'tipo_documento' => 'presupuesto',
                'medio_cobro' => 'presupuesto',
                'items' => $items,
            ],
        ]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('POS Presupuesto Error: ' . $e->getMessage());
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function normalizeIvaRate($raw): int
{
    $v = (int)$raw;
    if ($v === 1 || $v === 0) return 0;
    if ($v === 2 || $v === 5) return 5;
    if ($v === 3 || $v === 10) return 10;
    return 10;
}

function ensurePresupuestoTables(PDO $pdo, string $dbase): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbase}.presupuesto (
            forma_pago int DEFAULT '1',
            estado int DEFAULT '1',
            tipo_venta int DEFAULT NULL,
            tipo_documento int DEFAULT '1',
            id_empresa int DEFAULT NULL,
            id_factura int NOT NULL AUTO_INCREMENT,
            id_sucursal int DEFAULT NULL,
            id_login int DEFAULT NULL,
            fecha date DEFAULT NULL,
            vencimiento date DEFAULT NULL,
            nro_factura varchar(20) DEFAULT NULL,
            tipo_cliente int DEFAULT '1',
            id_cliente int DEFAULT NULL,
            ruc varchar(20) DEFAULT NULL,
            cantidad decimal(12,2) DEFAULT NULL,
            id_moneda int DEFAULT NULL,
            cambio decimal(12,2) DEFAULT NULL,
            exenta decimal(12,2) DEFAULT NULL,
            iva5 decimal(12,2) DEFAULT NULL,
            iva10 decimal(12,2) DEFAULT NULL,
            piva10 decimal(12,2) DEFAULT NULL,
            piva5 decimal(12,2) DEFAULT NULL,
            importe_gs decimal(12,2) DEFAULT NULL,
            timbrado varchar(20) DEFAULT NULL,
            vencimiento_timbrado date DEFAULT NULL,
            pagado decimal(12,2) DEFAULT '0.00',
            pendiente decimal(12,2) DEFAULT '0.00',
            tipo_precio int DEFAULT '1',
            descuento decimal(12,2) DEFAULT '0.00',
            total decimal(12,2) DEFAULT '0.00',
            costo decimal(12,2) DEFAULT '0.00',
            PRIMARY KEY (id_factura)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbase}.presupuesto_item (
            id int NOT NULL AUTO_INCREMENT,
            idproducto int DEFAULT NULL,
            estado int DEFAULT '1',
            id_sucursal int DEFAULT NULL,
            fecha date DEFAULT NULL,
            referencia int DEFAULT NULL,
            codigo varchar(20) DEFAULT NULL,
            descripcion varchar(100) DEFAULT NULL,
            entrada decimal(12,4) DEFAULT '0.0000',
            salida decimal(12,4) DEFAULT '0.0000',
            id_moneda int DEFAULT '1',
            cambio int DEFAULT NULL,
            costo_gs decimal(12,2) DEFAULT NULL,
            costo decimal(12,2) DEFAULT NULL,
            precio_gs decimal(12,2) DEFAULT NULL,
            precio decimal(12,2) DEFAULT NULL,
            importe_gs decimal(12,2) DEFAULT NULL,
            tipo_iva int DEFAULT '3',
            importe decimal(12,2) DEFAULT NULL,
            exenta decimal(12,2) NOT NULL DEFAULT '0.00',
            iva5 decimal(12,2) NOT NULL DEFAULT '0.00',
            iva10 decimal(12,2) NOT NULL DEFAULT '0.00',
            idfactura int DEFAULT NULL,
            id_login int DEFAULT NULL,
            id_sesion int DEFAULT NULL,
            obs tinytext,
            id_precio int DEFAULT NULL,
            descuento decimal(12,2) DEFAULT '0.00',
            total decimal(12,2) DEFAULT '0.00',
            PRIMARY KEY (id),
            KEY codigo (codigo),
            KEY idproducto (idproducto),
            KEY sucursal (id_sucursal),
            KEY idfactura (idfactura)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
    ");
}
