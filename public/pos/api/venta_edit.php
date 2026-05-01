<?php

/**
 * POS API - Editar Venta
 * Buscar y actualizar notas de venta (solo tipo_documento != 3)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/schema_compat.php';
require_once __DIR__ . '/../../../lib/producto_stock_snapshot.php';

if (!function_exists('posEditStockSnapshotReady')) {
    function posEditStockSnapshotReady(PDO $pdo, string $db): bool
    {
        return sxProductoStockSnapshotReady($pdo, $db);
    }
}

if (!function_exists('posEditStockSnapshotAdjust')) {
    function posEditStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
    {
        sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
    }
}

$id_empresa_session = (int)($_SESSION['id_empresa'] ?? 0);
$id_empresa_get = (int)($_GET['id_empresa'] ?? 0);
$id_empresa = $id_empresa_get > 0 ? $id_empresa_get : $id_empresa_session;

try {
    if ($id_empresa <= 0) {
        throw new Exception('Empresa no definida en sesión');
    }

    // Usar el resolvedor central del proyecto para evitar host/password legacy.
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = (string)$conn['dbName'];
    $dbHost = (string)($conn['dbHost'] ?? '');
    ensurePosSchemaCompatibility($pdo, $dbName);

    // ===== BÚSQUEDA DE VENTAS EDITABLES (MIS VENTAS - Solo hoy) =====
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'search') {
        $query = trim($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 50);
        $id_login = (int)($_GET['id_usuario'] ?? $_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
        $sifenFilter = strtolower(trim((string)($_GET['sifen_filter'] ?? 'todos')));
        $estadoFilter = strtolower(trim((string)($_GET['estado_filter'] ?? 'activos')));
        $fechaDesde = trim((string)($_GET['fecha_desde'] ?? ''));
        $fechaHasta = trim((string)($_GET['fecha_hasta'] ?? ''));
        $debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';

        // Detectar columnas disponibles para compatibilidad entre esquemas
        $colsVentas = [];
        try {
            $stmtCols = $pdo->query("DESCRIBE $dbName.factura_ventas");
            while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                $colsVentas[] = strtolower((string)$col['Field']);
            }
        } catch (Exception $e) {
            // Continuar con defaults
        }

        $usuarioWhere = '';
        if ($id_login > 0) {
            if (in_array('id_login', $colsVentas, true)) {
                $usuarioWhere = " AND f.id_login = :id_login";
            } elseif (in_array('id_usuario', $colsVentas, true)) {
                $usuarioWhere = " AND f.id_usuario = :id_login";
            } elseif (in_array('usuario', $colsVentas, true)) {
                $usuarioWhere = " AND f.usuario = :id_login";
            }
        }

        $estadoWhere = '';
        $estadoActivosExpr = "(
                f.estado = 0 OR
                f.estado = '0' OR
                LOWER(TRIM(CAST(f.estado AS CHAR))) = 'activo' OR
                LOWER(TRIM(CAST(f.estado AS CHAR))) = 'activa'
            )";
        if (in_array('estado', $colsVentas, true)) {
            if ($estadoFilter === 'activos') {
                $estadoWhere = " AND $estadoActivosExpr";
            } elseif ($estadoFilter === 'anulados') {
                $estadoWhere = " AND NOT $estadoActivosExpr";
            }
        }

        $sifenWhere = '';
        if ($sifenFilter === 'incompleto') {
            $sifenWhere = " AND (
                f.tipo_documento = 3 AND (
                    f.cdc IS NULL OR TRIM(CAST(f.cdc AS CHAR)) = '' OR
                    f.prot_cons_lote_sifen IS NULL OR TRIM(CAST(f.prot_cons_lote_sifen AS CHAR)) = '' OR
                    f.xml_respuesta IS NULL OR TRIM(CAST(f.xml_respuesta AS CHAR)) = ''
                )
            )";
        } elseif ($sifenFilter === 'electronicas') {
            $sifenWhere = " AND f.tipo_documento = 3";
        } elseif ($sifenFilter === 'comunes') {
            $sifenWhere = " AND (f.tipo_documento IS NULL OR f.tipo_documento <> 3)";
        }

        // Buscar ventas activas del usuario actual (últimos 30 días)
                $sql = "SELECT 
                    f.id_factura, 
                    f.nro_factura, 
                    f.fecha, 
                    f.total, 
                    f.tipo_documento,
                    f.estado,
                    f.cdc,
                    f.estado_sifen,
                    f.prot_cons_lote_sifen,
                    f.xml_respuesta,
                    (
                        f.tipo_documento = 3 AND (
                            f.cdc IS NULL OR TRIM(CAST(f.cdc AS CHAR)) = '' OR
                            f.prot_cons_lote_sifen IS NULL OR TRIM(CAST(f.prot_cons_lote_sifen AS CHAR)) = '' OR
                            f.xml_respuesta IS NULL OR TRIM(CAST(f.xml_respuesta AS CHAR)) = ''
                        )
                    ) AS sifen_incompleto,
                    f.id_cliente,
                    c.nombre AS cliente_nombre,
                    c.numero AS cliente_ruc
                FROM $dbName.factura_ventas f
                LEFT JOIN $dbName.clientes c ON c.id = f.id_cliente
            WHERE 1=1
                  $estadoWhere
                  $usuarioWhere
                  AND DATE(f.fecha) BETWEEN :fecha_desde AND :fecha_hasta
                  $sifenWhere";

        if (!empty($query)) {
            $sql .= " AND (
                f.nro_factura LIKE :q OR
                c.nombre LIKE :q2 OR
                DATE_FORMAT(f.fecha, '%d/%m/%Y') LIKE :q3 OR
                DATE_FORMAT(f.fecha, '%Y-%m-%d') LIKE :q4
            )";
        }

        $sql .= " ORDER BY f.id_factura DESC, f.fecha DESC LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        if ($usuarioWhere !== '') {
            $stmt->bindValue(':id_login', $id_login, PDO::PARAM_INT);
        }
        if ($fechaDesde === '') {
            $fechaDesde = date('Y-m-d', strtotime('-30 days'));
        }
        if ($fechaHasta === '') {
            $fechaHasta = date('Y-m-d');
        }
        $stmt->bindValue(':fecha_desde', $fechaDesde, PDO::PARAM_STR);
        $stmt->bindValue(':fecha_hasta', $fechaHasta, PDO::PARAM_STR);
        if (!empty($query)) {
            $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':q2', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':q3', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':q4', "%$query%", PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = ['success' => true, 'ventas' => $ventas];
        if ($debugMode) {
            $out['_debug'] = [
                'id_empresa_session' => $id_empresa_session,
                'id_empresa_get' => $id_empresa_get,
                'id_empresa_final' => $id_empresa,
                'dbase_usada' => $dbName,
                'usuario_filtro' => $id_login,
                'sifen_filter' => $sifenFilter,
                'estado_filter' => $estadoFilter,
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta
            ];
        }
        echo json_encode($out);
        exit;
    }

    // ===== OBTENER VENTA PARA EDITAR =====
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'load') {
        $id_factura = (int)($_GET['id'] ?? 0);

        if ($id_factura <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de factura inválido']);
            exit;
        }

        // Obtener cabecera
        $stmtHead = $pdo->prepare("
            SELECT 
                f.id_factura, f.nro_factura, f.fecha, f.total, f.tipo_documento, f.id_cliente,
                f.forma_pago, f.firma_digital, f.id_caja, f.timbrado,
                c.id AS cliente_id, c.nombre AS cliente_nombre, c.numero AS cliente_ruc,
                c.email AS cliente_email, c.telefono AS cliente_telefono,
                COALESCE(c.linea_credito, 0) AS cliente_linea_credito,
                COALESCE(c.saldo_guaranies, 0) AS cliente_saldo_guaranies
            FROM $dbName.factura_ventas f
            LEFT JOIN $dbName.clientes c ON c.id = f.id_cliente
            WHERE f.id_factura = :id
        ");
        $stmtHead->execute([':id' => $id_factura]);
        $factura = $stmtHead->fetch(PDO::FETCH_ASSOC);

        if (!$factura) {
            echo json_encode(['success' => false, 'message' => 'Venta no encontrada']);
            exit;
        }

        // Permitir edición incluso si es FE

        // Obtener items con datos completos del producto
        $stmtItems = $pdo->prepare("
            SELECT 
                ep.id AS extracto_id,
                ep.idproducto,
                ep.codigo,
                ep.descripcion,
                ep.salida AS cantidad,
                ep.precio,
                ep.costo,
                p.iva AS tasa_iva,
                p.saldo AS stock_actual,
                p.controla_stock,
                p.vende_sin_stock
            FROM $dbName.extracto_productos ep
            LEFT JOIN $dbName.tblproductos p ON p.idproducto = ep.idproducto
            WHERE ep.idfactura = :id AND ep.salida > 0
            ORDER BY ep.id
        ");
        $stmtItems->execute([':id' => $id_factura]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Formatear items para el carrito del POS
        $cartItems = [];
        foreach ($items as $item) {
            $cartItems[] = [
                'id' => $item['idproducto'],
                'extracto_id' => $item['extracto_id'],
                'codigo' => $item['codigo'],
                'descripcion' => $item['descripcion'],
                'cantidad' => (float)$item['cantidad'],
                'precio' => (float)$item['precio'],
                'costo' => (float)$item['costo'],
                'tasa_iva' => (int)($item['tasa_iva'] ?? 10),
                'stock' => (float)($item['stock_actual'] ?? 0),
                'controla_stock' => $item['controla_stock'],
                'vende_sin_stock' => $item['vende_sin_stock']
            ];
        }

        // Preparar cliente
        $cliente = null;
        if ($factura['id_cliente'] > 0) {
            $cliente = [
                'id' => $factura['cliente_id'],
                'nombre' => $factura['cliente_nombre'],
                'ruc' => $factura['cliente_ruc'],
                'email' => $factura['cliente_email'],
                'telefono' => $factura['cliente_telefono'],
                'linea_credito' => (float)($factura['cliente_linea_credito'] ?? 0),
                'saldo_guaranies' => (float)($factura['cliente_saldo_guaranies'] ?? 0)
            ];
        }

        echo json_encode([
            'success' => true,
            'venta' => [
                'id_factura' => $factura['id_factura'],
                'nro_factura' => $factura['nro_factura'],
                'fecha' => $factura['fecha'],
                'total' => (float)$factura['total'],
                'tipo_documento' => $factura['tipo_documento'],
                'forma_pago' => $factura['forma_pago'],
                'firma_digital' => $factura['firma_digital']
            ],
            'cliente' => $cliente,
            'items' => $cartItems
        ]);
        exit;
    }

    // ===== ACTUALIZAR VENTA =====
    if ($_SERVER['REQUEST_METHOD'] === 'PUT' || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update')) {
        $input = json_decode(file_get_contents('php://input'), true);

        $id_factura = (int)($input['id_factura'] ?? 0);
        $id_cliente = (int)($input['id_cliente'] ?? 0);
        $items = $input['items'] ?? [];
        $total = (float)($input['total'] ?? 0);
        $payment_method = $input['payment_method'] ?? 'efectivo';
        $payments = $input['payments'] ?? [];
        $cash_received = (float)($input['cash_received'] ?? 0);
        $cash_change = (float)($input['cash_change'] ?? 0);
        $id_usuario = (int)($_SESSION['id_login'] ?? 0);
        $id_sucursal = (int)($input['id_sucursal'] ?? $_SESSION['id_sucursal'] ?? 1);
        $is_credit_sale = strtolower(trim((string)$payment_method)) === 'credito';

        if ($id_factura <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de factura requerido']);
            exit;
        }

        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'No hay items en la venta']);
            exit;
        }
        if ($is_credit_sale && $id_cliente <= 0) {
            echo json_encode(['success' => false, 'message' => 'Debe seleccionar un cliente para editar a crédito']);
            exit;
        }

        // Verificar que la factura exista y sea editable
        $stmtCheck = $pdo->prepare("SELECT tipo_documento, cdc FROM $dbName.factura_ventas WHERE id_factura = :id");
        $stmtCheck->execute([':id' => $id_factura]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Venta no encontrada']);
            exit;
        }

        // Permitir edición incluso si es FE

        // Generar detalle de pago
        $payment_detail = strtoupper($payment_method);
        if ($payment_method === 'efectivo' && $cash_received > 0) {
            $payment_detail .= " | RECIBIDO: " . number_format($cash_received, 0, ',', '.') . " | VUELTO: " . number_format($cash_change, 0, ',', '.');
        }

        // Mapeo de medio de pago
        $medio_pago_id = 1;
        if ($payment_method === 'tarjeta') $medio_pago_id = 2;
        elseif ($payment_method === 'transferencia' || $payment_method === 'transfer') $medio_pago_id = 3;
        elseif ($payment_method === 'qr' || $payment_method === 'pix') $medio_pago_id = 4;
        elseif ($payment_method === 'credito') $medio_pago_id = 5;

        // Calcular IVA
        $totalIva10 = 0;
        $totalIva5 = 0;
        $totalExento = 0;

        foreach ($items as $item) {
            $subtotal = $item['precio'] * $item['cantidad'];
            $tasaIva = $item['tasa_iva'] ?? 10;

            if ($tasaIva == 10) {
                $totalIva10 += round($subtotal / 11);
            } elseif ($tasaIva == 5) {
                $totalIva5 += round($subtotal / 21);
            } else {
                $totalExento += $subtotal;
            }
        }

        $pdo->beginTransaction();

        try {
            // 1. Restaurar stock de items anteriores
            $stmtOldItems = $pdo->prepare("SELECT idproducto, id_sucursal, salida FROM $dbName.extracto_productos WHERE idfactura = :id AND salida > 0");
            $stmtOldItems->execute([':id' => $id_factura]);
            $oldItems = $stmtOldItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($oldItems as $oldItem) {
                if ($oldItem['idproducto'] > 0) {
                    $pdo->prepare("UPDATE $dbName.tblproductos SET saldo = saldo + :cant WHERE idproducto = :id")
                        ->execute([':cant' => $oldItem['salida'], ':id' => $oldItem['idproducto']]);
                    posEditStockSnapshotAdjust($pdo, $dbName, (int)$oldItem['idproducto'], (int)($oldItem['id_sucursal'] ?? $id_sucursal), (float)$oldItem['salida']);
                }
            }

            // 2. Eliminar items anteriores
            $pdo->prepare("DELETE FROM $dbName.extracto_productos WHERE idfactura = :id")->execute([':id' => $id_factura]);

            // 3. Actualizar cabecera
            $stmtUpdate = $pdo->prepare("
                UPDATE $dbName.factura_ventas SET
                    id_cliente = :id_cliente,
                    total = :total,
                    importe_gs = :total2,
                    iva10 = :iva10,
                    iva5 = :iva5,
                    exenta = :exenta,
                    forma_pago = :forma_pago,
                    firma_digital = :firma_digital,
                    updated_at = NOW()
                WHERE id_factura = :id_factura
            ");
            $stmtUpdate->execute([
                ':id_cliente' => $id_cliente,
                ':total' => $total,
                ':total2' => $total,
                ':iva10' => $totalIva10,
                ':iva5' => $totalIva5,
                ':exenta' => $totalExento,
                ':forma_pago' => $medio_pago_id,
                ':firma_digital' => $payment_detail,
                ':id_factura' => $id_factura
            ]);

            // 4. Insertar nuevos items
            foreach ($items as $item) {
                $sqlExtracto = "INSERT INTO $dbName.extracto_productos
                    (id_sucursal, idproducto, entrada, salida, fecha, id_login, idfactura, codigo, descripcion, costo, precio)
                    SELECT 
                        :id_sucursal, 
                        p.idproducto, 
                        0, 
                        :cantidad, 
                        NOW(), 
                        :id_login, 
                        :id_factura,
                        p.cve_producto, 
                        p.desproducto,
                        COALESCE(mp.costo, 0),
                        :precio_venta
                    FROM $dbName.tblproductos p
                    LEFT JOIN $dbName.mercaderia_precio mp ON mp.codigo = p.idproducto AND mp.tipo = 1
                    WHERE p.idproducto = :idproducto";

                $stmtExtracto = $pdo->prepare($sqlExtracto);
                $stmtExtracto->execute([
                    ':idproducto' => $item['id'],
                    ':id_sucursal' => $id_sucursal,
                    ':cantidad' => $item['cantidad'],
                    ':id_login' => $id_usuario,
                    ':id_factura' => $id_factura,
                    ':precio_venta' => $item['precio']
                ]);

                // Descontar stock nuevo
                if (isset($item['id']) && $item['id'] > 0) {
                    $pdo->prepare("UPDATE $dbName.tblproductos SET saldo = saldo - :cant WHERE idproducto = :id")
                        ->execute([':cant' => $item['cantidad'], ':id' => $item['id']]);
                    posEditStockSnapshotAdjust($pdo, $dbName, (int)$item['id'], $id_sucursal, -1 * (float)$item['cantidad']);
                }
            }

            $nroFactura = '';
            $idCajaFactura = 0;
            try {
                $stmtFacturaMeta = $pdo->prepare("SELECT nro_factura, id_caja FROM $dbName.factura_ventas WHERE id_factura = :id LIMIT 1");
                $stmtFacturaMeta->execute([':id' => $id_factura]);
                $facturaMeta = $stmtFacturaMeta->fetch(PDO::FETCH_ASSOC) ?: [];
                $nroFactura = (string)($facturaMeta['nro_factura'] ?? '');
                $idCajaFactura = (int)($facturaMeta['id_caja'] ?? 0);
            } catch (Throwable $e) {
            }

            syncEditedSalePaymentArtifacts(
                $pdo,
                $dbName,
                $id_factura,
                $id_cliente,
                (string)$nroFactura,
                $id_usuario,
                (int)$idCajaFactura,
                (string)$payment_method,
                is_array($payments) ? $payments : [],
                (float)$total
            );

            $pdo->commit();

            // Obtener datos actualizados
            $stmtFinal = $pdo->prepare("SELECT nro_factura FROM $dbName.factura_ventas WHERE id_factura = :id");
            $stmtFinal->execute([':id' => $id_factura]);
            $nroFactura = $stmtFinal->fetchColumn();

            echo json_encode([
                'success' => true,
                'message' => 'Venta actualizada correctamente',
                'data' => [
                    'id_factura' => $id_factura,
                    'nro_factura' => $nroFactura,
                    'total' => $total
                ]
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        exit;
    }

    // Default: método no soportado
    echo json_encode(['success' => false, 'message' => 'Acción no válida']);
} catch (Throwable $e) {
    error_log('POS Venta Edit Error: ' . $e->getMessage() . ' in ' . basename((string)$e->getFile()) . ':' . (int)$e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function upsertSalePaymentsInCashLedger(
    PDO $pdo,
    string $dbase,
    int $idCaja,
    int $idUsuario,
    int $idFactura,
    string $nroFactura,
    array $payments,
    string $fallbackMethod,
    float $total
): void {
    if ($idCaja <= 0 || $idUsuario <= 0 || $idFactura <= 0) return;

    $allow = ['efectivo', 'tarjeta', 'transferencia', 'transfer', 'pix', 'qr'];
    $rows = [];

    if (!empty($payments) && is_array($payments)) {
        foreach ($payments as $p) {
            if (!is_array($p)) continue;
            $rows[] = $p;
        }
    }

    if (empty($rows)) {
        $rows[] = ['method' => $fallbackMethod !== '' ? $fallbackMethod : 'efectivo', 'amount' => $total];
    }

    $normalized = [];
    foreach ($rows as $row) {
        $methodRaw = strtolower(trim((string)($row['method'] ?? $row['payment_method'] ?? $fallbackMethod)));
        if ($methodRaw === '') $methodRaw = 'efectivo';
        if (!in_array($methodRaw, $allow, true)) continue;

        $amount = (float)($row['amount'] ?? $row['monto'] ?? 0);
        if ($amount <= 0) continue;

        $medio = mapCashLedgerMethod($methodRaw);
        $comprobante = '';
        if ($methodRaw === 'tarjeta') $comprobante = (string)($row['voucher_number'] ?? '');
        if ($methodRaw === 'transferencia' || $methodRaw === 'transfer') $comprobante = (string)($row['transfer_reference'] ?? '');
        if ($methodRaw === 'pix' || $methodRaw === 'qr') $comprobante = (string)($row['qr_transaction_code'] ?? $row['pix_reference'] ?? '');

        $normalized[$medio] = [
            'medio' => $medio,
            'credito' => $amount,
            'comprobante' => substr(trim($comprobante), 0, 20),
            'concepto' => substr(strtoupper($medio) . ' | Cobro Fact. ' . trim($nroFactura !== '' ? $nroFactura : (string)$idFactura), 0, 60)
        ];
    }

    if (empty($normalized) && $total > 0) {
        $medio = mapCashLedgerMethod($fallbackMethod);
        $normalized[$medio] = [
            'medio' => $medio,
            'credito' => $total,
            'comprobante' => '',
            'concepto' => substr(strtoupper($medio) . ' | Cobro Fact. ' . trim($nroFactura !== '' ? $nroFactura : (string)$idFactura), 0, 60)
        ];
    }

    if (empty($normalized)) return;

    $stmtFind = $pdo->prepare("SELECT id, UPPER(TRIM(medio_cobro)) AS medio
        FROM $dbase.extracto_caja
        WHERE tabla_relacion = 'factura_ventas'
          AND id_relacion = :id_factura
          AND operacion = 2
          AND referencia = 41");
    $stmtFind->execute([':id_factura' => $idFactura]);
    $existingByMedio = [];
    while ($r = $stmtFind->fetch(PDO::FETCH_ASSOC)) {
        $medio = (string)($r['medio'] ?? '');
        if ($medio === '' || isset($existingByMedio[$medio])) continue;
        $existingByMedio[$medio] = (int)($r['id'] ?? 0);
    }

    $stmtUpdate = $pdo->prepare("UPDATE $dbase.extracto_caja
        SET codigo = :id_caja,
            concepto = :concepto,
            debito = 0,
            credito = :credito,
            fecha = NOW(),
            login = :login,
            id_login = :id_login,
            estado = 1,
            moneda = 1,
            cambio = 1,
            comprobante = :comprobante,
            medio_cobro = :medio_cobro
        WHERE id = :id");

    $stmtInsert = $pdo->prepare("INSERT INTO $dbase.extracto_caja
        (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante, medio_cobro, tabla_relacion, id_relacion)
        VALUES (1, :id_caja, :concepto, 0, :credito, NOW(), :login, :id_login, 1, 1, 1, 2, 41, :comprobante, :medio_cobro, 'factura_ventas', :id_factura)");

    foreach ($normalized as $medio => $payload) {
        $idExistente = (int)($existingByMedio[$medio] ?? 0);
        if ($idExistente > 0) {
            $stmtUpdate->execute([
                ':id_caja' => $idCaja,
                ':concepto' => $payload['concepto'],
                ':credito' => $payload['credito'],
                ':login' => $idUsuario,
                ':id_login' => $idUsuario,
                ':comprobante' => $payload['comprobante'],
                ':medio_cobro' => $payload['medio'],
                ':id' => $idExistente
            ]);
            continue;
        }

        $stmtInsert->execute([
            ':id_caja' => $idCaja,
            ':concepto' => $payload['concepto'],
            ':credito' => $payload['credito'],
            ':login' => $idUsuario,
            ':id_login' => $idUsuario,
            ':comprobante' => $payload['comprobante'],
            ':medio_cobro' => $payload['medio'],
            ':id_factura' => $idFactura
        ]);
    }
}

function syncEditedSalePaymentArtifacts(
    PDO $pdo,
    string $dbase,
    int $idFactura,
    int $idCliente,
    string $nroFactura,
    int $idUsuario,
    int $idCaja,
    string $paymentMethod,
    array $payments,
    float $total
): void {
    deleteEditedSalePaymentArtifacts($pdo, $dbase, $idFactura);
    replaceEditedSalePayments($pdo, $dbase, $idFactura, $idUsuario, $payments, $paymentMethod, $total);

    $methodNorm = strtolower(trim((string)$paymentMethod));
    if ($methodNorm === 'credito') {
        $creditPlan = buildEditedCreditPlan($payments, $total);
        applyEditedCreditArtifacts($pdo, $dbase, $idFactura, $idCliente, $idUsuario, $nroFactura, $total, $creditPlan);
        return;
    }

    updateEditedFacturaBalance($pdo, $dbase, $idFactura, $total, 0, mapCashLedgerMethod($methodNorm));
    if ($idCaja > 0 && $idUsuario > 0) {
        upsertSalePaymentsInCashLedger(
            $pdo,
            $dbase,
            $idCaja,
            $idUsuario,
            $idFactura,
            $nroFactura,
            $payments,
            $paymentMethod,
            $total
        );
    }
}

function deleteEditedSalePaymentArtifacts(PDO $pdo, string $dbase, int $idFactura): void
{
    try {
        $pdo->prepare("DELETE FROM $dbase.extracto_caja WHERE tabla_relacion = 'factura_ventas' AND id_relacion = :id_factura")
            ->execute([':id_factura' => $idFactura]);
    } catch (Throwable $e) {
    }

    try {
        $pdo->prepare("DELETE FROM $dbase.documentos WHERE id_factura = :id_factura")
            ->execute([':id_factura' => $idFactura]);
    } catch (Throwable $e) {
    }

    try {
        $pdo->prepare("
            DELETE FROM $dbase.extracto_cliente
            WHERE id_factura = :id_factura
               OR (tabla_relacion = 'factura_ventas' AND id_relacion = :id_relacion)
        ")->execute([
            ':id_factura' => $idFactura,
            ':id_relacion' => $idFactura,
        ]);
    } catch (Throwable $e) {
    }
}

function replaceEditedSalePayments(
    PDO $pdo,
    string $dbase,
    int $idFactura,
    int $idUsuario,
    array $payments,
    string $fallbackMethod,
    float $total
): void {
    $tablaPagos = ensureEditedPosPaymentsTable($pdo, $dbase);
    if ($tablaPagos === '') {
        return;
    }

    $pdo->prepare("DELETE FROM $dbase.$tablaPagos WHERE id_factura = :id_factura")
        ->execute([':id_factura' => $idFactura]);

    $rows = [];
    foreach ($payments as $payment) {
        if (is_array($payment)) {
            $rows[] = $payment;
        }
    }
    if (empty($rows)) {
        $rows[] = [
            'method' => $fallbackMethod !== '' ? $fallbackMethod : 'efectivo',
            'amount' => $total,
        ];
    }

    $stmt = $pdo->prepare("
        INSERT INTO $dbase.$tablaPagos (
            id_factura, id_usuario, metodo, monto, currency,
            cash_received, cash_change,
            voucher_number, transfer_reference, qr_transaction_code,
            card_terminal_reference, card_auth_code, card_nsu, card_acquirer,
            card_brand, card_masked_pan, card_rrn, card_batch,
            card_installments, card_financing_type, card_processor, card_type,
            card_capture_payload_json
        ) VALUES (
            :id_factura, :id_usuario, :metodo, :monto, :currency,
            :cash_received, :cash_change,
            :voucher_number, :transfer_reference, :qr_transaction_code,
            :card_terminal_reference, :card_auth_code, :card_nsu, :card_acquirer,
            :card_brand, :card_masked_pan, :card_rrn, :card_batch,
            :card_installments, :card_financing_type, :card_processor, :card_type,
            :card_capture_payload_json
        )
    ");

    foreach ($rows as $row) {
        $method = strtolower(trim((string)($row['method'] ?? $row['payment_method'] ?? $fallbackMethod)));
        if ($method === '') $method = 'efectivo';
        $payload = ['raw' => $row];
        $stmt->execute([
            ':id_factura' => $idFactura,
            ':id_usuario' => $idUsuario,
            ':metodo' => $method,
            ':monto' => (float)($row['amount'] ?? $row['monto'] ?? $total),
            ':currency' => strtoupper(trim((string)($row['currency'] ?? 'PYG'))),
            ':cash_received' => (float)($row['cash_received'] ?? 0),
            ':cash_change' => (float)($row['cash_change'] ?? 0),
            ':voucher_number' => trim((string)($row['voucher_number'] ?? '')),
            ':transfer_reference' => trim((string)($row['transfer_reference'] ?? '')),
            ':qr_transaction_code' => trim((string)($row['qr_transaction_code'] ?? $row['pix_reference'] ?? '')),
            ':card_terminal_reference' => trim((string)($row['card_terminal_reference'] ?? $row['reference'] ?? '')),
            ':card_auth_code' => trim((string)($row['card_auth_code'] ?? $row['auth_code'] ?? '')),
            ':card_nsu' => trim((string)($row['card_nsu'] ?? $row['nsu'] ?? '')),
            ':card_acquirer' => trim((string)($row['card_acquirer'] ?? $row['acquirer'] ?? '')),
            ':card_brand' => trim((string)($row['card_brand'] ?? $row['brand'] ?? '')),
            ':card_masked_pan' => trim((string)($row['card_masked_pan'] ?? $row['masked_pan'] ?? '')),
            ':card_rrn' => trim((string)($row['card_rrn'] ?? $row['rrn'] ?? '')),
            ':card_batch' => trim((string)($row['card_batch'] ?? $row['batch'] ?? '')),
            ':card_installments' => max(1, (int)($row['card_installments'] ?? $row['installments'] ?? 1)),
            ':card_financing_type' => trim((string)($row['card_financing_type'] ?? $row['financing_type'] ?? '')),
            ':card_processor' => trim((string)($row['card_processor'] ?? $row['processor'] ?? '')),
            ':card_type' => trim((string)($row['card_type'] ?? '')),
            ':card_capture_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function buildEditedCreditPlan(array $payments, float $total): array
{
    $creditRow = [];
    foreach ($payments as $payment) {
        if (!is_array($payment)) continue;
        $method = strtolower(trim((string)($payment['method'] ?? $payment['payment_method'] ?? '')));
        if ($method === 'credito' || $method === 'crédito') {
            $creditRow = $payment;
            break;
        }
    }

    $installments = max(1, (int)($creditRow['credit_installments'] ?? $creditRow['installments'] ?? 1));
    $dueDate = trim((string)($creditRow['credit_due_date'] ?? $creditRow['due_date'] ?? ''));
    $notes = trim((string)($creditRow['credit_notes'] ?? ''));
    $dueTs = $dueDate !== '' ? strtotime($dueDate) : false;

    return [
        'installments' => $installments,
        'due_date' => $dueTs ? date('Y-m-d', $dueTs) : date('Y-m-d', strtotime('+30 days')),
        'notes' => $notes,
        'interest_pct' => max(0, (float)($creditRow['credit_interest_pct'] ?? 0)),
        'mora_pct' => max(0, (float)($creditRow['credit_mora_pct'] ?? 0)),
        'grace_days' => max(0, (int)($creditRow['credit_grace_days'] ?? 0)),
        'total' => max(0, $total),
    ];
}

function applyEditedCreditArtifacts(
    PDO $pdo,
    string $dbase,
    int $idFactura,
    int $idCliente,
    int $idUsuario,
    string $nroFactura,
    float $total,
    array $creditPlan
): void {
    if ($idFactura <= 0 || $idCliente <= 0 || $total <= 0) {
        throw new Exception('Datos inválidos para actualizar venta a crédito');
    }

    ensureEditedFacturaVentasCreditColumns($pdo, $dbase);
    updateEditedFacturaBalance($pdo, $dbase, $idFactura, $total, $total, 'CREDITO');

    try {
        $stmtCols = $pdo->query("SHOW COLUMNS FROM $dbase.extracto_cliente");
    } catch (Throwable $e) {
        throw new Exception('Tabla extracto_cliente no disponible para ventas a crédito');
    }
    $clienteCols = [];
    while ($row = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $clienteCols[] = strtolower((string)($row['Field'] ?? ''));
    }
    $concepto = substr('Venta a credito Fact. ' . ($nroFactura !== '' ? $nroFactura : ('#' . $idFactura)) . (!empty($creditPlan['notes']) ? ' | ' . $creditPlan['notes'] : ''), 0, 255);
    $map = [
        'codigo' => $idCliente,
        'concepto' => $concepto,
        'debito' => $total,
        'credito' => 0,
        'fecha' => '__NOW__',
        'login' => $idUsuario,
        'id_login' => $idUsuario,
        'estado' => 1,
        'id_factura' => $idFactura,
        'referencia' => 41,
        'tabla_relacion' => 'factura_ventas',
        'id_relacion' => $idFactura,
        'medio_cobro' => 'CREDITO',
    ];
    $cols = [];
    $vals = [];
    $params = [];
    foreach ($map as $col => $value) {
        if (!in_array($col, $clienteCols, true)) continue;
        $cols[] = $col;
        if ($value === '__NOW__') {
            $vals[] = 'NOW()';
        } else {
            $param = ':' . $col;
            $vals[] = $param;
            $params[$param] = $value;
        }
    }
    if (empty($cols)) {
        throw new Exception('extracto_cliente no tiene columnas compatibles para registrar crédito');
    }
    $pdo->prepare("INSERT INTO $dbase.extracto_cliente (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")")
        ->execute($params);

    try {
        $stmtDocCols = $pdo->query("SHOW COLUMNS FROM $dbase.documentos");
    } catch (Throwable $e) {
        throw new Exception('Tabla documentos no disponible para ventas a crédito');
    }
    $docCols = [];
    while ($row = $stmtDocCols->fetch(PDO::FETCH_ASSOC)) {
        $docCols[] = strtolower((string)($row['Field'] ?? ''));
    }

    $installments = max(1, (int)($creditPlan['installments'] ?? 1));
    $dueDate = (string)($creditPlan['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
    $interestPct = max(0, (float)($creditPlan['interest_pct'] ?? 0));
    $moraPct = max(0, (float)($creditPlan['mora_pct'] ?? 0));
    $graceDays = max(0, (int)($creditPlan['grace_days'] ?? 0));
    $baseAmount = max(0, (float)($creditPlan['total'] ?? $total));
    $totalInterest = $baseAmount * ($interestPct / 100);
    $totalFinanced = $baseAmount + $totalInterest;
    $basePerInstallment = floor($totalFinanced / $installments);
    $capitalPerInstallment = floor($baseAmount / $installments);
    $interestPerInstallment = floor($totalInterest / $installments);
    $remainder = $totalFinanced - ($basePerInstallment * $installments);
    $capitalRemainder = $baseAmount - ($capitalPerInstallment * $installments);
    $interestRemainder = $totalInterest - ($interestPerInstallment * $installments);

    for ($i = 1; $i <= $installments; $i++) {
        $amount = $basePerInstallment + ($i === $installments ? $remainder : 0);
        $capital = $capitalPerInstallment + ($i === $installments ? $capitalRemainder : 0);
        $interest = $interestPerInstallment + ($i === $installments ? $interestRemainder : 0);
        $fechaVenc = date('Y-m-d', strtotime($dueDate . ' + ' . (($i - 1) * 30) . ' days'));
        $qty = $i . '/' . $installments;
        $docMap = [
            'id_sucursal' => 1,
            'id_cliente' => $idCliente,
            'id_factura' => $idFactura,
            'fecha_creacion' => '__NOW__',
            'fecha_vencimiento' => $fechaVenc,
            'capital' => $capital,
            'cantidad_cuota' => $qty,
            'periodo_cuota' => 30,
            'int_capital' => $interest,
            'int_moratorio' => 0,
            'total' => $amount,
            'concepto' => substr('Cuota ' . $qty . ' - Fac. ' . ($nroFactura !== '' ? $nroFactura : ('#' . $idFactura)), 0, 255),
            'id_login' => $idUsuario,
            'pagado' => 0,
            'pendiente' => $amount,
            'estado' => 1,
            'inforcomf' => 0,
            'dias_gracia' => $graceDays,
            'porc_mora' => $moraPct,
        ];
        $cols = [];
        $vals = [];
        $params = [];
        foreach ($docMap as $col => $value) {
            if (!in_array($col, $docCols, true)) continue;
            $cols[] = $col;
            if ($value === '__NOW__') {
                $vals[] = 'NOW()';
            } else {
                $param = ':' . $col . '_' . $i;
                $vals[] = $param;
                $params[$param] = $value;
            }
        }
        if (empty($cols)) {
            throw new Exception('documentos no tiene columnas compatibles para registrar cuotas');
        }
        $pdo->prepare("INSERT INTO $dbase.documentos (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")")
            ->execute($params);
    }
}

function updateEditedFacturaBalance(PDO $pdo, string $dbase, int $idFactura, float $saldo, float $pendiente, string $medioCobro): void
{
    ensureEditedFacturaVentasCreditColumns($pdo, $dbase);

    $set = ["saldo = :saldo", "pendiente = :pendiente"];
    $params = [
        ':saldo' => $saldo,
        ':pendiente' => $pendiente,
        ':id_factura' => $idFactura,
    ];

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $dbase.factura_ventas LIKE 'medio_cobro'");
        if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
            $set[] = "medio_cobro = :medio_cobro";
            $params[':medio_cobro'] = $medioCobro;
        }
    } catch (Throwable $e) {
    }

    $sql = "UPDATE $dbase.factura_ventas SET " . implode(', ', $set) . " WHERE id_factura = :id_factura";
    $pdo->prepare($sql)->execute($params);
}

function ensureEditedFacturaVentasCreditColumns(PDO $pdo, string $dbase): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $dbase.factura_ventas");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = strtolower((string)($row['Field'] ?? ''));
        }
        if (!in_array('saldo', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.factura_ventas ADD COLUMN saldo DECIMAL(15,2) NULL AFTER total");
        }
        if (!in_array('pendiente', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.factura_ventas ADD COLUMN pendiente DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER saldo");
        }
        if (!in_array('medio_cobro', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.factura_ventas ADD COLUMN medio_cobro VARCHAR(20) NULL");
        }
        $pdo->exec("UPDATE $dbase.factura_ventas SET saldo = total WHERE saldo IS NULL");
    } catch (Throwable $e) {
        throw new Exception('No se pudo preparar factura_ventas para crédito: ' . $e->getMessage());
    }
}

function ensureEditedPosPaymentsTable(PDO $pdo, string $dbase): string
{
    try {
        $pdo->query("SELECT 1 FROM $dbase.factura_ventas_pagos LIMIT 1");
        return 'factura_ventas_pagos';
    } catch (Throwable $e) {
        return '';
    }
}

function mapCashLedgerMethod(string $method): string
{
    $m = strtolower(trim($method));
    if ($m === 'tarjeta') return 'TARJETA';
    if ($m === 'transferencia' || $m === 'transfer') return 'TRANSFERENCIA';
    if ($m === 'pix' || $m === 'qr') return 'QR';
    return 'EFECTIVO';
}
