<?php

/**
 * Compras API - Backend for Purchase Module
 * Handles CRUD operations for factura_compras and extracto_productos
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/compras_credito_helpers.php';
require_once __DIR__ . '/../lib/producto_stock_snapshot.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonInput = json_decode($rawBody ?: 'null', true);
if (!is_array($jsonInput)) {
    $jsonInput = [];
}

$action = $_GET['action'] ?? $_POST['action'] ?? ($jsonInput['action'] ?? '');
if ($action === 'create') $action = 'save';
if ($action === 'anular') $action = 'void';

$id_empresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? ($jsonInput['id_empresa'] ?? null) ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 1);

// DB Connection
$masterDb = defined('MASTER_DB') ? MASTER_DB : ($_SESSION['dbu'] ?? 'serproc1');
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

function sxColumnExists(PDO $pdo, string $db, string $table, string $column): bool
{
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
    return (int)$stmt->fetchColumn() > 0;
}

function sxTableExists(PDO $pdo, string $db, string $table): bool
{
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
    return (int)$stmt->fetchColumn() > 0;
}

function comprasStockSnapshotReady(PDO $pdo, string $db): bool
{
    return sxProductoStockSnapshotReady($pdo, $db);
}

function comprasStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
{
    sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
}

function comprasStockSnapshotRevertFactura(PDO $pdo, string $db, int $idFactura): void
{
    if ($idFactura <= 0 || !comprasStockSnapshotReady($pdo, $db)) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT idproducto,
               COALESCE(NULLIF(id_sucursal, 0), 1) AS id_sucursal,
               (COALESCE(entrada, 0) - COALESCE(salida, 0)) AS delta
        FROM {$db}.extracto_productos
        WHERE referencia = 2
          AND idfactura = :id_factura
          AND COALESCE(estado, 1) = 1
    ");
    $stmt->execute([':id_factura' => $idFactura]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        comprasStockSnapshotAdjust(
            $pdo,
            $db,
            (int)($row['idproducto'] ?? 0),
            (int)($row['id_sucursal'] ?? 1),
            -1 * (float)($row['delta'] ?? 0)
        );
    }
}

function ensureComprasExtraSchema(PDO $pdo, string $db): void
{
    if (!sxColumnExists($pdo, $db, 'factura_compras', 'medio_pago')) {
        $pdo->exec("ALTER TABLE {$db}.factura_compras ADD COLUMN medio_pago VARCHAR(30) NULL AFTER forma_pago");
    }
    if (!sxColumnExists($pdo, $db, 'factura_compras', 'tipo_comprobante')) {
        $pdo->exec("ALTER TABLE {$db}.factura_compras ADD COLUMN tipo_comprobante VARCHAR(20) NULL AFTER tipo_documento");
    }
}

function ensureGastoCompraSchema(PDO $pdo, string $db): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$db}.gastos_empresa (
        id_gasto INT AUTO_INCREMENT PRIMARY KEY,
        id_empresa INT NOT NULL,
        id_sucursal INT NULL,
        id_caja INT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        concepto VARCHAR(140) NOT NULL,
        observacion VARCHAR(255) NULL,
        monto DECIMAL(14,2) NOT NULL,
        pagado_por_tipo VARCHAR(30) NOT NULL DEFAULT 'CAJA',
        pagado_por_detalle VARCHAR(120) NULL,
        origen VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
        estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
        creado_por INT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_gasto_fecha (fecha),
        INDEX idx_gasto_empresa (id_empresa),
        INDEX idx_gasto_sucursal (id_sucursal),
        INDEX idx_gasto_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!sxColumnExists($pdo, $db, 'gastos_empresa', 'id_factura_compra')) {
        $pdo->exec("ALTER TABLE {$db}.gastos_empresa ADD COLUMN id_factura_compra INT NULL AFTER id_caja");
    }
    if (!sxColumnExists($pdo, $db, 'gastos_empresa', 'id_proveedor')) {
        $pdo->exec("ALTER TABLE {$db}.gastos_empresa ADD COLUMN id_proveedor INT NULL AFTER id_factura_compra");
    }
}

function ensureFacturaComprasCreditColumns(PDO $pdo, string $db): void
{
    if (!sxColumnExists($pdo, $db, 'factura_compras', 'credit_installments')) {
        $pdo->exec("ALTER TABLE {$db}.factura_compras ADD COLUMN credit_installments INT NULL AFTER tipo_comprobante");
    }
    if (!sxColumnExists($pdo, $db, 'factura_compras', 'credit_due_date')) {
        $pdo->exec("ALTER TABLE {$db}.factura_compras ADD COLUMN credit_due_date DATE NULL AFTER credit_installments");
    }
    if (!sxColumnExists($pdo, $db, 'factura_compras', 'credit_notes')) {
        $pdo->exec("ALTER TABLE {$db}.factura_compras ADD COLUMN credit_notes VARCHAR(255) NULL AFTER credit_due_date");
    }
}

function ensureDocumentosCompraTable(PDO $pdo, string $db): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$db}.documentos_compra (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_factura INT NOT NULL,
            id_proveedor INT NOT NULL,
            id_sucursal INT NULL,
            id_login INT NULL,
            nro_factura VARCHAR(60) NULL,
            cuota_numero INT NOT NULL DEFAULT 1,
            cantidad_cuota VARCHAR(20) NULL,
            capital DECIMAL(15,2) NOT NULL DEFAULT 0,
            interes DECIMAL(15,2) NOT NULL DEFAULT 0,
            total DECIMAL(15,2) NOT NULL DEFAULT 0,
            pagado DECIMAL(15,2) NOT NULL DEFAULT 0,
            pendiente DECIMAL(15,2) NOT NULL DEFAULT 0,
            fecha_emision DATE NULL,
            fecha_vencimiento DATE NULL,
            estado TINYINT(1) NOT NULL DEFAULT 1,
            observacion VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_factura (id_factura),
            INDEX idx_proveedor (id_proveedor),
            INDEX idx_vencimiento (fecha_vencimiento),
            INDEX idx_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function normalizeCreditPurchaseDueDate(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    $ts = strtotime($raw);
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}

function cleanupCreditPurchaseArtifacts(PDO $pdo, string $db, int $idFactura): void
{
    if ($idFactura <= 0) return;
    if (sxTableExists($pdo, $db, 'documentos_compra')) {
        $pdo->prepare("UPDATE {$db}.documentos_compra SET estado = 0, pendiente = GREATEST(pendiente, 0) WHERE id_factura = :id")
            ->execute([':id' => $idFactura]);
    }
    if (!sxTableExists($pdo, $db, 'extracto_cliente')) {
        return;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM {$db}.extracto_cliente");
    $available = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $available[] = strtolower((string)($row['Field'] ?? ''));
    }
    if (in_array('tabla_relacion', $available, true) && in_array('id_relacion', $available, true) && in_array('estado', $available, true)) {
        $pdo->prepare("UPDATE {$db}.extracto_cliente SET estado = 0 WHERE tabla_relacion = 'factura_compras' AND id_relacion = :id")
            ->execute([':id' => $idFactura]);
        return;
    }
    if (in_array('id_factura', $available, true) && in_array('estado', $available, true)) {
        $pdo->prepare("UPDATE {$db}.extracto_cliente SET estado = 0 WHERE id_factura = :id")
            ->execute([':id' => $idFactura]);
    }
}

function persistCreditPurchaseArtifacts(
    PDO $pdo,
    string $db,
    int $idFactura,
    int $idProveedor,
    int $idUsuario,
    int $idSucursal,
    string $nroFactura,
    string $fechaEmision,
    float $total,
    array $plan
): void {
    if ($idFactura <= 0 || $idProveedor <= 0 || $total <= 0) {
        throw new Exception('Datos inválidos para registrar compra a crédito');
    }

    cleanupCreditPurchaseArtifacts($pdo, $db, $idFactura);

    $installments = max(1, (int)($plan['installments'] ?? 1));
    $dueDate = normalizeCreditPurchaseDueDate((string)($plan['due_date'] ?? ''));
    if ($dueDate === '') {
        $dueDate = date('Y-m-d', strtotime('+30 days'));
    }
    $notes = trim((string)($plan['notes'] ?? ''));

    $pdo->prepare("
        UPDATE {$db}.factura_compras
        SET pagado = 0,
            pendiente = :pendiente,
            medio_pago = 'CREDITO',
            credit_installments = :installments,
            credit_due_date = :due_date,
            credit_notes = :notes
        WHERE id_factura = :id
    ")->execute([
        ':pendiente' => $total,
        ':installments' => $installments,
        ':due_date' => $dueDate,
        ':notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
        ':id' => $idFactura,
    ]);

    if (sxTableExists($pdo, $db, 'extracto_cliente')) {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$db}.extracto_cliente");
        $available = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $available[] = strtolower((string)($row['Field'] ?? ''));
        }
        $concepto = 'Compra a credito Fact. ' . trim($nroFactura !== '' ? $nroFactura : ('#' . $idFactura));
        if ($notes !== '') {
            $concepto .= ' | ' . $notes;
        }
        $map = [
            'codigo' => $idProveedor,
            'concepto' => substr($concepto, 0, 255),
            'debito' => $total,
            'credito' => 0,
            'fecha' => '__NOW__',
            'login' => $idUsuario,
            'id_login' => $idUsuario,
            'estado' => 1,
            'id_factura' => $idFactura,
            'referencia' => 39,
            'tabla_relacion' => 'factura_compras',
            'id_relacion' => $idFactura,
            'medio_cobro' => 'CREDITO',
        ];
        $cols = [];
        $vals = [];
        $params = [];
        foreach ($map as $col => $value) {
            if (!in_array($col, $available, true)) continue;
            $cols[] = $col;
            if ($value === '__NOW__') {
                $vals[] = 'NOW()';
                continue;
            }
            $param = ':' . $col;
            $vals[] = $param;
            $params[$param] = $value;
        }
        if ($cols) {
            $sql = "INSERT INTO {$db}.extracto_cliente (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
            $pdo->prepare($sql)->execute($params);
        }
    }

    $baseInstallment = floor($total / $installments);
    $remainder = $total - ($baseInstallment * $installments);
    for ($i = 1; $i <= $installments; $i++) {
        $amount = $baseInstallment;
        if ($i === $installments) {
            $amount += $remainder;
        }
        $fechaVencimiento = date('Y-m-d', strtotime($dueDate . ' + ' . (($i - 1) * 30) . ' days'));
        $cantidadCuota = $i . '/' . $installments;
        $conceptoCuota = 'Cuota ' . $cantidadCuota . ' Compra ' . trim($nroFactura !== '' ? $nroFactura : ('#' . $idFactura));
        $pdo->prepare("
            INSERT INTO {$db}.documentos_compra
            (id_factura, id_proveedor, id_sucursal, id_login, nro_factura, cuota_numero, cantidad_cuota, capital, interes, total, pagado, pendiente, fecha_emision, fecha_vencimiento, estado, observacion)
            VALUES
            (:id_factura, :id_proveedor, :id_sucursal, :id_login, :nro_factura, :cuota_numero, :cantidad_cuota, :capital, 0, :total, 0, :pendiente, :fecha_emision, :fecha_vencimiento, 1, :observacion)
        ")->execute([
            ':id_factura' => $idFactura,
            ':id_proveedor' => $idProveedor,
            ':id_sucursal' => $idSucursal,
            ':id_login' => $idUsuario,
            ':nro_factura' => $nroFactura,
            ':cuota_numero' => $i,
            ':cantidad_cuota' => $cantidadCuota,
            ':capital' => $amount,
            ':total' => $amount,
            ':pendiente' => $amount,
            ':fecha_emision' => date('Y-m-d', strtotime($fechaEmision ?: 'now')),
            ':fecha_vencimiento' => $fechaVencimiento,
            ':observacion' => substr($notes !== '' ? $notes : $conceptoCuota, 0, 255),
        ]);
    }
}

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Get company database from master schema
    $stmtDB = $pdo->prepare("SELECT dbase FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    if (!$dbName) {
        throw new Exception("Base de datos no encontrada para empresa ID: $id_empresa");
    }

    ensureComprasExtraSchema($pdo, $dbName);

    switch ($action) {
        // ===== LIST COMPRAS =====
        case 'list':
            $fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-01');
            $fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
            $estado = $_GET['estado'] ?? '';
            $buscar = trim($_GET['buscar'] ?? '');

            $whereClause = "fc.id_empresa = $id_empresa AND DATE(fc.fecha) BETWEEN :desde AND :hasta";
            if ($estado !== '') {
                $whereClause .= " AND fc.estado = " . (int)$estado;
            }
            if (!empty($buscar)) {
                $whereClause .= " AND (p.nombre LIKE :buscar OR fc.nro_factura LIKE :buscar2)";
            }

            $sql = "SELECT fc.id_factura, fc.fecha, fc.nro_factura, fc.timbrado, fc.vencimiento,
                           fc.total, fc.pagado, fc.pendiente, fc.estado, fc.forma_pago,
                           fc.exenta, fc.iva5, fc.iva10, fc.piva5, fc.piva10, fc.nota,
                           COALESCE(fc.medio_pago, '') AS medio_pago,
                           COALESCE(fc.tipo_comprobante, '') AS tipo_comprobante,
                           COALESCE(fc.tipo_compra, 1) AS tipo_compra,
                           p.id as id_proveedor, p.nombre as proveedor, p.numero as ruc
                    FROM $dbName.factura_compras fc
                    LEFT JOIN $dbName.clientes p ON fc.id_cliente = p.id
                    WHERE $whereClause
                    ORDER BY fc.fecha DESC, fc.id_factura DESC
                    LIMIT 500";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':desde', $fechaDesde);
            $stmt->bindValue(':hasta', $fechaHasta);
            if (!empty($buscar)) {
                $stmt->bindValue(':buscar', "%$buscar%");
                $stmt->bindValue(':buscar2', "%$buscar%");
            }
            $stmt->execute();
            $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'compras' => $compras]);
            break;

        // ===== GET SINGLE COMPRA =====
        case 'get':
            $id_factura = (int)($_GET['id_factura'] ?? 0);

            // Header
            $stmt = $pdo->prepare("
                SELECT fc.*, p.nombre as proveedor, p.numero as ruc
                FROM $dbName.factura_compras fc
                LEFT JOIN $dbName.clientes p ON fc.id_cliente = p.id
                WHERE fc.id_factura = :id
            ");
            $stmt->execute([':id' => $id_factura]);
            $compra = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$compra) {
                throw new Exception("Compra no encontrada");
            }

            if ((int)($compra['tipo_compra'] ?? 1) === 2 && sxTableExists($pdo, $dbName, 'gastos_empresa') && sxColumnExists($pdo, $dbName, 'gastos_empresa', 'id_factura_compra')) {
                $stmtItems = $pdo->prepare("
                    SELECT
                        g.id_gasto AS id,
                        0 AS idproducto,
                        '' AS codigo,
                        COALESCE(g.observacion, '') AS descripcion,
                        COALESCE(g.concepto, '') AS rubro,
                        1 AS cantidad,
                        g.monto AS costo_gs,
                        1 AS tipo_iva,
                        g.monto AS importe_gs,
                        g.concepto AS producto_nombre
                    FROM $dbName.gastos_empresa g
                    WHERE g.id_factura_compra = :id_factura
                      AND UPPER(TRIM(COALESCE(g.estado, 'ACTIVO'))) = 'ACTIVO'
                    ORDER BY g.id_gasto
                ");
                $stmtItems->execute([':id_factura' => $id_factura]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmtItems = $pdo->prepare("
                    SELECT ep.id, ep.idproducto, ep.codigo, ep.descripcion, ep.entrada as cantidad,
                           ep.costo_gs, ep.tipo_iva, ep.importe_gs,
                           pr.descripcion as producto_nombre
                    FROM $dbName.extracto_productos ep
                    LEFT JOIN $dbName.tblproductos pr ON ep.idproducto = pr.idproducto
                    WHERE ep.referencia = 2 AND ep.idfactura = :id_factura AND ep.estado = 1
                    ORDER BY ep.id
                ");
                $stmtItems->execute([':id_factura' => $id_factura]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'compra' => $compra, 'items' => $items]);
            break;

        case 'search_gasto_rubros':
            ensureGastoCompraSchema($pdo, $dbName);
            $query = trim((string)($_GET['q'] ?? ''));
            $limit = (int)($_GET['limit'] ?? 20);
            if ($limit <= 0) $limit = 20;
            if ($limit > 100) $limit = 100;

            if ($query === '') {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT concepto AS rubro
                    FROM $dbName.gastos_empresa
                    WHERE TRIM(COALESCE(concepto, '')) <> ''
                    ORDER BY concepto ASC
                    LIMIT :limit
                ");
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT concepto AS rubro
                    FROM $dbName.gastos_empresa
                    WHERE concepto LIKE :q
                    ORDER BY concepto ASC
                    LIMIT :limit
                ");
                $stmt->bindValue(':q', '%' . $query . '%', PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            }
            $stmt->execute();
            echo json_encode(['success' => true, 'rubros' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
            break;

        // ===== SEARCH PROVEEDORES (using clientes table) =====
        case 'search_proveedor':
        case 'search_proveedores':
            try {
                $query = trim((string)($_GET['q'] ?? ''));
                $query = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{2070}-\x{209F}]/u', '', $query);
                $limit = (int)($_GET['limit'] ?? 20);
                if ($limit <= 0) $limit = 20;
                if ($limit > 100) $limit = 100;

                // Compatibilidad multi-tenant: detectar columnas reales de clientes.
                $colStmt = $pdo->query("SHOW COLUMNS FROM $dbName.clientes");
                $cols = [];
                while ($r = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = strtolower((string)($r['Field'] ?? ''));
                }

                $pickCol = static function(array $candidates, array $existing): ?string {
                    foreach ($candidates as $c) {
                        if (in_array(strtolower($c), $existing, true)) return $c;
                    }
                    return null;
                };

                $idCol = $pickCol(['id', 'idcliente', 'id_cliente'], $cols);
                $nameCol = $pickCol(['nombre', 'razon_social', 'cliente', 'descripcion'], $cols);
                $docCol = $pickCol(['numero', 'ruc', 'documento', 'nro_documento', 'cedula'], $cols);

                if (!$idCol || !$nameCol) {
                    throw new Exception("Estructura de proveedores no compatible en clientes");
                }

                $whereParts = ["CONVERT(`$nameCol` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :q"];
                if ($docCol) $whereParts[] = "CONVERT(`$docCol` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :q";
                $docSelect = $docCol ? "`$docCol`" : "''";

                $sql = "SELECT `$idCol` AS id, `$nameCol` AS nombre, {$docSelect} AS numero, {$docSelect} AS ruc
                        FROM $dbName.clientes
                        WHERE " . implode(' OR ', $whereParts) . "
                        ORDER BY `$nameCol`
                        LIMIT :limit";

                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'proveedores' => $proveedores]);
            } catch (Throwable $e) {
                error_log('[compras_api][search_proveedor] ' . $e->getMessage());
                // Fail-safe: no romper la UI de compras por diferencias de esquema/collation.
                echo json_encode(['success' => true, 'proveedores' => []]);
            }
            break;

        // ===== SEARCH PRODUCTOS =====
        case 'search_productos':
            $query = trim($_GET['q'] ?? '');
            $limit = (int)($_GET['limit'] ?? 20);

            $sql = "SELECT p.idproducto as id, p.codigo, p.descripcion, p.costo,
                           p.tipo_iva, p.precio1 as precio
                    FROM $dbName.tblproductos p
                    WHERE (p.descripcion LIKE :q OR p.codigo LIKE :q) AND p.estado = 1
                    ORDER BY p.descripcion
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'productos' => $productos]);
            break;

        // ===== SAVE COMPRA =====
        case 'save':
            $input = $jsonInput;

            $id_factura = (int)($input['id_factura'] ?? 0);
            $id_proveedor = (int)($input['id_proveedor'] ?? $input['id_cliente'] ?? 0);
            $nro_factura = trim($input['nro_factura'] ?? '');
            $fecha = $input['fecha'] ?? date('Y-m-d H:i:s');
            $vencimiento = $input['vencimiento'] ?? null;
            $timbrado = trim($input['timbrado'] ?? '');
            $vencimiento_timbrado = $input['vencimiento_timbrado'] ?? null;
            $tipoCompra = strtolower(trim((string)($input['tipo_compra'] ?? 'producto')));
            $isGastoCompra = in_array($tipoCompra, ['gasto', 'gastos', 'expense'], true);
            $medioPago = strtoupper(trim((string)($input['medio_pago'] ?? 'EFECTIVO')));
            $tipoComprobante = strtoupper(trim((string)($input['tipo_comprobante'] ?? 'FACTURA')));
            $forma_pago = $medioPago === 'CREDITO' ? 2 : 1;
            $creditInstallments = max(1, (int)($input['credit_installments'] ?? 1));
            $creditDueDate = normalizeCreditPurchaseDueDate((string)($input['credit_due_date'] ?? ''));
            $creditNotes = trim((string)($input['credit_notes'] ?? ''));
            $nota = trim($input['nota'] ?? '');
            $items = $input['items'] ?? [];
            $moneda = strtoupper(trim((string)($input['moneda'] ?? 'PYG')));
            $cambio = (float)($input['cambio'] ?? 1);
            if ($cambio <= 0) $cambio = 1;
            $id_moneda_input = (int)($input['id_moneda'] ?? 0);
            $id_moneda = $id_moneda_input > 0 ? $id_moneda_input : (($moneda === 'BRL') ? 2 : 1);
            $id_caja = (int)($_SESSION['id_caja_def'] ?? 0);

            $proveedor_nombre = trim($input['proveedor_nombre'] ?? '');
            $ruc = trim($input['ruc'] ?? '');
            $rucNorm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ruc));

            if ($id_proveedor <= 0) {
                if (empty($proveedor_nombre)) {
                    throw new Exception("Debe seleccionar un proveedor o ingresar su nombre");
                }

                // Check if supplier exists by RUC/Documento (exacto o normalizado) o por nombre.
                $searchProv = $pdo->prepare("
                    SELECT id, numero
                    FROM $dbName.clientes
                    WHERE (
                        (:ruc <> '' AND numero = :ruc)
                        OR (
                            :ruc_norm <> ''
                            AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(numero, ''), '-', ''), '.', ''), '/', ''), ' ', '')) = :ruc_norm
                        )
                        OR nombre = :nombre
                    )
                    LIMIT 1
                ");
                $searchProv->execute([
                    ':ruc' => $ruc,
                    ':ruc_norm' => $rucNorm,
                    ':nombre' => $proveedor_nombre
                ]);
                $existingProv = $searchProv->fetch(PDO::FETCH_ASSOC);

                if ($existingProv) {
                    $id_proveedor = (int)$existingProv['id'];
                    $rucExistente = trim((string)($existingProv['numero'] ?? ''));
                    if ($rucExistente === '' && $ruc !== '') {
                        // Si el proveedor existía sin documento, completar con RUC extranjero recibido.
                        $stmtUpdProv = $pdo->prepare("UPDATE $dbName.clientes SET numero = :ruc WHERE id = :id");
                        $stmtUpdProv->execute([':ruc' => $ruc, ':id' => $id_proveedor]);
                    }
                    $ruc = $ruc !== '' ? $ruc : $rucExistente;
                } else {
                    // Create new supplier
                    $rucAlta = $ruc !== '' ? $ruc : ('EXT-' . date('YmdHis'));
                    $stmtNewProv = $pdo->prepare("INSERT INTO $dbName.clientes (nombre, numero, estado, sucursal, cuenta, fecha, documento, llave) VALUES (:nombre, :ruc, 1, :suc, 1, :fecha, '', :llave)");
                    $stmtNewProv->execute([
                        ':nombre' => $proveedor_nombre,
                        ':ruc' => $rucAlta,
                        ':suc' => $id_sucursal,
                        ':fecha' => date('Y-m-d'),
                        ':llave' => "1.1.$rucAlta"
                    ]);
                    $id_proveedor = (int)$pdo->lastInsertId();
                    $ruc = $rucAlta;
                }
            } else {
                // Get existing RUC if already have ID
                $stmtProv = $pdo->prepare("SELECT numero FROM $dbName.clientes WHERE id = :id");
                $stmtProv->execute([':id' => $id_proveedor]);
                $ruc = $stmtProv->fetchColumn() ?: $ruc;
            }

            if (empty($nro_factura)) {
                throw new Exception("Debe ingresar el número de factura");
            }
            if (empty($items)) {
                throw new Exception("Debe agregar al menos un ítem");
            }

            if ($isGastoCompra) {
                ensureGastoCompraSchema($pdo, $dbName);
            }
            ensureFacturaComprasCreditColumns($pdo, $dbName);
            if ($medioPago === 'CREDITO') {
                ensureDocumentosCompraTable($pdo, $dbName);
            }

            // Calculate totals
            $exenta = 0;
            $iva5 = 0;
            $iva10 = 0;
            $piva5 = 0;
            $piva10 = 0;
            $total = 0;
            $cantidad = 0;

            foreach ($items as $item) {
                $subtotal = (float)($item['cantidad'] ?? 0) * (float)($item['costo'] ?? 0);
                $tipoIva = (int)($item['tipo_iva'] ?? ($isGastoCompra ? 1 : 3));
                $cantidad += (float)($item['cantidad'] ?? 0);
                $total += $subtotal;

                if ($tipoIva == 1) { // Exenta
                    $exenta += $subtotal;
                } elseif ($tipoIva == 2) { // IVA 5%
                    $iva5 += $subtotal;
                    $piva5 += round($subtotal / 21, 2);
                } else { // IVA 10%
                    $iva10 += $subtotal;
                    $piva10 += round($subtotal / 11, 2);
                }
            }

            $pdo->beginTransaction();

            try {
                $productosNuevos = [];
                $fcCols = [];
                $epCols = [];
                $existingCompraMeta = null;

                $stmtFcCols = $pdo->query("SHOW COLUMNS FROM $dbName.factura_compras");
                while ($c = $stmtFcCols->fetch(PDO::FETCH_ASSOC)) {
                    $fcCols[] = strtolower((string)($c['Field'] ?? ''));
                }
                $stmtEpCols = $pdo->query("SHOW COLUMNS FROM $dbName.extracto_productos");
                while ($c = $stmtEpCols->fetch(PDO::FETCH_ASSOC)) {
                    $epCols[] = strtolower((string)($c['Field'] ?? ''));
                }

                $hasFcIdMoneda = in_array('id_moneda', $fcCols, true);
                $hasFcCambio = in_array('cambio', $fcCols, true);
                $hasFcMedioPago = in_array('medio_pago', $fcCols, true);
                $hasFcTipoComprobante = in_array('tipo_comprobante', $fcCols, true);
                $hasEpIdMoneda = in_array('id_moneda', $epCols, true);
                $hasEpCambio = in_array('cambio', $epCols, true);
                $pagadoInicial = $medioPago === 'CREDITO' ? 0 : $total;
                $pendienteInicial = max(0, $total - $pagadoInicial);

                if ($id_factura > 0) {
                    $stmtCompraMeta = $pdo->prepare("
                        SELECT id_factura, COALESCE(tipo_compra, 1) AS tipo_compra
                        FROM $dbName.factura_compras
                        WHERE id_factura = :id_factura
                        LIMIT 1
                    ");
                    $stmtCompraMeta->execute([':id_factura' => $id_factura]);
                    $existingCompraMeta = $stmtCompraMeta->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$existingCompraMeta) {
                        throw new Exception("Compra no encontrada para editar");
                    }

                    // UPDATE existing
                    $updateSet = [
                        "id_cliente = :id_proveedor",
                        "ruc = :ruc",
                        "nro_factura = :nro_factura",
                        "fecha = :fecha",
                        "vencimiento = :vencimiento",
                        "timbrado = :timbrado",
                        "vencimiento_timbrado = :vencimiento_timbrado",
                        "forma_pago = :forma_pago",
                        "cantidad = :cantidad",
                        "exenta = :exenta",
                        "iva5 = :iva5",
                        "iva10 = :iva10",
                        "piva5 = :piva5",
                        "piva10 = :piva10",
                        "total = :total",
                        "pagado = :pagado",
                        "pendiente = :pendiente",
                        "nota = :nota",
                        "id_login = :id_login",
                        "tipo_compra = :tipo_compra"
                    ];
                    if ($hasFcIdMoneda) $updateSet[] = "id_moneda = :id_moneda";
                    if ($hasFcCambio) $updateSet[] = "cambio = :cambio";
                    if ($hasFcMedioPago) $updateSet[] = "medio_pago = :medio_pago";
                    if ($hasFcTipoComprobante) $updateSet[] = "tipo_comprobante = :tipo_comprobante";

                    $stmt = $pdo->prepare("UPDATE $dbName.factura_compras SET " . implode(', ', $updateSet) . " WHERE id_factura = :id_factura");
                    $paramsUpd = [
                        ':id_proveedor' => $id_proveedor,
                        ':ruc' => $ruc,
                        ':nro_factura' => $nro_factura,
                        ':fecha' => $fecha,
                        ':vencimiento' => $vencimiento,
                        ':timbrado' => $timbrado,
                        ':vencimiento_timbrado' => $vencimiento_timbrado,
                        ':forma_pago' => $forma_pago,
                        ':cantidad' => $cantidad,
                        ':exenta' => $exenta,
                        ':iva5' => $iva5,
                        ':iva10' => $iva10,
                        ':piva5' => $piva5,
                        ':piva10' => $piva10,
                        ':total' => $total,
                        ':pagado' => $pagadoInicial,
                        ':pendiente' => $pendienteInicial,
                        ':nota' => $nota,
                        ':id_login' => $id_login,
                        ':tipo_compra' => $isGastoCompra ? 2 : 1,
                        ':id_factura' => $id_factura
                    ];
                    if ($hasFcIdMoneda) $paramsUpd[':id_moneda'] = $id_moneda;
                    if ($hasFcCambio) $paramsUpd[':cambio'] = $cambio;
                    if ($hasFcMedioPago) $paramsUpd[':medio_pago'] = $medioPago;
                    if ($hasFcTipoComprobante) $paramsUpd[':tipo_comprobante'] = $tipoComprobante;
                    $stmt->execute($paramsUpd);
                    $pdo->prepare("UPDATE $dbName.factura_compras SET credit_installments = :installments, credit_due_date = :due_date, credit_notes = :notes WHERE id_factura = :id_factura")
                        ->execute([
                            ':installments' => $medioPago === 'CREDITO' ? $creditInstallments : null,
                            ':due_date' => $medioPago === 'CREDITO' && $creditDueDate !== '' ? $creditDueDate : null,
                            ':notes' => $medioPago === 'CREDITO' && $creditNotes !== '' ? mb_substr($creditNotes, 0, 255) : null,
                            ':id_factura' => $id_factura
                        ]);

                    if ((int)($existingCompraMeta['tipo_compra'] ?? 1) === 2) {
                        $pdo->prepare("UPDATE $dbName.gastos_empresa SET estado = 'ANULADO' WHERE id_factura_compra = :id AND origen = 'COMPRA_GASTO'")->execute([':id' => $id_factura]);
                    }
                    if ((int)($existingCompraMeta['tipo_compra'] ?? 1) !== 2) {
                        comprasStockSnapshotRevertFactura($pdo, $dbName, (int)$id_factura);
                        $pdo->prepare("UPDATE $dbName.extracto_productos SET estado = 0 WHERE referencia = 2 AND idfactura = :id")->execute([':id' => $id_factura]);
                    }
                } else {
                    // INSERT new
                    $insertCols = [
                        'tipo_compra', 'forma_pago', 'estado', 'id_empresa', 'id_sucursal', 'id_login',
                        'fecha', 'vencimiento', 'nro_factura', 'id_cliente', 'ruc', 'cantidad',
                        'exenta', 'iva5', 'iva10', 'piva5', 'piva10',
                        'timbrado', 'vencimiento_timbrado', 'total', 'pendiente', 'pagado', 'nota'
                    ];
                    $insertVals = [
                        ':tipo_compra', ':forma_pago', '1', ':id_empresa', ':id_sucursal', ':id_login',
                        ':fecha', ':vencimiento', ':nro_factura', ':id_proveedor', ':ruc', ':cantidad',
                        ':exenta', ':iva5', ':iva10', ':piva5', ':piva10',
                        ':timbrado', ':vencimiento_timbrado', ':total', ':pendiente', ':pagado', ':nota'
                    ];
                    if ($hasFcIdMoneda) {
                        $insertCols[] = 'id_moneda';
                        $insertVals[] = ':id_moneda';
                    }
                    if ($hasFcCambio) {
                        $insertCols[] = 'cambio';
                        $insertVals[] = ':cambio';
                    }
                    if ($hasFcMedioPago) {
                        $insertCols[] = 'medio_pago';
                        $insertVals[] = ':medio_pago';
                    }
                    if ($hasFcTipoComprobante) {
                        $insertCols[] = 'tipo_comprobante';
                        $insertVals[] = ':tipo_comprobante';
                    }
                    $insertCols[] = 'credit_installments';
                    $insertVals[] = ':credit_installments';
                    $insertCols[] = 'credit_due_date';
                    $insertVals[] = ':credit_due_date';
                    $insertCols[] = 'credit_notes';
                    $insertVals[] = ':credit_notes';

                    $stmt = $pdo->prepare("INSERT INTO $dbName.factura_compras (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")");
                    $paramsIns = [
                        ':tipo_compra' => $isGastoCompra ? 2 : 1,
                        ':forma_pago' => $forma_pago,
                        ':id_empresa' => $id_empresa,
                        ':id_sucursal' => $id_sucursal,
                        ':id_login' => $id_login,
                        ':fecha' => $fecha,
                        ':vencimiento' => $vencimiento,
                        ':nro_factura' => $nro_factura,
                        ':id_proveedor' => $id_proveedor,
                        ':ruc' => $ruc,
                        ':cantidad' => $cantidad,
                        ':exenta' => $exenta,
                        ':iva5' => $iva5,
                        ':iva10' => $iva10,
                        ':piva5' => $piva5,
                        ':piva10' => $piva10,
                        ':timbrado' => $timbrado,
                        ':vencimiento_timbrado' => $vencimiento_timbrado,
                        ':total' => $total,
                        ':pendiente' => $pendienteInicial,
                        ':pagado' => $pagadoInicial,
                        ':nota' => $nota
                    ];
                    if ($hasFcIdMoneda) $paramsIns[':id_moneda'] = $id_moneda;
                    if ($hasFcCambio) $paramsIns[':cambio'] = $cambio;
                    if ($hasFcMedioPago) $paramsIns[':medio_pago'] = $medioPago;
                    if ($hasFcTipoComprobante) $paramsIns[':tipo_comprobante'] = $tipoComprobante;
                    $paramsIns[':credit_installments'] = $medioPago === 'CREDITO' ? $creditInstallments : null;
                    $paramsIns[':credit_due_date'] = $medioPago === 'CREDITO' && $creditDueDate !== '' ? $creditDueDate : null;
                    $paramsIns[':credit_notes'] = $medioPago === 'CREDITO' && $creditNotes !== '' ? mb_substr($creditNotes, 0, 255) : null;
                    $stmt->execute($paramsIns);
                    $id_factura = $pdo->lastInsertId();
                }

                if ($isGastoCompra) {
                    $stmtGasto = $pdo->prepare("
                        INSERT INTO $dbName.gastos_empresa (
                            id_empresa, id_sucursal, id_caja, id_factura_compra, id_proveedor,
                            fecha, concepto, observacion, monto, pagado_por_tipo, pagado_por_detalle,
                            origen, estado, creado_por
                        ) VALUES (
                            :id_empresa, :id_sucursal, :id_caja, :id_factura_compra, :id_proveedor,
                            :fecha, :concepto, :observacion, :monto, :pagado_por_tipo, :pagado_por_detalle,
                            'COMPRA_GASTO', 'ACTIVO', :creado_por
                        )
                    ");
                } else {
                    $epInsertCols = [
                        'idproducto', 'id_cliente', 'idfactura', 'estado', 'id_sucursal', 'fecha', 'referencia',
                        'codigo', 'descripcion', 'entrada', 'salida',
                        'costo_gs', 'costo', 'importe_gs', 'importe', 'tipo_iva'
                    ];
                    $epInsertVals = [
                        ':idproducto', ':id_proveedor', ':id_factura', '1', ':id_sucursal', ':fecha', '2',
                        ':codigo', ':descripcion', ':cantidad', '0',
                        ':costo', ':costo', ':importe', ':importe', ':tipo_iva'
                    ];
                    if ($hasEpIdMoneda) {
                        $epInsertCols[] = 'id_moneda';
                        $epInsertVals[] = ':id_moneda';
                    }
                    if ($hasEpCambio) {
                        $epInsertCols[] = 'cambio';
                        $epInsertVals[] = ':cambio';
                    }
                    $stmtItem = $pdo->prepare("INSERT INTO $dbName.extracto_productos (" . implode(', ', $epInsertCols) . ") VALUES (" . implode(', ', $epInsertVals) . ")");
                }

                foreach ($items as $item) {
                    $codigo = trim($item['codigo'] ?? '');
                    $descripcion = trim($item['descripcion'] ?? '');
                    $rubro = trim((string)($item['rubro'] ?? $descripcion));
                    $costo = (float)($item['costo'] ?? 0);
                    $tipoIva = (int)($item['tipo_iva'] ?? ($isGastoCompra ? 1 : 3));
                    $idproducto = (int)($item['idproducto'] ?? $item['id'] ?? 0);
                    $suggestedPrice = (float)($item['venta_ocr'] ?? 0);
                    if ($suggestedPrice <= 0) {
                        $suggestedPrice = round($costo * 1.35);
                    }

                    if ($isGastoCompra) {
                        $stmtGasto->execute([
                            ':id_empresa' => $id_empresa,
                            ':id_sucursal' => $id_sucursal,
                            ':id_caja' => $id_caja > 0 ? $id_caja : null,
                            ':id_factura_compra' => $id_factura,
                            ':id_proveedor' => $id_proveedor > 0 ? $id_proveedor : null,
                            ':fecha' => $fecha,
                            ':concepto' => mb_substr($rubro !== '' ? $rubro : 'GASTO', 0, 140),
                            ':observacion' => $descripcion !== '' ? mb_substr($descripcion, 0, 255) : null,
                            ':monto' => (float)($item['cantidad'] ?? 0) * $costo,
                            ':pagado_por_tipo' => $medioPago === 'CREDITO' ? 'CREDITO' : 'CAJA',
                            ':pagado_por_detalle' => $medioPago,
                            ':creado_por' => $id_login > 0 ? $id_login : null,
                        ]);
                        continue;
                    }

                    // Check if product exists by code and/or name
                    if ($idproducto <= 0 && (!empty($codigo) || !empty($descripcion))) {
                        $existingId = null;
                        if (!empty($codigo)) {
                            $stmtSearchCode = $pdo->prepare("SELECT idproducto FROM $dbName.tblproductos WHERE cve_producto = :codigo LIMIT 1");
                            $stmtSearchCode->execute([':codigo' => $codigo]);
                            $existingId = $stmtSearchCode->fetchColumn();
                        }
                        // Si no encontró por código, probar por nombre antes de crear uno nuevo.
                        if (!$existingId && !empty($descripcion)) {
                            $stmtSearchName = $pdo->prepare("SELECT idproducto FROM $dbName.tblproductos WHERE desproducto = :descripcion LIMIT 1");
                            $stmtSearchName->execute([':descripcion' => $descripcion]);
                            $existingId = $stmtSearchName->fetchColumn();
                        }

                        if ($existingId) {
                            $idproducto = (int)$existingId;
                        } else {
                            // Product doesn't exist - create it
                            if (empty($codigo)) {
                                $codigo = 'OCR' . date('ymdHis') . rand(100, 999);
                            }

                            $stmtNewProd = $pdo->prepare("
                                INSERT INTO $dbName.tblproductos (
                                    cve_producto, desproducto, precio_compra, precio_venta, iva, Estado, 
                                    stock_minimo, stock_maximo
                                ) VALUES (
                                    :codigo, :descripcion, :costo, :precio, :tipo_iva, 1,
                                    0, 0
                                )
                            ");
                            $stmtNewProd->execute([
                                ':codigo' => $codigo,
                                ':descripcion' => $descripcion,
                                ':costo' => $costo,
                                ':precio' => $suggestedPrice,
                                ':tipo_iva' => $tipoIva
                            ]);
                            $idproducto = $pdo->lastInsertId();
                            $productosNuevos[] = [
                                'codigo' => $codigo,
                                'descripcion' => $descripcion,
                                'costo' => $costo,
                                'precio_venta' => $suggestedPrice
                            ];
                        }
                    }

                    // SYNC PRICES & BARCODES (for all products)
                    if ($idproducto > 0) {
                        $precioTipo1 = round($suggestedPrice);
                        if ($precioTipo1 <= 0) {
                            $precioTipo1 = round($costo * 1.35);
                        }
                        // Tipos 2 y 3 derivados desde precio de venta (tipo 1), no desde costo.
                        // Se mantienen proporciones históricas: 130/135 y 125/135.
                        $precioTipo2 = round($precioTipo1 * (130 / 135));
                        $precioTipo3 = round($precioTipo1 * (125 / 135));

                        // 1. Sync mercaderia_precio (levels 1, 2, 3)
                        // Tipo 1 se actualiza siempre con el precio de venta enviado desde la compra.
                        $stmtCheckPrice = $pdo->prepare("SELECT tipo FROM $dbName.mercaderia_precio WHERE codigo = :id");
                        $stmtCheckPrice->execute([':id' => $idproducto]);
                        $existingLevels = $stmtCheckPrice->fetchAll(PDO::FETCH_COLUMN);

                        $stmtNewPrice = $pdo->prepare("INSERT INTO $dbName.mercaderia_precio (codigo, tipo, precio, costo, moneda) VALUES (:id, :tipo, :precio, :costo, 1)");
                        $stmtUpdPrice = $pdo->prepare("UPDATE $dbName.mercaderia_precio SET precio = :precio, costo = :costo, moneda = 1 WHERE codigo = :id AND tipo = :tipo");

                        if (!in_array(1, $existingLevels)) {
                            $stmtNewPrice->execute([':id' => $idproducto, ':tipo' => 1, ':precio' => $precioTipo1, ':costo' => $costo]);
                        } else {
                            $stmtUpdPrice->execute([':id' => $idproducto, ':tipo' => 1, ':precio' => $precioTipo1, ':costo' => $costo]);
                        }
                        if (!in_array(2, $existingLevels)) {
                            $stmtNewPrice->execute([':id' => $idproducto, ':tipo' => 2, ':precio' => $precioTipo2, ':costo' => $costo]);
                        } else {
                            $stmtUpdPrice->execute([':id' => $idproducto, ':tipo' => 2, ':precio' => $precioTipo2, ':costo' => $costo]);
                        }
                        if (!in_array(3, $existingLevels)) {
                            $stmtNewPrice->execute([':id' => $idproducto, ':tipo' => 3, ':precio' => $precioTipo3, ':costo' => $costo]);
                        } else {
                            $stmtUpdPrice->execute([':id' => $idproducto, ':tipo' => 3, ':precio' => $precioTipo3, ':costo' => $costo]);
                        }

                        // 2. Sync codigo_barra
                        if (!empty($codigo)) {
                            $stmtCheckBar = $pdo->prepare("SELECT id FROM $dbName.codigo_barra WHERE id_producto = :id AND codigo_barra = :bar");
                            $stmtCheckBar->execute([':id' => $idproducto, ':bar' => $codigo]);
                            if (!$stmtCheckBar->fetch()) {
                                $stmtAddBar = $pdo->prepare("INSERT INTO $dbName.codigo_barra (id_producto, codigo_barra, id_login) VALUES (:id, :bar, :login)");
                                $stmtAddBar->execute([':id' => $idproducto, ':bar' => $codigo, ':login' => $id_login]);
                            }
                        }
                    }

                    $itemParams = [
                        ':idproducto' => $idproducto,
                        ':id_proveedor' => $id_proveedor,
                        ':id_factura' => $id_factura,
                        ':id_sucursal' => $id_sucursal,
                        ':fecha' => $fecha,
                        ':codigo' => $codigo,
                        ':descripcion' => $descripcion,
                        ':cantidad' => (float)($item['cantidad'] ?? 0),
                        ':costo' => $costo,
                        ':importe' => (float)($item['cantidad'] ?? 0) * $costo,
                        ':tipo_iva' => $tipoIva
                    ];
                    if ($hasEpIdMoneda) $itemParams[':id_moneda'] = $id_moneda;
                    if ($hasEpCambio) $itemParams[':cambio'] = $cambio;
                    $stmtItem->execute($itemParams);
                    comprasStockSnapshotAdjust(
                        $pdo,
                        $dbName,
                        (int)$idproducto,
                        (int)$id_sucursal,
                        (float)($item['cantidad'] ?? 0)
                    );
                }

                if ($medioPago === 'CREDITO') {
                    persistCreditPurchaseArtifacts(
                        $pdo,
                        $dbName,
                        (int)$id_factura,
                        (int)$id_proveedor,
                        (int)$id_login,
                        (int)$id_sucursal,
                        (string)$nro_factura,
                        (string)$fecha,
                        (float)$total,
                        [
                            'installments' => $creditInstallments,
                            'due_date' => $creditDueDate,
                            'notes' => $creditNotes,
                        ]
                    );
                } else {
                    cleanupCreditPurchaseArtifacts($pdo, $dbName, (int)$id_factura);
                }

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Compra guardada correctamente',
                    'id_factura' => $id_factura,
                    'tipo_compra' => $isGastoCompra ? 2 : 1,
                    'productos_nuevos' => $productosNuevos
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        // ===== VOID COMPRA =====
        case 'void':
            $input = $jsonInput;
            $id_factura = (int)($input['id_factura'] ?? 0);

            if ($id_factura <= 0) {
                throw new Exception("ID de factura inválido");
            }

            $pdo->beginTransaction();
            try {
                // Update header
                $pdo->prepare("UPDATE $dbName.factura_compras SET estado = 0 WHERE id_factura = :id")->execute([':id' => $id_factura]);
                // Update items
                comprasStockSnapshotRevertFactura($pdo, $dbName, $id_factura);
                $pdo->prepare("UPDATE $dbName.extracto_productos SET estado = 0 WHERE referencia = 2 AND idfactura = :id")->execute([':id' => $id_factura]);
                if (sxTableExists($pdo, $dbName, 'gastos_empresa') && sxColumnExists($pdo, $dbName, 'gastos_empresa', 'id_factura_compra')) {
                    $pdo->prepare("UPDATE $dbName.gastos_empresa SET estado = 'ANULADO' WHERE id_factura_compra = :id")->execute([':id' => $id_factura]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Compra anulada correctamente']);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        // ===== SAVE PAYMENT (REPLICA OF EMITIR_RECIBO) =====
        case 'save_payment':
            $input = $jsonInput;
            $id_factura = (int)($input['id_factura'] ?? 0);
            $monto = (float)($input['monto'] ?? 0);
            $forma_pago = trim($input['forma_pago'] ?? 'Efectivo');
            $observacion = trim($input['observacion'] ?? '');

            if ($id_factura <= 0 || $monto <= 0) {
                throw new Exception("ID de factura y monto deben ser válidos");
            }

            // Get purchase header
            $stmt = $pdo->prepare("SELECT id_factura, nro_factura, total, pagado, pendiente, id_cliente, ruc FROM $dbName.factura_compras WHERE id_factura = :id");
            $stmt->execute([':id' => $id_factura]);
            $compra = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$compra) throw new Exception("Compra no encontrada");
            if ($monto > ((float)$compra['pendiente'] + 0.009)) {
                throw new Exception("El monto supera el pendiente de la compra");
            }

            sxComprasCreditoEnsureDocumentosCompraTable($pdo, $dbName);
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS $dbName.recibos_pago (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nro_recibo VARCHAR(20),
                    id_factura INT,
                    nro_factura VARCHAR(50),
                    id_cliente INT,
                    monto DECIMAL(15,2),
                    forma_pago VARCHAR(50),
                    observacion TEXT,
                    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
                    id_usuario INT,
                    id_empresa INT,
                    INDEX idx_factura (id_factura),
                    INDEX idx_cliente (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8
            ");

            $pdo->beginTransaction();
            try {
                // Generate receipt number
                $stmtMax = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM $dbName.recibos_pago");
                $nextId = $stmtMax->fetchColumn();
                $nroRecibo = 'PAGO-' . str_pad($nextId, 7, '0', STR_PAD_LEFT);

                // Insert payment record
                $stmtIns = $pdo->prepare("
                    INSERT INTO $dbName.recibos_pago (nro_recibo, id_factura, nro_factura, id_cliente, monto, forma_pago, observacion, id_usuario, id_empresa)
                    VALUES (:nro, :id_f, :nro_f, :id_c, :monto, :forma, :obs, :user, :emp)
                ");
                $stmtIns->execute([
                    ':nro' => $nroRecibo,
                    ':id_f' => $id_factura,
                    ':nro_f' => $compra['nro_factura'],
                    ':id_c' => $compra['id_cliente'],
                    ':monto' => $monto,
                    ':forma' => $forma_pago,
                    ':obs' => $observacion,
                    ':user' => $id_login,
                    ':emp' => $id_empresa
                ]);

                // Update purchase balance
                $newPagado = (float)$compra['pagado'] + $monto;
                $stmtUpd = $pdo->prepare("UPDATE $dbName.factura_compras SET pagado = :pagado, pendiente = total - :pagado2 WHERE id_factura = :id");
                $stmtUpd->execute([':pagado' => $newPagado, ':pagado2' => $newPagado, ':id' => $id_factura]);

                sxComprasCreditoInsertSupplierPaymentLedger(
                    $pdo,
                    $dbName,
                    $id_factura,
                    (int)$compra['id_cliente'],
                    $id_login,
                    $monto,
                    $forma_pago,
                    (string)($compra['nro_factura'] ?? ''),
                    $observacion
                );
                sxComprasCreditoApplyPaymentToInstallments($pdo, $dbName, $id_factura, $monto);
                sxComprasCreditoSyncFacturaBalance($pdo, $dbName, $id_factura);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pago registrado correctamente', 'nro_recibo' => $nroRecibo]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        // ===== GET PAYMENTS (FOR DETAIL GRID) =====
        case 'get_payments':
            $id_factura = (int)($_GET['id_factura'] ?? 0);

            // Check if table exists
            $tableCheck = $pdo->prepare("SHOW TABLES LIKE '$dbName.recibos_pago'");
            $tableCheck->execute();
            if (!$tableCheck->fetch()) {
                echo json_encode(['success' => true, 'payments' => []]);
                break;
            }

            $stmt = $pdo->prepare("SELECT * FROM $dbName.recibos_pago WHERE id_factura = :id ORDER BY fecha DESC");
            $stmt->execute([':id' => $id_factura]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'payments' => $payments]);
            break;

        default:
            throw new Exception("Acción no válida: $action");
    }
} catch (Exception $e) {
    if ($action === 'search_proveedor' || $action === 'search_proveedores') {
        error_log('[compras_api][global_catch][search_proveedor] ' . $e->getMessage());
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'proveedores' => []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
