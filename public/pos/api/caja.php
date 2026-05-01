<?php

/**
 * POS API - Control de Caja
 * Gestión de operaciones de caja
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/schema_compat.php';
require_once __DIR__ . '/../../../modelos/compras_credito_helpers.php';
require_once __DIR__ . '/push_notify_helper.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$soft_error = (int)($_GET['soft_error'] ?? $_POST['soft_error'] ?? 0) === 1;
$id_empresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? 0);
$session_admin_raw = strtoupper(trim((string)($_SESSION['usr_priv_admin'] ?? 'N')));
$is_admin = false;
$admin_role_resolved = '';
$admin_priv_resolved = '';
$id_caja = (int)($_GET['id_caja'] ?? $_POST['id_caja'] ?? $_SESSION['id_caja_def'] ?? 1);
$solo_mias = (int)($_GET['solo_mias'] ?? $_POST['solo_mias'] ?? 0) === 1;
$login_usuario = trim((string)($_SESSION['username'] ?? $_SESSION['login'] ?? ''));

// Validación estricta de admin desde master DB.
// Regla: solo es admin si sec_users indica privilegio admin y/o role contiene ADMIN.
if ($id_login > 0) {
    try {
        $pdo_master = getMasterConnection();
        $hasEmpresaColumn = false;
        try {
            $stmtCol = $pdo_master->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = :db
                  AND table_name = 'sec_users'
                  AND column_name = 'id_empresa'
            ");
            $stmtCol->execute([':db' => MASTER_DB]);
            $hasEmpresaColumn = ((int)$stmtCol->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $hasEmpresaColumn = false;
        }

        $userRow = null;
        if ($hasEmpresaColumn) {
            $stmtAdmin = $pdo_master->prepare("
                SELECT priv_admin, role
                FROM " . MASTER_DB . ".sec_users
                WHERE id_login = :id_login
                  AND id_empresa = :id_empresa
                LIMIT 1
            ");
            $stmtAdmin->execute([':id_login' => $id_login, ':id_empresa' => $id_empresa]);
            $userRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$userRow) {
            $stmtAdmin = $pdo_master->prepare("
                SELECT priv_admin, role
                FROM " . MASTER_DB . ".sec_users
                WHERE id_login = :id_login
                LIMIT 1
            ");
            $stmtAdmin->execute([':id_login' => $id_login]);
            $userRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($userRow) {
            $privNorm = strtoupper(trim((string)($userRow['priv_admin'] ?? 'N')));
            $roleNorm = strtoupper(trim((string)($userRow['role'] ?? '')));
            $admin_priv_resolved = $privNorm;
            $admin_role_resolved = $roleNorm;
            $hasAdminPriv = in_array($privNorm, ['Y', 'S', '1', 'TRUE'], true);
            $hasAdminRole = in_array($roleNorm, ['ADMIN', 'SUPERADMIN', 'ROOT'], true);
            $is_admin = ($roleNorm !== '') ? $hasAdminRole : $hasAdminPriv;
        }
    } catch (Throwable $e) {
        $is_admin = false;
    }
}

if ($login_usuario === '' && $id_login > 0) {
    try {
        $pdo_master = getMasterConnection();
        $stmt_login = $pdo_master->prepare("SELECT login FROM " . MASTER_DB . ".sec_users WHERE id_login = :id LIMIT 1");
        $stmt_login->execute([':id' => $id_login]);
        $login_usuario = trim((string)($stmt_login->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $login_usuario = '';
    }
}
$id_login_txt = (string)$id_login;

try {
    // Obtener conexión a la base de datos de la empresa
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    ensurePosSchemaCompatibility($pdo, (string)$dbName);
    $hasMedioCobro = false;
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM $dbName.extracto_caja LIKE 'medio_cobro'");
        $hasMedioCobro = ($stmtCol && $stmtCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasMedioCobro = false;
    }

    function reverseAccounting($pdo, $dbName, $id, $op, $relTable, $relId, $monto, $ref)
    {
        // --- REVERSA COBRO FACTURA VENTA (Ref 41) ---
        if ($ref == 41 || $relTable == 'factura_ventas') {
            $idFact = $relId;
            if ($idFact) {
                // Restaurar factura como pendiente
                restoreFacturaPendiente($pdo, $dbName, $idFact, $monto);

                // Anular extracto_cliente relacionado
                $stmtCli = $pdo->prepare("UPDATE $dbName.extracto_cliente SET estado = 0 WHERE id_factura = :id_fact AND credito = :monto AND estado = 1 LIMIT 1");
                $stmtCli->execute([':id_fact' => $idFact, ':monto' => $monto]);
            }
        }
        // --- REVERSA COBRO DOCUMENTO (Ref 1203 or New 'Cobro de documentos') ---
        else if ($ref == 1203 || $relTable == 'documentos' || $op['concepto'] == 'Cobro de documentos') {
            $idDoc = $relId;
            if ($idDoc) {
                // Obtener documento para ver si tiene factura vinculada
                $stmtDoc = $pdo->prepare("SELECT id_factura FROM $dbName.documentos WHERE numero = :id");
                $stmtDoc->execute([':id' => $idDoc]);
                $idFact = $stmtDoc->fetchColumn();

                // Restaurar saldo documento
                $pdo->exec("UPDATE $dbName.documentos SET pagado = pagado - $monto, pendiente = pendiente + $monto WHERE numero = $idDoc");

                if ($idFact) {
                    // Restaurar factura vinculada como pendiente
                    restoreFacturaPendiente($pdo, $dbName, $idFact, $monto);
                }

                // Anular extracto_cliente relacionado
                $stmtCli = $pdo->prepare("UPDATE $dbName.extracto_cliente SET estado = 0 WHERE id_relacion = :id_doc AND tabla_relacion = 'documentos' AND credito = :monto AND estado = 1 LIMIT 1");
                $stmtCli->execute([':id_doc' => $idDoc, ':monto' => $monto]);
            }
        }
        // --- REVERSA PAGO FACTURA COMPRA (Ref 39) ---
        else if ($ref == 39 || $relTable == 'factura_compras') {
            $idFact = $relId;
            if ($idFact) {
                sxComprasCreditoReversePaymentOnInstallments($pdo, $dbName, (int)$idFact, (float)$monto);
                sxComprasCreditoReverseSupplierPaymentLedger($pdo, $dbName, (int)$idFact, (float)$monto);
                sxComprasCreditoSyncFacturaBalance($pdo, $dbName, (int)$idFact);
            }
        }
        // --- REVERSA EXTRACTO CLIENTE (Generic - Cobros/Pagos Varios) ---
        else if ($relTable == 'clientes' || in_array($ref, [16, 27, 28])) {
            $idCliente = $relId;
            if ($idCliente) {
                // Anular el registro más reciente de ese cliente con concepto similar
                $stmtCli = $pdo->prepare("UPDATE $dbName.extracto_cliente SET estado = 0 WHERE codigo = :id_cli AND concepto LIKE :concepto AND estado = 1 ORDER BY id DESC LIMIT 1");
                $stmtCli->execute([
                    ':id_cli' => $idCliente,
                    ':concepto' => substr($op['concepto'], 0, 20) . '%'
                ]);
            }
        }
    }

    function cajaAbiertaHoy($pdo, $dbName, $idCaja): bool
    {
        $sql = "
            SELECT
                MAX(CASE
                    WHEN (UPPER(TRIM(concepto)) = 'APERTURA DE CAJA' OR referencia = 16)
                    THEN id ELSE 0 END
                ) AS apertura_id,
                MAX(CASE
                    WHEN (UPPER(concepto) LIKE 'CIERRE DE CAJA%' OR referencia = 11)
                    THEN id ELSE 0 END
                ) AS cierre_id
            FROM $dbName.extracto_caja
            WHERE codigo = :id_caja
              AND DATE(fecha) = CURDATE()
              AND estado = 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_caja' => $idCaja]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['apertura_id' => 0, 'cierre_id' => 0];
        $aperturaId = (int)($row['apertura_id'] ?? 0);
        $cierreId = (int)($row['cierre_id'] ?? 0);

        return $aperturaId > 0 && $aperturaId > $cierreId;
    }

    function assertOperacionPropia($pdo, $dbName, $idOperacion, $idLogin, $loginUsuario = '', $idLoginTxt = ''): void
    {
        $stmt = $pdo->prepare("
            SELECT id
            FROM $dbName.extracto_caja
            WHERE id = :id
              AND (
                    id_login = :id_login
                    OR CAST(login AS CHAR) = :login_usuario
                    OR CAST(login AS CHAR) = :id_login_txt
              )
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $idOperacion,
            ':id_login' => $idLogin,
            ':login_usuario' => (string)$loginUsuario,
            ':id_login_txt' => (string)$idLoginTxt
        ]);
        if (!$stmt->fetchColumn()) {
            throw new Exception("Solo puede modificar operaciones propias desde Mi Caja");
        }
    }

    function tableExists($pdo, $dbName, $table): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = :db AND table_name = :tb
        ");
        $stmt->execute([':db' => $dbName, ':tb' => $table]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    function tableColumns($pdo, $dbName, $table): array
    {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.columns
            WHERE table_schema = :db AND table_name = :tb
        ");
        $stmt->execute([':db' => $dbName, ':tb' => $table]);
        return array_map(static fn($r) => (string)$r['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    function tableColumnType($pdo, $dbName, $table, $column): string
    {
        $stmt = $pdo->prepare("
            SELECT DATA_TYPE
            FROM information_schema.columns
            WHERE table_schema = :db AND table_name = :tb AND column_name = :col
            LIMIT 1
        ");
        $stmt->execute([':db' => $dbName, ':tb' => $table, ':col' => $column]);
        return strtolower(trim((string)($stmt->fetchColumn() ?: '')));
    }

    function restoreFacturaPendiente($pdo, $dbName, $idFactura, $monto): void
    {
        $idFactura = (int)$idFactura;
        $monto = abs((float)$monto);
        if ($idFactura <= 0 || $monto <= 0) return;

        $cols = tableColumns($pdo, $dbName, 'factura_ventas');
        if (!$cols) return;

        $sets = [];
        $params = [':id' => $idFactura, ':monto' => $monto];

        if (in_array('saldo', $cols, true)) {
            $sets[] = "saldo = IFNULL(saldo, total) + :monto";
        }
        if (in_array('pendiente', $cols, true)) {
            $sets[] = "pendiente = GREATEST(IFNULL(pendiente, 0) + :monto, 0)";
        }

        $estadoCol = null;
        foreach (['estado_cobro', 'estado_pago', 'situacion'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $estadoCol = $candidate;
                break;
            }
        }
        if ($estadoCol === null && in_array('estado', $cols, true)) {
            $tipoEstado = tableColumnType($pdo, $dbName, 'factura_ventas', 'estado');
            if (in_array($tipoEstado, ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'enum'], true)) {
                $estadoCol = 'estado';
            }
        }
        if ($estadoCol !== null) {
            $sets[] = "$estadoCol = :estado_pend";
            $params[':estado_pend'] = 'PENDIENTE';
        }

        if (empty($sets)) return;

        $sql = "UPDATE $dbName.factura_ventas SET " . implode(', ', $sets) . " WHERE id_factura = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    function pickColumn(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) return $c;
        }
        return null;
    }

    function facturaPendienteWhereSql(array $factCols): array
    {
        $totalExpr = in_array('saldo', $factCols, true)
            ? "COALESCE(f.saldo, f.total, 0)"
            : (in_array('pendiente', $factCols, true)
                ? "COALESCE(f.pendiente, f.total, 0)"
                : "COALESCE(f.total, 0)");

        $where = ["{$totalExpr} > 0.01"];

        if (in_array('medio_cobro', $factCols, true)) {
            $where[] = "UPPER(TRIM(COALESCE(f.medio_cobro, ''))) = 'PENDIENTE'";
        }

        if (in_array('estado', $factCols, true)) {
            $where[] = "COALESCE(f.estado, 1) = 1";
        }

        return [$where, $totalExpr];
    }

    function smartInsert($pdo, $dbName, $table, array $row): int
    {
        $cols = tableColumns($pdo, $dbName, $table);
        $allowed = array_flip($cols);
        $insertCols = [];
        $insertVals = [];
        $params = [];

        foreach ($row as $k => $v) {
            if (!isset($allowed[$k])) continue;
            $insertCols[] = $k;
            $p = ':' . $k;
            $insertVals[] = $p;
            $params[$p] = $v;
        }

        if (empty($insertCols)) {
            throw new Exception("No hay columnas compatibles para guardar en $table");
        }

        $sql = "INSERT INTO $dbName.$table (" . implode(',', $insertCols) . ")
                VALUES (" . implode(',', $insertVals) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    }

    function buildPublicDocReceiptToken(int $idEmpresa, int $idCajaOp, int $idDocumento): string
    {
        $secret = hash('sha256', 'sistemax-doc-receipt|' . MASTER_DB . '|v1');
        return hash_hmac('sha256', $idEmpresa . '|' . $idCajaOp . '|' . $idDocumento, $secret);
    }

    function buildPublicDocReceiptUrl(int $idEmpresa, int $idCajaOp, int $idDocumento): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $path = '/public/pos/cobro_documento_comprobante.php';
        $query = http_build_query([
            'id_empresa' => $idEmpresa,
            'id' => $idCajaOp,
            'doc' => $idDocumento,
            'token' => buildPublicDocReceiptToken($idEmpresa, $idCajaOp, $idDocumento),
        ]);
        if ($host !== '') {
            return $scheme . '://' . $host . $path . '?' . $query;
        }
        return $path . '?' . $query;
    }

    function ensureCajaVoidApprovalTable($pdo, $dbName): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS $dbName.caja_void_approvals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tipo VARCHAR(30) NOT NULL DEFAULT 'VOID_OPERACION',
                id_operacion INT NOT NULL,
                id_caja INT NOT NULL DEFAULT 0,
                id_empresa INT NOT NULL DEFAULT 0,
                estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
                solicitado_por_id_login INT NOT NULL DEFAULT 0,
                solicitado_por_login VARCHAR(80) NULL,
                aprobado_por_id_login INT NULL,
                aprobado_por_login VARCHAR(80) NULL,
                motivo VARCHAR(255) NULL,
                payload_json TEXT NULL,
                fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_resolucion DATETIME NULL,
                INDEX idx_void_estado (estado, fecha_solicitud),
                INDEX idx_void_operacion (id_operacion, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $pdo->exec($sql);
    }

    function findPendingVoidApproval($pdo, $dbName, $idOperacion)
    {
        $stmt = $pdo->prepare("
            SELECT id, fecha_solicitud, solicitado_por_login
            FROM $dbName.caja_void_approvals
            WHERE id_operacion = :id_op
              AND tipo = 'VOID_OPERACION'
              AND estado = 'PENDIENTE'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':id_op' => (int)$idOperacion]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function findApprovedVoidApproval($pdo, $dbName, $idOperacion, $idLogin)
    {
        $stmt = $pdo->prepare("
            SELECT id
            FROM $dbName.caja_void_approvals
            WHERE id_operacion = :id_op
              AND tipo = 'VOID_OPERACION'
              AND estado = 'APROBADO'
              AND solicitado_por_id_login = :id_login
              AND fecha_resolucion >= (NOW() - INTERVAL 30 MINUTE)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':id_op' => (int)$idOperacion, ':id_login' => (int)$idLogin]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    switch ($action) {
        case 'print_config':
            $idCajaReq = (int)($_GET['id_caja'] ?? $_POST['id_caja'] ?? $id_caja ?? 0);
            if ($idCajaReq <= 0) {
                echo json_encode(['success' => false, 'message' => 'Caja inválida']);
                break;
            }

            $impresoraCfg = '';
            try {
                $stmtImp = $pdo->prepare("SELECT impresora FROM $dbName.cajas WHERE id_caja = :id_caja AND id_empresa = :id_empresa LIMIT 1");
                $stmtImp->execute([':id_caja' => $idCajaReq, ':id_empresa' => $id_empresa]);
                $impresoraCfg = (string)($stmtImp->fetchColumn() ?: '');
            } catch (Throwable $e) {
                $impresoraCfg = '';
            }
            if ($impresoraCfg === '') {
                $stmtImp2 = $pdo->prepare("SELECT impresora FROM $dbName.cajas WHERE id_caja = :id_caja LIMIT 1");
                $stmtImp2->execute([':id_caja' => $idCajaReq]);
                $impresoraCfg = (string)($stmtImp2->fetchColumn() ?: '');
            }

            echo json_encode([
                'success' => true,
                'id_caja' => $idCajaReq,
                'impresora' => trim($impresoraCfg),
            ]);
            break;

        // ===== RESUMEN DASHBOARD =====
        case 'summary':
            $fecha = $_GET['fecha'] ?? date('Y-m-d');
            $fecha_desde = $_GET['fecha_desde'] ?? $fecha;
            $fecha_hasta = $_GET['fecha_hasta'] ?? $fecha;
            $estado = strtolower(trim((string)($_GET['estado'] ?? 'activos')));
            $whereUsuario = $solo_mias ? " AND (e.id_login = :id_login OR CAST(e.login AS CHAR) = :login_usuario OR CAST(e.login AS CHAR) = :id_login_txt)" : "";
            $whereEstado = '';
            if ($estado === 'activos') {
                $whereEstado = " AND COALESCE(e.estado, 1) = 1";
            } elseif ($estado === 'anulados') {
                $whereEstado = " AND e.estado = 0";
            }

            $efectivoCond = $hasMedioCobro
                ? "(UPPER(COALESCE(e.medio_cobro, '')) IN ('EFECTIVO', '1') OR (e.medio_cobro IS NULL AND e.concepto LIKE '%EFECTIVO%'))"
                : "(e.concepto LIKE '%EFECTIVO%')";
            $tarjetaCond = $hasMedioCobro
                ? "(UPPER(COALESCE(e.medio_cobro, '')) = 'TARJETA' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TARJETA%'))"
                : "(e.concepto LIKE '%TARJETA%')";
            $transferCond = $hasMedioCobro
                ? "(UPPER(COALESCE(e.medio_cobro, '')) IN ('TRANSFERENCIA', 'TRANSFER') OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TRANSFER%'))"
                : "(e.concepto LIKE '%TRANSFER%')";
            $qrCond = $hasMedioCobro
                ? "(UPPER(COALESCE(e.medio_cobro, '')) IN ('QR', 'PIX') OR (e.medio_cobro IS NULL AND (e.concepto LIKE '%QR%' OR e.concepto LIKE '%PIX%')))"
                : "((e.concepto LIKE '%QR%') OR (e.concepto LIKE '%PIX%'))";
            $creditoCond = $hasMedioCobro
                ? "(UPPER(COALESCE(e.medio_cobro, '')) = 'CREDITO' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%CREDITO%'))"
                : "(e.concepto LIKE '%CREDITO%')";

            $sql = "SELECT 
                        SUM(CASE WHEN $efectivoCond THEN e.credito - e.debito ELSE 0 END) as efectivo,
                        SUM(CASE WHEN $tarjetaCond THEN e.credito - e.debito ELSE 0 END) as tarjeta,
                        SUM(CASE WHEN $transferCond THEN e.credito - e.debito ELSE 0 END) as transferencia,
                        SUM(CASE WHEN $qrCond THEN e.credito - e.debito ELSE 0 END) as qr_pix,
                        SUM(CASE WHEN $creditoCond THEN e.credito - e.debito ELSE 0 END) as credito,
                        SUM(e.credito) as total_entradas,
                        SUM(e.debito) as total_salidas,
                        SUM(e.credito - e.debito) as saldo
                    FROM $dbName.extracto_caja e
                    WHERE e.codigo = :id_caja 
                      AND DATE(e.fecha) BETWEEN :fecha_desde AND :fecha_hasta
                      $whereEstado
                      $whereUsuario";

            $stmt = $pdo->prepare($sql);
            $params = [':id_caja' => $id_caja, ':fecha_desde' => $fecha_desde, ':fecha_hasta' => $fecha_hasta];
            if ($solo_mias) {
                $params[':id_login'] = $id_login;
                $params[':login_usuario'] = $login_usuario;
                $params[':id_login_txt'] = $id_login_txt;
            }
            $stmt->execute($params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            // Obtener info de la caja
            $stmtCaja = $pdo->prepare("SELECT caja, id_caja FROM $dbName.cajas WHERE id_caja = :id");
            $stmtCaja->execute([':id' => $id_caja]);
            $cajaInfo = $stmtCaja->fetch(PDO::FETCH_ASSOC);

            // Obtener facturas pendientes de cobro con la misma regla que el listado:
            // solo medio_cobro=PENDIENTE y monto pendiente real > 0.
            $factPendientes = ['cantidad' => 0, 'total' => 0];
            try {
                $factCols = tableColumns($pdo, $dbName, 'factura_ventas');
                if (!empty($factCols)) {
                    [$wherePend, $totalPendExpr] = facturaPendienteWhereSql($factCols);
                    $sqlPend = "SELECT COUNT(*) as cantidad, COALESCE(SUM({$totalPendExpr}), 0) as total
                        FROM $dbName.factura_ventas f";
                    if (!empty($wherePend)) {
                        $sqlPend .= " WHERE " . implode(' AND ', $wherePend);
                    }

                    $stmtPend = $pdo->query($sqlPend);
                    if ($stmtPend) {
                        $factPendientes = $stmtPend->fetch(PDO::FETCH_ASSOC) ?: $factPendientes;
                    }
                }
            } catch (Throwable $e) {
                $factPendientes = ['cantidad' => 0, 'total' => 0];
            }

            echo json_encode([
                'success' => true,
                'summary' => [
                    'efectivo' => (float)($summary['efectivo'] ?? 0),
                    'tarjeta' => (float)($summary['tarjeta'] ?? 0),
                    'transferencia' => (float)($summary['transferencia'] ?? 0),
                    'qr_pix' => (float)($summary['qr_pix'] ?? 0),
                    'credito' => (float)($summary['credito'] ?? 0),
                    'total_entradas' => (float)($summary['total_entradas'] ?? 0),
                    'total_salidas' => (float)($summary['total_salidas'] ?? 0),
                    'saldo' => (float)($summary['saldo'] ?? 0),
                    'facturas_pendientes_count' => (int)($factPendientes['cantidad'] ?? 0),
                    'facturas_pendientes_total' => (float)($factPendientes['total'] ?? 0)
                ],
                'caja' => $cajaInfo
            ]);
            break;

        // ===== CATÁLOGOS (OPERACIONES Y REFERENCIAS) =====
        case 'catalogos':
            // Operaciones de caja (filtrar solo las de caja)
            $stmtOps = $pdo->query("SELECT id, operacion FROM " . MASTER_DB . ".operaciones WHERE tabla_extracto = 'extracto_caja' OR operacion LIKE '%Caja%' ORDER BY operacion");
            $operaciones = $stmtOps->fetchAll(PDO::FETCH_ASSOC);

            // Referencias
            $stmtRefs = $pdo->query("SELECT id, referencia FROM " . MASTER_DB . ".referencia WHERE tabla_extracto = 'extracto_caja' OR referencia LIKE '%Caja%' OR referencia LIKE '%Cobro%' OR referencia LIKE '%Pago%' ORDER BY referencia");
            $referencias = $stmtRefs->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'operaciones' => $operaciones,
                'referencias' => $referencias
            ]);
            break;

        // ===== META FORM OPERACIONES =====
        case 'form_meta':
            $currencyLabel = 'Gs.';
            try {
                if (tableExists($pdo, $dbName, 'cajas')) {
                    $cajaCols = tableColumns($pdo, $dbName, 'cajas');
                    $colMoneda = pickColumn($cajaCols, ['moneda', 'id_moneda']);
                    if ($colMoneda) {
                        $stmtMon = $pdo->prepare("SELECT $colMoneda AS moneda FROM $dbName.cajas WHERE id_caja = :id LIMIT 1");
                        $stmtMon->execute([':id' => $id_caja]);
                        $val = trim((string)($stmtMon->fetchColumn() ?? ''));
                        if ($val !== '') {
                            $currencyLabel = $val;
                        }
                    }
                }
                if (is_numeric($currencyLabel) && tableExists($pdo, $dbName, 'monedas')) {
                    $monCols = tableColumns($pdo, $dbName, 'monedas');
                    $idCol = pickColumn($monCols, ['id', 'codigo', 'id_moneda']);
                    $nameCol = pickColumn($monCols, ['simbolo', 'moneda', 'descripcion', 'nombre']);
                    if ($idCol && $nameCol) {
                        $stmtM = $pdo->prepare("SELECT $nameCol FROM $dbName.monedas WHERE $idCol = :id LIMIT 1");
                        $stmtM->execute([':id' => (int)$currencyLabel]);
                        $m = trim((string)($stmtM->fetchColumn() ?? ''));
                        if ($m !== '') $currencyLabel = $m;
                    }
                }
            } catch (Throwable $e) {
            }

            echo json_encode([
                'success' => true,
                'currency' => $currencyLabel,
            ]);
            break;

        // ===== BÚSQUEDA INTELIGENTE POR REFERENCIA =====
        case 'reference_search':
            $type = strtolower(trim((string)($_GET['type'] ?? '')));
            $q = trim((string)($_GET['q'] ?? ''));
            $limit = max(1, min(30, (int)($_GET['limit'] ?? 20)));

            $map = [
                'contacto' => [
                    'table' => 'clientes',
                    'id' => ['id', 'codigo', 'id_cliente'],
                    'name' => ['nombre', 'razon_social', 'cliente'],
                    'extra' => ['ruc', 'ci', 'documento', 'codigo'],
                ],
                'caja' => [
                    'table' => 'cajas',
                    'id' => ['id_caja', 'id', 'codigo'],
                    'name' => ['caja', 'descripcion', 'nombre'],
                    'extra' => ['descripcion', 'codigo'],
                ],
                'banco' => [
                    'table' => 'bancos',
                    'id' => ['id_banco', 'id', 'codigo'],
                    'name' => ['banco', 'nombre', 'descripcion'],
                    'extra' => ['codigo', 'descripcion'],
                ],
                'cuenta' => [
                    'table' => 'cuentas',
                    'id' => ['id_cuenta', 'id', 'codigo'],
                    'name' => ['cuenta', 'descripcion', 'nombre'],
                    'extra' => ['codigo', 'descripcion'],
                ],
            ];

            if (!isset($map[$type])) {
                echo json_encode(['success' => true, 'items' => []]);
                break;
            }

            $cfgRef = $map[$type];
            $table = $cfgRef['table'];
            if (!tableExists($pdo, $dbName, $table)) {
                echo json_encode(['success' => true, 'items' => []]);
                break;
            }

            $colsRef = tableColumns($pdo, $dbName, $table);
            $idCol = pickColumn($colsRef, $cfgRef['id']) ?: $colsRef[0];
            $nameCol = pickColumn($colsRef, $cfgRef['name']) ?: $idCol;
            $extraCol = pickColumn($colsRef, $cfgRef['extra']);
            $extraSelect = $extraCol ? "$extraCol AS extra" : "'' AS extra";
            $sqlRef = "SELECT $idCol AS id, $nameCol AS nombre, $extraSelect
                       FROM $dbName.$table
                       ORDER BY $nameCol ASC
                       LIMIT :limit";
            $stmtRef = $pdo->prepare($sqlRef);
            $stmtRef->bindValue(':limit', $q !== '' ? 220 : $limit, PDO::PARAM_INT);
            $stmtRef->execute();
            $rowsRef = $stmtRef->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($q !== '') {
                $qNorm = strtolower($q);
                $tokens = array_values(array_filter(preg_split('/\s+/', $qNorm)));
                $ranked = [];
                foreach ($rowsRef as $rowItem) {
                    $nombre = strtolower(trim((string)($rowItem['nombre'] ?? '')));
                    $extra = strtolower(trim((string)($rowItem['extra'] ?? '')));
                    $idTxt = strtolower(trim((string)($rowItem['id'] ?? '')));
                    $haystack = trim($nombre . ' ' . $extra . ' ' . $idTxt);
                    $haystackCompact = str_replace(' ', '', $haystack);

                    $allTokens = true;
                    $score = 0;
                    foreach ($tokens as $tk) {
                        if (strpos($haystack, $tk) !== false || strpos($haystackCompact, str_replace(' ', '', $tk)) !== false) {
                            $score += 10;
                            if (strpos($nombre, $tk) === 0) $score += 6;
                            elseif (strpos($haystack, ' ' . $tk) !== false) $score += 4;
                        } else {
                            $allTokens = false;
                            break;
                        }
                    }
                    if (!$allTokens) continue;

                    if ($nombre === $qNorm || $idTxt === $qNorm) $score += 40;
                    elseif (strpos($nombre, $qNorm) === 0) $score += 24;
                    elseif (strpos($haystack, $qNorm) !== false) $score += 12;

                    $rowItem['_score'] = $score;
                    $ranked[] = $rowItem;
                }

                usort($ranked, static function ($a, $b) {
                    $sa = (int)($a['_score'] ?? 0);
                    $sb = (int)($b['_score'] ?? 0);
                    if ($sa === $sb) {
                        return strcmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
                    }
                    return $sb <=> $sa;
                });

                if (!empty($ranked)) {
                    $rowsRef = array_slice($ranked, 0, $limit);
                } else {
                    $rowsRef = [];
                }
            }

            echo json_encode(['success' => true, 'items' => $rowsRef]);
            break;

        // ===== FORM ÚNICO DE OPERACIONES =====
        case 'operation_form_save':
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $tipo = strtolower(trim((string)($input['tipo'] ?? 'entrada')));
            $refType = strtolower(trim((string)($input['referencia_tipo'] ?? 'caja')));
            $refId = (int)($input['referencia_id'] ?? 0);
            $numero = trim((string)($input['numero'] ?? ''));
            $monto = abs((float)($input['importe'] ?? 0));
            $fechaPago = trim((string)($input['fecha_pago'] ?? ''));
            $beneficiario = trim((string)($input['beneficiario'] ?? ''));
            $concepto = trim((string)($input['concepto'] ?? ''));

            if (!in_array($tipo, ['entrada', 'salida'], true)) {
                throw new Exception('Operacion invalida');
            }
            if (!in_array($refType, ['contacto', 'caja', 'banco', 'cuenta'], true)) {
                throw new Exception('Referencia invalida');
            }
            if ($refId <= 0) {
                throw new Exception('Debe seleccionar una referencia');
            }
            if ($monto <= 0) {
                throw new Exception('El importe debe ser mayor a 0');
            }
            if ($concepto === '') {
                throw new Exception('Debe ingresar el concepto');
            }

            $debito = $tipo === 'salida' ? $monto : 0;
            $credito = $tipo === 'entrada' ? $monto : 0;
            $fechaSql = date('Y-m-d H:i:s');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
                $fechaSql = $fechaPago . ' ' . date('H:i:s');
            }

            $targetMap = [
                'contacto' => 'extracto_cliente',
                'caja' => 'extracto_caja',
                'banco' => 'extracto_banco',
                'cuenta' => 'extracto_cuenta',
            ];
            $secondaryTable = $targetMap[$refType];
            if (!tableExists($pdo, $dbName, 'extracto_caja')) {
                throw new Exception("No existe la tabla extracto_caja en esta empresa");
            }

            $operacionId = $tipo === 'entrada' ? 2 : 1;
            $referenciaId = $tipo === 'entrada' ? 16 : 15;
            $conceptoFinal = strtoupper($tipo) . ': ' . $concepto;
            if ($numero !== '') $conceptoFinal .= ' | Nro: ' . $numero;

            $rowCaja = [
                'sucursal' => 1,
                'codigo' => $id_caja,
                'concepto' => substr($conceptoFinal, 0, 60),
                'debito' => $debito,
                'credito' => $credito,
                'fecha' => $fechaSql,
                'login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                'id_login' => $id_login,
                'estado' => 1,
                'moneda' => 1,
                'cambio' => 1,
                'operacion' => $operacionId,
                'referencia' => $referenciaId,
                'comprobante' => substr($numero, 0, 20),
                'beneficiario' => substr($beneficiario, 0, 80),
                'tabla_relacion' => $refType,
                'id_relacion' => $refId,
            ];

            $idCajaInsert = 0;
            $idRefInsert = 0;
            $warning = '';

            $pdo->beginTransaction();
            try {
                // 1) Siempre registrar en extracto_caja (mi caja)
                $idCajaInsert = smartInsert($pdo, $dbName, 'extracto_caja', $rowCaja);

                // 2) Registrar también en extracto de referencia cuando corresponda
                if ($refType !== 'caja') {
                    if (tableExists($pdo, $dbName, $secondaryTable)) {
                        $rowRef = $rowCaja;
                        $rowRef['codigo'] = $refId;
                        $rowRef['tabla_relacion'] = 'extracto_caja';
                        $rowRef['id_relacion'] = $idCajaInsert;
                        $idRefInsert = smartInsert($pdo, $dbName, $secondaryTable, $rowRef);
                    } else {
                        $warning = "No existe la tabla $secondaryTable en esta empresa";
                    }
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Operacion registrada correctamente',
                'id_extracto_caja' => $idCajaInsert,
                'id_extracto_referencia' => $idRefInsert,
                'tabla_referencia' => $refType !== 'caja' ? $secondaryTable : '',
                'warning' => $warning,
            ]);
            break;

        // ===== LISTAR OPERACIONES =====
        case 'list':
            $fecha = $_GET['fecha'] ?? date('Y-m-d');
            $fecha_desde = $_GET['fecha_desde'] ?? $fecha;
            $fecha_hasta = $_GET['fecha_hasta'] ?? $fecha;
            $limit = max(1, (int)($_GET['limit'] ?? 50));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $estado = strtolower(trim((string)($_GET['estado'] ?? 'activos')));
            $q = trim((string)($_GET['q'] ?? ''));
            $medioCobro = strtoupper(trim((string)($_GET['medio_cobro'] ?? '')));
            $whereUsuario = $solo_mias ? " AND (e.id_login = :id_login OR CAST(e.login AS CHAR) = :login_usuario OR CAST(e.login AS CHAR) = :id_login_txt)" : "";
            $medioCobroSelect = $hasMedioCobro ? "e.medio_cobro" : "'' AS medio_cobro";
            $whereEstado = '';
            if ($estado === 'activos') {
                $whereEstado = " AND COALESCE(e.estado, 1) = 1";
            } elseif ($estado === 'anulados') {
                $whereEstado = " AND e.estado = 0";
            }
            $whereMedioCobro = '';
            if ($medioCobro !== '') {
                if ($medioCobro === 'EFECTIVO') {
                    $whereMedioCobro = $hasMedioCobro
                        ? " AND (UPPER(COALESCE(e.medio_cobro, '')) IN ('EFECTIVO', '1') OR (e.medio_cobro IS NULL AND e.concepto LIKE '%EFECTIVO%'))"
                        : " AND (e.concepto LIKE '%EFECTIVO%')";
                } elseif ($medioCobro === 'TARJETA') {
                    $whereMedioCobro = $hasMedioCobro
                        ? " AND (UPPER(COALESCE(e.medio_cobro, '')) = 'TARJETA' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TARJETA%'))"
                        : " AND (e.concepto LIKE '%TARJETA%')";
                } elseif ($medioCobro === 'TRANSFERENCIA' || $medioCobro === 'TRANSFER') {
                    $whereMedioCobro = $hasMedioCobro
                        ? " AND (UPPER(COALESCE(e.medio_cobro, '')) IN ('TRANSFERENCIA', 'TRANSFER') OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TRANSFER%'))"
                        : " AND (e.concepto LIKE '%TRANSFER%')";
                } elseif ($medioCobro === 'QR' || $medioCobro === 'PIX') {
                    $whereMedioCobro = $hasMedioCobro
                        ? " AND (UPPER(COALESCE(e.medio_cobro, '')) IN ('QR', 'PIX') OR (e.medio_cobro IS NULL AND (e.concepto LIKE '%QR%' OR e.concepto LIKE '%PIX%')))"
                        : " AND ((e.concepto LIKE '%QR%') OR (e.concepto LIKE '%PIX%'))";
                } elseif ($medioCobro === 'CREDITO') {
                    $whereMedioCobro = $hasMedioCobro
                        ? " AND (UPPER(COALESCE(e.medio_cobro, '')) = 'CREDITO' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%CREDITO%'))"
                        : " AND (e.concepto LIKE '%CREDITO%')";
                }
            }
            $whereSearch = $q !== ''
                ? " AND (
                        CAST(e.id AS CHAR) LIKE :q
                        OR e.concepto LIKE :q
                        OR IFNULL(e.comprobante, '') LIKE :q
                        OR IFNULL(o.operacion, '') LIKE :q
                        OR IFNULL(r.referencia, '') LIKE :q
                    )"
                : "";
            $baseFrom = " FROM $dbName.extracto_caja e
                    LEFT JOIN " . MASTER_DB . ".operaciones o ON e.operacion = o.id
                    LEFT JOIN " . MASTER_DB . ".referencia r ON e.referencia = r.id
                    LEFT JOIN $dbName.factura_ventas fv ON e.tabla_relacion = 'factura_ventas' AND e.id_relacion = fv.id_factura
                    WHERE e.codigo = :id_caja 
                      AND DATE(e.fecha) BETWEEN :fecha_desde AND :fecha_hasta
                      $whereEstado
                      $whereMedioCobro
                      $whereSearch
                      $whereUsuario";

            $sqlCount = "SELECT COUNT(*) AS total_rows " . $baseFrom;
            $stmtCount = $pdo->prepare($sqlCount);
            $stmtCount->bindValue(':id_caja', $id_caja, PDO::PARAM_INT);
            $stmtCount->bindValue(':fecha_desde', $fecha_desde, PDO::PARAM_STR);
            $stmtCount->bindValue(':fecha_hasta', $fecha_hasta, PDO::PARAM_STR);
            if ($solo_mias) {
                $stmtCount->bindValue(':id_login', $id_login, PDO::PARAM_INT);
                $stmtCount->bindValue(':login_usuario', $login_usuario, PDO::PARAM_STR);
                $stmtCount->bindValue(':id_login_txt', $id_login_txt, PDO::PARAM_STR);
            }
            if ($q !== '') {
                $stmtCount->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
            }
            $stmtCount->execute();
            $totalRows = (int)($stmtCount->fetchColumn() ?: 0);

            $sql = "SELECT 
                        e.id,
                        e.fecha,
                        e.concepto,
                        e.debito,
                        e.credito,
                        (e.credito - e.debito) as monto,
                        SUM(e.credito - e.debito) OVER (ORDER BY e.id ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS saldo_acumulado,
                        e.estado,
                        e.login as id_usuario,
                        e.operacion,
                        e.referencia,
                        e.comprobante,
                        $medioCobroSelect,
                        e.tabla_relacion,
                        e.id_relacion,
                        o.operacion as operacion_nombre,
                        r.referencia as referencia_nombre,
                        fv.nro_factura as nro_factura_rel"
                        . $baseFrom .
                    " ORDER BY e.id DESC
                    LIMIT :limit OFFSET :offset";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id_caja', $id_caja, PDO::PARAM_INT);
                $stmt->bindValue(':fecha_desde', $fecha_desde, PDO::PARAM_STR);
                $stmt->bindValue(':fecha_hasta', $fecha_hasta, PDO::PARAM_STR);
                if ($solo_mias) {
                    $stmt->bindValue(':id_login', $id_login, PDO::PARAM_INT);
                    $stmt->bindValue(':login_usuario', $login_usuario, PDO::PARAM_STR);
                    $stmt->bindValue(':id_login_txt', $id_login_txt, PDO::PARAM_STR);
                }
                if ($q !== '') {
                    $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $operaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                // Fallback para motores sin funciones de ventana
                $sqlFallback = "SELECT 
                        e.id,
                        e.fecha,
                        e.concepto,
                        e.debito,
                        e.credito,
                        (e.credito - e.debito) as monto,
                        0 as saldo_acumulado,
                        e.estado,
                        e.login as id_usuario,
                        e.operacion,
                        e.referencia,
                        e.comprobante,
                        $medioCobroSelect,
                        e.tabla_relacion,
                        e.id_relacion,
                        o.operacion as operacion_nombre,
                        r.referencia as referencia_nombre,
                        fv.nro_factura as nro_factura_rel"
                        . $baseFrom .
                    " ORDER BY e.id DESC
                    LIMIT :limit OFFSET :offset";
                $stmt = $pdo->prepare($sqlFallback);
                $stmt->bindValue(':id_caja', $id_caja, PDO::PARAM_INT);
                $stmt->bindValue(':fecha_desde', $fecha_desde, PDO::PARAM_STR);
                $stmt->bindValue(':fecha_hasta', $fecha_hasta, PDO::PARAM_STR);
                if ($solo_mias) {
                    $stmt->bindValue(':id_login', $id_login, PDO::PARAM_INT);
                    $stmt->bindValue(':login_usuario', $login_usuario, PDO::PARAM_STR);
                    $stmt->bindValue(':id_login_txt', $id_login_txt, PDO::PARAM_STR);
                }
                if ($q !== '') {
                    $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $operaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            foreach ($operaciones as &$op) {
                $op['tipo'] = (float)$op['credito'] > 0 ? 'entrada' : 'salida';

                // Trazabilidad visual: mostrar nro_factura en concepto/comprobante
                $nroFacturaRel = trim((string)($op['nro_factura_rel'] ?? ''));
                if ($nroFacturaRel !== '') {
                    if (trim((string)($op['comprobante'] ?? '')) === '') {
                        $op['comprobante'] = $nroFacturaRel;
                    }
                    $conceptoActual = trim((string)($op['concepto'] ?? ''));
                    if ($conceptoActual !== '' && stripos($conceptoActual, $nroFacturaRel) === false) {
                        $op['concepto'] = substr($conceptoActual . " | Fac #" . $nroFacturaRel, 0, 60);
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'operaciones' => $operaciones,
                'total' => count($operaciones),
                'totalRows' => $totalRows,
                'offset' => $offset,
                'limit' => $limit
            ]);
            break;

        // ===== OBTENER UNA OPERACIÓN =====
        case 'get':
            $id = (int)($_GET['id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM $dbName.extracto_caja WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $operacion = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'operacion' => $operacion
            ]);
            break;

        // ===== INSERTAR OPERACIÓN =====
        case 'insert':
            $input = json_decode(file_get_contents('php://input'), true);

            $tipo = $input['tipo'] ?? 'entrada'; // entrada o salida
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');

            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }
            if (empty($concepto)) {
                throw new Exception("Debe ingresar un concepto");
            }

            $debito = $tipo === 'salida' ? $monto : 0;
            $credito = $tipo === 'entrada' ? $monto : 0;

            // Prefijo según tipo
            if ($tipo === 'entrada' && stripos($concepto, 'Entrada') === false && stripos($concepto, 'Cobro') === false) {
                $concepto = "Entrada: " . $concepto;
            } elseif ($tipo === 'salida' && stripos($concepto, 'Salida') === false && stripos($concepto, 'Pago') === false) {
                $concepto = "Salida: " . $concepto;
            }

            // Append Payment Method Details
            $paymentMethod = $input['payment_method'] ?? 'efectivo';
            $paymentRef = trim($input['payment_ref'] ?? '');

            // Prepend payment method to ensure it's not truncated (field is limited to 60 chars)
            $paymentPrefix = strtoupper($paymentMethod);
            if (!empty($paymentRef)) {
                $paymentPrefix .= " Ref:" . substr($paymentRef, 0, 10);
            }
            $concepto = $paymentPrefix . " | " . $concepto;

            $stmt = $pdo->prepare("
                INSERT INTO $dbName.extracto_caja 
                (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante)
                VALUES (1, :id_caja, :concepto, :debito, :credito, NOW(), :login, :id_login, 1, 1, 1, :operacion, :referencia, :comprobante)
            ");

            // Map tipo and payment to operacion/referencia IDs from serproc1 tables
            // operacion: 1=Salida Caja, 2=Entrada Caja
            // referencia: 15=Pago Varios, 16=Cobro Varios, 41=Cobro Factura Venta, 39=Pago Factura Compra
            $operacionId = ($tipo === 'entrada') ? 2 : 1;

            // Determine referencia based on concept context
            $referenciaId = 16; // Default: Cobro Varios
            if ($tipo === 'salida') {
                $referenciaId = 15; // Pago Varios
            }
            if (stripos($concepto, 'Factura') !== false) {
                $referenciaId = ($tipo === 'entrada') ? 41 : 39; // Cobro/Pago Factura
            }
            if (stripos($concepto, 'APERTURA') !== false) {
                $referenciaId = 16; // Cobro Varios (Apertura)
            }

            $stmt->execute([
                ':id_caja' => $id_caja,
                ':concepto' => substr($concepto, 0, 60),
                ':debito' => $debito,
                ':credito' => $credito,
                ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                ':id_login' => $id_login,
                ':operacion' => $operacionId,
                ':referencia' => $referenciaId,
                ':comprobante' => substr($paymentRef, 0, 20)
            ]);

            $newId = $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Operación registrada correctamente',
                'id' => $newId
            ]);
            break;

        // ===== COBRO DE FACTURA (Registra en Caja + Cliente + Factura) =====
        case 'cobro_factura_caja':
            $input = json_decode(file_get_contents('php://input'), true);

            $idFactura = (int)($input['id_factura'] ?? 0);
            $monto = abs((float)($input['monto'] ?? 0));
            $paymentMethod = strtoupper(trim((string)($input['payment_method'] ?? 'EFECTIVO')));
            $paymentRef = trim((string)($input['payment_ref'] ?? ''));
            $tipoDocumento = strtoupper(trim((string)($input['tipo_documento'] ?? 'FACTURA')));
            $tipoCobro = strtoupper(trim((string)($input['tipo_cobro'] ?? 'TOTAL')));
            $cardFinancingType = strtolower(trim((string)($input['card_financing_type'] ?? 'credito')));
            $cardProcessor = strtolower(trim((string)($input['card_processor'] ?? 'bancard')));
            $cardInstallments = max(1, (int)($input['card_installments'] ?? 1));
            $creditInstallments = max(1, (int)($input['credit_installments'] ?? 1));
            $creditDueDate = trim((string)($input['credit_due_date'] ?? ''));
            $creditNotes = trim((string)($input['credit_notes'] ?? ''));
            $cashReceived = abs((float)($input['cash_received'] ?? 0));
            $cashChange = abs((float)($input['cash_change'] ?? 0));

            if ($idFactura <= 0) throw new Exception("Debe seleccionar una factura");
            if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0");

            $metodosValidos = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR', 'PIX'];
            if (!in_array($paymentMethod, $metodosValidos, true)) {
                if ($paymentMethod === 'CREDITO') {
                    throw new Exception("Crédito está deprecado en el módulo Caja");
                }
                $paymentMethod = 'EFECTIVO';
            }
            if (!in_array($tipoDocumento, ['FACTURA', 'NOTA'], true)) $tipoDocumento = 'FACTURA';
            if (!in_array($tipoCobro, ['TOTAL'], true)) $tipoCobro = 'TOTAL';
            if ($paymentMethod === 'EFECTIVO') {
                if ($cashReceived <= 0) throw new Exception("Efectivo: debe ingresar entrega");
                if ($cashReceived < $monto) throw new Exception("Efectivo: la entrega no puede ser menor al total");
                $cashChange = max($cashReceived - $monto, 0);
            } elseif ($paymentMethod === 'TARJETA') {
                if ($paymentRef === '') throw new Exception("Tarjeta: debe ingresar voucher/NSU");
                if ($cardInstallments < 1) throw new Exception("Tarjeta: cuotas inválidas");
                if ($cardProcessor === '') throw new Exception("Tarjeta: procesador requerido");
            } elseif ($paymentMethod === 'TRANSFERENCIA') {
                if ($paymentRef === '') throw new Exception("Transferencia: debe ingresar referencia");
            } elseif ($paymentMethod === 'QR' || $paymentMethod === 'PIX') {
                if ($paymentRef === '') throw new Exception("PIX/QR: debe ingresar referencia TXID");
            }

            $stmtFact = $pdo->prepare("
                SELECT f.id_factura, f.nro_factura, f.total, f.id_cliente, c.nombre as cliente
                FROM $dbName.factura_ventas f
                LEFT JOIN $dbName.clientes c ON f.id_cliente = c.id
                WHERE f.id_factura = :id
            ");
            $stmtFact->execute([':id' => $idFactura]);
            $factura = $stmtFact->fetch(PDO::FETCH_ASSOC);
            if (!$factura) throw new Exception("Factura no encontrada");

            $targetTable = 'extracto_caja';
            $codigoRef = $id_caja;
            if ($paymentMethod === 'TARJETA' || $paymentMethod === 'TRANSFERENCIA' || $paymentMethod === 'QR' || $paymentMethod === 'PIX') {
                $targetTable = 'extracto_banco';
                $codigoRef = $id_caja;
            }

            if (!tableExists($pdo, $dbName, $targetTable)) {
                throw new Exception("No existe la tabla $targetTable en esta empresa");
            }

            $conceptoBase = ($tipoDocumento === 'NOTA') ? 'Cobro Nota' : 'Cobro Fact.';
            $concepto = $paymentMethod . " | " . $conceptoBase . " " . $factura['nro_factura'] . " | " . $tipoCobro;
            if ($paymentMethod === 'EFECTIVO') {
                $concepto .= " | REC " . number_format($cashReceived, 0, '.', '') . " VUE " . number_format($cashChange, 0, '.', '');
            } elseif ($paymentMethod === 'TARJETA') {
                $concepto .= " | " . strtoupper($cardFinancingType) . " {$cardInstallments}x " . strtoupper($cardProcessor);
            } elseif ($paymentMethod === 'TRANSFERENCIA') {
                $concepto .= " | TRANSFER";
            } elseif ($paymentMethod === 'QR' || $paymentMethod === 'PIX') {
                $concepto .= " | PIX";
            }

            $row = [
                'sucursal' => 1,
                'codigo' => $codigoRef,
                'concepto' => substr($concepto, 0, 60),
                'debito' => 0,
                'credito' => $monto,
                'fecha' => date('Y-m-d H:i:s'),
                'login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                'id_login' => $id_login,
                'estado' => 1,
                'moneda' => 1,
                'cambio' => 1,
                'operacion' => 2,
                'referencia' => 41,
                'comprobante' => substr($paymentRef, 0, 20),
                'medio_cobro' => $paymentMethod,
                'tabla_relacion' => 'factura_ventas',
                'id_relacion' => $idFactura,
                'id_factura' => $idFactura,
                'beneficiario' => '',
                'entrega' => $paymentMethod === 'EFECTIVO' ? $cashReceived : null,
                'vuelto' => $paymentMethod === 'EFECTIVO' ? $cashChange : null,
                'monto_entregado' => $paymentMethod === 'EFECTIVO' ? $cashReceived : null,
                'monto_vuelto' => $paymentMethod === 'EFECTIVO' ? $cashChange : null
            ];

            $pdo->beginTransaction();
            try {
                $insertId = smartInsert($pdo, $dbName, $targetTable, $row);

                try {
                    $checkSaldo = $pdo->query("SHOW COLUMNS FROM $dbName.factura_ventas LIKE 'saldo'");
                    if ($checkSaldo->rowCount() == 0) {
                        $pdo->exec("ALTER TABLE $dbName.factura_ventas ADD COLUMN saldo DECIMAL(15,2) NULL AFTER total");
                        $pdo->exec("UPDATE $dbName.factura_ventas SET saldo = total WHERE saldo IS NULL");
                    }
                    $checkMedio = $pdo->query("SHOW COLUMNS FROM $dbName.factura_ventas LIKE 'medio_cobro'");
                    if ($checkMedio->rowCount() == 0) {
                        $pdo->exec("ALTER TABLE $dbName.factura_ventas ADD COLUMN medio_cobro VARCHAR(20) NULL");
                    }
                    $stmtUpd = $pdo->prepare("UPDATE $dbName.factura_ventas SET saldo = GREATEST(IFNULL(saldo, total) - :m1, 0), pendiente = GREATEST(IFNULL(pendiente, 0) - :m2, 0) WHERE id_factura = :id");
                    $stmtUpd->execute([':m1' => $monto, ':m2' => $monto, ':id' => $idFactura]);
                    $stmtMedio = $pdo->prepare("UPDATE $dbName.factura_ventas SET medio_cobro = :medio WHERE id_factura = :id");
                    $stmtMedio->execute([':medio' => $paymentMethod, ':id' => $idFactura]);
                } catch (Exception $e) {
                }

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Cobro registrado correctamente',
                    'tabla' => $targetTable,
                    'id' => $insertId,
                    'factura' => $factura['nro_factura'],
                    'monto' => $monto,
                    'cash_received' => $paymentMethod === 'EFECTIVO' ? $cashReceived : null,
                    'cash_change' => $paymentMethod === 'EFECTIVO' ? $cashChange : null
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            break;

        case 'cobro_factura':
            $input = json_decode(file_get_contents('php://input'), true);

            $idFactura = (int)($input['id_factura'] ?? 0);
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');
            $paymentMethod = strtoupper(trim($input['payment_method'] ?? 'EFECTIVO'));
            $paymentRef = trim($input['payment_ref'] ?? '');
            $tipoDocumento = strtoupper(trim((string)($input['tipo_documento'] ?? 'FACTURA')));
            $tipoCobro = strtoupper(trim((string)($input['tipo_cobro'] ?? 'TOTAL')));

            if ($tipoDocumento !== 'FACTURA' && $tipoDocumento !== 'NOTA') {
                $tipoDocumento = 'FACTURA';
            }
            if ($tipoCobro !== 'TOTAL' && $tipoCobro !== 'PARCIAL') {
                $tipoCobro = 'TOTAL';
            }

            if ($idFactura <= 0) {
                throw new Exception("Debe seleccionar una factura");
            }
            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }

            // Obtener datos de la factura
            $stmtFact = $pdo->prepare("
                SELECT f.id_factura, f.nro_factura, f.total, f.id_cliente, c.nombre as cliente
                FROM $dbName.factura_ventas f
                LEFT JOIN $dbName.clientes c ON f.id_cliente = c.id
                WHERE f.id_factura = :id
            ");
            $stmtFact->execute([':id' => $idFactura]);
            $factura = $stmtFact->fetch(PDO::FETCH_ASSOC);

            if (!$factura) {
                throw new Exception("Factura no encontrada");
            }

            // Iniciar transacción
            $pdo->beginTransaction();

            try {
                // Si es una EDICIÓN (viene con ID), primero ANULAMOS el registro anterior
                // para revertir saldos de factura, extractos, etc.
                $editingId = (int)($input['id'] ?? 0);
                if ($editingId > 0) {
                    assertOperacionPropia($pdo, $dbName, $editingId, $id_login, $login_usuario, $id_login_txt);
                    // 1. Obtener la op anterior
                    $stmtOld = $pdo->prepare("SELECT sucursal, id, credito, debito, referencia, tabla_relacion, id_relacion, concepto, comprobante, estado FROM $dbName.extracto_caja WHERE id = :id");
                    $stmtOld->execute([':id' => $editingId]);
                    $oldOp = $stmtOld->fetch(PDO::FETCH_ASSOC);

                    if ($oldOp && $oldOp['estado'] != 0) {
                        $oldRelTable = $oldOp['tabla_relacion'];
                        $oldRelId = (int)$oldOp['id_relacion'];
                        $oldRef = (int)$oldOp['referencia'];
                        $oldMonto = (float)($oldOp['credito'] > 0 ? $oldOp['credito'] : $oldOp['debito']);

                        // Reversa Cobro Factura Venta
                        if ($oldRef == 41 || $oldRelTable == 'factura_ventas') {
                            restoreFacturaPendiente($pdo, $dbName, $oldRelId, $oldMonto);
                            $stmtCli = $pdo->prepare("UPDATE $dbName.extracto_cliente SET estado = 0 WHERE id_factura = :id_fact AND credito = :monto AND estado = 1 LIMIT 1");
                            $stmtCli->execute([':id_fact' => $oldRelId, ':monto' => $oldMonto]);
                        }

                        // Marcar registro anterior como anulado
                        $pdo->exec("UPDATE $dbName.extracto_caja SET estado = 0 WHERE id = $editingId");
                    }
                }

                if (strtoupper($paymentMethod) === 'CREDITO') {
                    // --- LÓGICA COBRO CRÉDITO (DOCUMENTOS) ---

                    $installments = (int)($input['credit_installments'] ?? 1);
                    if ($installments < 1) $installments = 1;
                    $dueDate = $input['credit_due_date'] ?? date('Y-m-d', strtotime('+30 days'));

                    $interestPct = (float)($input['credit_interest_pct'] ?? 0);
                    $moraPct = (float)($input['credit_mora_pct'] ?? 0);
                    $graceDays = (int)($input['credit_grace_days'] ?? 0);

                    // Monto Base (Capital puro)
                    $baseAmount = $monto;

                    // Interés Total Financiero
                    $totalInterest = $baseAmount * ($interestPct / 100);

                    // Total Final a pagar (Capital + Interés)
                    $totalFinanced = $baseAmount + $totalInterest;

                    // Cálculos unitarios base (floor para evitar decimales infinitos)
                    $c_total_base = floor($totalFinanced / $installments);
                    $c_capital_base = floor($baseAmount / $installments);
                    $c_interest_base = floor($totalInterest / $installments); // Se registrará en int_capital

                    // Calcular remanentes para ajustar en la última cuota
                    $remainderTotal = $totalFinanced - ($c_total_base * $installments);
                    $remainderCapital = $baseAmount - ($c_capital_base * $installments);
                    $remainderInterest = $totalInterest - ($c_interest_base * $installments);

                    $idCaja = 0; // No se mueve caja

                    for ($i = 1; $i <= $installments; $i++) {
                        $thisTotal = $c_total_base;
                        $thisCapital = $c_capital_base;
                        $thisInterest = $c_interest_base;

                        if ($i == $installments) {
                            $thisTotal += $remainderTotal;
                            $thisCapital += $remainderCapital;
                            $thisInterest += $remainderInterest;
                        }

                        // Calcular vencimiento
                        $daysToAdd = ($i - 1) * 30;
                        $fechaVencCuota = date('Y-m-d', strtotime($dueDate . " + $daysToAdd days"));

                        $qtyStr = "$i/$installments";

                        $stmtDoc = $pdo->prepare("
                        INSERT INTO $dbName.documentos 
                        (id_sucursal, id_cliente, id_factura, fecha_creacion, fecha_vencimiento, 
                         capital, cantidad_cuota, periodo_cuota, int_capital, int_moratorio, total, 
                         concepto, id_login, pagado, pendiente, estado, inforcomf, dias_gracia, porc_mora)
                        VALUES 
                        (1, :id_cliente, :id_factura, NOW(), :fecha_vencimiento,
                         :capital, :cantidad_cuota, 30, :int_capital, 0, :total,
                         :concepto, :id_login, 0, :pendiente, 1, 0, :dias_gracia, :porc_mora)
                    ");

                        $stmtDoc->execute([
                            ':id_cliente' => $factura['id_cliente'],
                            ':id_factura' => $idFactura,
                            ':fecha_vencimiento' => $fechaVencCuota,
                            ':capital' => $thisCapital,
                            ':cantidad_cuota' => $qtyStr,
                            ':int_capital' => $thisInterest,
                            ':total' => $thisTotal,
                            ':concepto' => "Cuota $qtyStr - Fac. " . $factura['nro_factura'],
                            ':id_login' => $id_login,
                            ':pendiente' => $thisTotal,
                            ':dias_gracia' => $graceDays,
                            ':porc_mora' => $moraPct
                        ]);
                    }


                    $message = "Plan de pagos ($installments cuotas) generado correctamente. Factura documentada.";

                    // Opcional: Podríamos actualizar el estado de la factura para indicar que está documentada,
                    // pero por ahora mantenemos el saldo para reflejar la deuda.

                } else {
                    // --- LÓGICA COBRO CONTADO/DIRECTO ---

                    // 1. REGISTRAR EN EXTRACTO_CAJA (Entrada de dinero)
                    $conceptoBase = ($tipoDocumento === 'NOTA') ? 'Cobro Nota' : 'Cobro Fact.';
                    $conceptoCaja = $paymentMethod . " | " . $conceptoBase . " " . $factura['nro_factura'] . " | " . $tipoCobro;

                    $stmtCaja = $pdo->prepare("
                    INSERT INTO $dbName.extracto_caja 
                    (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante, medio_cobro, tabla_relacion, id_relacion)
                    VALUES (1, :id_caja, :concepto, 0, :credito, NOW(), :login, :id_login, 1, 1, 1, 2, 41, :comprobante, :medio_cobro, 'factura_ventas', :id_factura)
                ");
                    $stmtCaja->execute([
                        ':id_caja' => $id_caja,
                        ':concepto' => substr($conceptoCaja, 0, 60),
                        ':credito' => $monto,
                        ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                        ':id_login' => $id_login,
                        ':comprobante' => substr($paymentRef, 0, 20),
                        ':medio_cobro' => $paymentMethod,
                        ':id_factura' => $idFactura
                    ]);
                    $idCaja = $pdo->lastInsertId();

                    // 2. REGISTRAR EN EXTRACTO_CLIENTE (Pago del cliente)
                    $conceptoCliente = "Pago Fact. " . $factura['nro_factura'] . " - " . $paymentMethod;

                    try {
                        $stmtCliente = $pdo->prepare("
                        INSERT INTO $dbName.extracto_cliente 
                        (codigo, concepto, debito, credito, fecha, login, estado, id_factura)
                        VALUES (:id_cliente, :concepto, 0, :credito, NOW(), :id_login, 1, :id_factura)
                    ");
                        $stmtCliente->execute([
                            ':id_cliente' => $factura['id_cliente'],
                            ':concepto' => substr($conceptoCliente, 0, 60),
                            ':credito' => $monto,
                            ':id_login' => $id_login,
                            ':id_factura' => $idFactura
                        ]);
                    } catch (Exception $e) {
                    }

                    // 3. ACTUALIZAR SALDO/PENDIENTE EN FACTURA
                    try {
                        $checkCol = $pdo->query("SHOW COLUMNS FROM $dbName.factura_ventas LIKE 'saldo'");
                        if ($checkCol->rowCount() == 0) {
                            $pdo->exec("ALTER TABLE $dbName.factura_ventas ADD COLUMN saldo DECIMAL(15,2) NULL AFTER total");
                            $pdo->exec("UPDATE $dbName.factura_ventas SET saldo = total WHERE saldo IS NULL");
                        }
                        $checkMedio = $pdo->query("SHOW COLUMNS FROM $dbName.factura_ventas LIKE 'medio_cobro'");
                        if ($checkMedio->rowCount() == 0) {
                            $pdo->exec("ALTER TABLE $dbName.factura_ventas ADD COLUMN medio_cobro VARCHAR(20) NULL");
                        }
                        $stmtUpd = $pdo->prepare("UPDATE $dbName.factura_ventas SET saldo = GREATEST(IFNULL(saldo, total) - :m1, 0), pendiente = GREATEST(IFNULL(pendiente, 0) - :m2, 0) WHERE id_factura = :id");
                        $stmtUpd->execute([':m1' => $monto, ':m2' => $monto, ':id' => $idFactura]);
                        $stmtMedio = $pdo->prepare("UPDATE $dbName.factura_ventas SET medio_cobro = :medio WHERE id_factura = :id");
                        $stmtMedio->execute([':medio' => $paymentMethod, ':id' => $idFactura]);
                    } catch (Exception $e) {
                    }

                    $message = 'Cobro registrado correctamente en Caja y Cuenta Cliente';
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Cobro registrado correctamente en Caja y Cuenta Cliente',
                    'id_caja' => $idCaja,
                    'factura' => $factura['nro_factura'],
                    'monto' => $monto,
                    'tipo_documento' => $tipoDocumento,
                    'tipo_cobro' => $tipoCobro
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ===== ACTUALIZAR OPERACIÓN =====
        case 'update':
            $input = json_decode(file_get_contents('php://input'), true);

            $id = (int)($input['id'] ?? 0);
            $tipo = $input['tipo'] ?? 'entrada';
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = trim($input['concepto'] ?? '');
            $operacionId = !empty($input['operacion']) ? (int)$input['operacion'] : null;
            $referenciaId = !empty($input['referencia']) ? (int)$input['referencia'] : null;
            $medioCobro = trim($input['medio_cobro'] ?? 'EFECTIVO');

            if ($id <= 0) {
                throw new Exception("ID de operación inválido");
            }
            if ($monto <= 0) {
                throw new Exception("El monto debe ser mayor a 0");
            }
            assertOperacionPropia($pdo, $dbName, $id, $id_login, $login_usuario, $id_login_txt);

            // Obtener estado actual para detectar Apertura de Caja
            $stmtCurrent = $pdo->prepare("SELECT referencia, concepto FROM $dbName.extracto_caja WHERE id = :id LIMIT 1");
            $stmtCurrent->execute([':id' => $id]);
            $currentRow = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
            if (!$currentRow) {
                throw new Exception("Operación no encontrada");
            }

            $refActual = (int)($currentRow['referencia'] ?? 0);
            $conceptoActual = strtoupper(trim((string)($currentRow['concepto'] ?? '')));
            $isApertura = ($refActual === 9003 || $refActual === 16 || strpos($conceptoActual, 'APERTURA DE CAJA') !== false || strpos($conceptoActual, 'APERTURA DE CJA') !== false);

            // Regla solicitada: en update de Apertura guardar valores fijos
            if ($isApertura) {
                $tipo = 'entrada';
                $operacionId = 2;
                $referenciaId = 9003;
                $medioCobro = '1'; // 1 = Efectivo
            }

            $debito = $tipo === 'salida' ? $monto : 0;
            $credito = $tipo === 'entrada' ? $monto : 0;

            $sqlUpdate = "
                UPDATE $dbName.extracto_caja 
                SET concepto = :concepto, debito = :debito, credito = :credito, 
                    operacion = :operacion, referencia = :referencia";
            if ($hasMedioCobro) {
                $sqlUpdate .= ", medio_cobro = :medio_cobro";
            }
            $sqlUpdate .= " WHERE id = :id";

            $paramsUpdate = [
                ':id' => $id,
                ':concepto' => substr($concepto, 0, 60),
                ':debito' => $debito,
                ':credito' => $credito,
                ':operacion' => $operacionId,
                ':referencia' => $referenciaId
            ];
            if ($hasMedioCobro) {
                $paramsUpdate[':medio_cobro'] = $medioCobro;
            }

            $stmt = $pdo->prepare($sqlUpdate);
            $stmt->execute($paramsUpdate);

            echo json_encode([
                'success' => true,
                'message' => 'Operación actualizada correctamente'
            ]);
            break;

        // ===== ANULAR OPERACIÓN =====
        case 'void':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = $_POST ?: [];
            }
            $id = (int)($input['id'] ?? 0);
            $motivo = trim((string)($input['motivo'] ?? ''));

            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID de operación inválido']);
                break;
            }

            // No-admin: no anula directo, genera solicitud de autorización.
            if (!$is_admin) {
                try {
                    ensureCajaVoidApprovalTable($pdo, $dbName);
                    $pending = findPendingVoidApproval($pdo, $dbName, $id);
                    if ($pending) {
                        try {
                            $stmtAdminsPush = $pdo_master->prepare("
                                SELECT id_login, priv_admin, role
                                FROM " . MASTER_DB . ".sec_users
                                WHERE id_empresa = :id_empresa
                                  AND active = 'Y'
                            ");
                            $stmtAdminsPush->execute([':id_empresa' => $id_empresa]);
                            $pushDestinations = [];
                            foreach (($stmtAdminsPush->fetchAll(PDO::FETCH_ASSOC) ?: []) as $adminRow) {
                                $idDest = (int)($adminRow['id_login'] ?? 0);
                                if ($idDest <= 0) {
                                    continue;
                                }
                                $privAdmin = strtoupper(trim((string)($adminRow['priv_admin'] ?? 'N')));
                                $role = strtoupper(trim((string)($adminRow['role'] ?? '')));
                                $hasAdminPriv = in_array($privAdmin, ['Y', 'S', '1', 'TRUE'], true);
                                $hasAdminRole = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
                                    || strpos($role, 'ADMIN') !== false
                                    || strpos($role, 'SUPERVIS') !== false;
                                if ($hasAdminPriv || $hasAdminRole) {
                                    $pushDestinations[] = $idDest;
                                }
                            }
                            if (!empty($pushDestinations) && (smxPosPushEnabled() || smxMobileFcmEnabled())) {
                                smxPosNotifyUserLoginsPush($pdo_master, MASTER_DB, $pushDestinations, [
                                    'title' => 'Anulación de caja pendiente',
                                    'body' => sprintf(
                                        '%s reenvió una solicitud para autorizar una anulación de caja.',
                                        $login_usuario !== '' ? $login_usuario : ('Usuario #' . $id_login)
                                    ),
                                    'url' => '/public/pos/autorizaciones.php',
                                    'tag' => 'pos-auth-void-' . $id_empresa . '-' . (int)$id,
                                    'channel' => 'authorization_request',
                                    'peer_login' => $id_login,
                                    'icon' => '/public/assets/logo-192.png',
                                    'badge' => '/public/assets/logo-192.png',
                                    '_id_empresa' => $id_empresa,
                                    '_id_remitente_login' => $id_login,
                                    '_web_push_extra_login_ids' => [$id_login],
                                    '_fcm_extra_login_ids' => [$id_login],
                                ]);
                            }
                        } catch (Throwable $e) {
                        }
                        echo json_encode([
                            'success' => true,
                            'pending_approval' => true,
                            'approval_id' => (int)$pending['id'],
                            'message' => 'La solicitud pendiente fue reenviada al administrador.'
                        ]);
                        break;
                    }

                    $stmtReq = $pdo->prepare("
                        INSERT INTO $dbName.caja_void_approvals
                        (tipo, id_operacion, id_caja, id_empresa, estado, solicitado_por_id_login, solicitado_por_login, motivo, payload_json, fecha_solicitud)
                        VALUES ('VOID_OPERACION', :id_op, :id_caja, :id_empresa, 'PENDIENTE', :id_login, :login, :motivo, :payload, NOW())
                    ");
                    $stmtReq->execute([
                        ':id_op' => $id,
                        ':id_caja' => $id_caja,
                        ':id_empresa' => $id_empresa,
                        ':id_login' => $id_login,
                        ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                        ':motivo' => substr($motivo, 0, 255),
                        ':payload' => json_encode(['id_operacion' => $id, 'id_caja' => $id_caja], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);

                    try {
                        $stmtAdminsPush = $pdo_master->prepare("
                            SELECT id_login, priv_admin, role
                            FROM " . MASTER_DB . ".sec_users
                            WHERE id_empresa = :id_empresa
                              AND active = 'Y'
                        ");
                        $stmtAdminsPush->execute([':id_empresa' => $id_empresa]);
                        $pushDestinations = [];
                        foreach (($stmtAdminsPush->fetchAll(PDO::FETCH_ASSOC) ?: []) as $adminRow) {
                            $idDest = (int)($adminRow['id_login'] ?? 0);
                            if ($idDest <= 0) {
                                continue;
                            }
                            $privAdmin = strtoupper(trim((string)($adminRow['priv_admin'] ?? 'N')));
                            $role = strtoupper(trim((string)($adminRow['role'] ?? '')));
                            $hasAdminPriv = in_array($privAdmin, ['Y', 'S', '1', 'TRUE'], true);
                            $hasAdminRole = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
                                || strpos($role, 'ADMIN') !== false
                                || strpos($role, 'SUPERVIS') !== false;
                            if ($hasAdminPriv || $hasAdminRole) {
                                $pushDestinations[] = $idDest;
                            }
                        }
                        if (!empty($pushDestinations) && (smxPosPushEnabled() || smxMobileFcmEnabled())) {
                            smxPosNotifyUserLoginsPush($pdo_master, MASTER_DB, $pushDestinations, [
                                'title' => 'Anulación de caja pendiente',
                                'body' => sprintf(
                                    '%s solicita autorizar una anulación de caja.',
                                    $login_usuario !== '' ? $login_usuario : ('Usuario #' . $id_login)
                                ),
                                'url' => '/public/pos/autorizaciones.php',
                                'tag' => 'pos-auth-void-' . $id_empresa . '-' . (int)$id,
                                'channel' => 'authorization_request',
                                'peer_login' => $id_login,
                                'icon' => '/public/assets/logo-192.png',
                                'badge' => '/public/assets/logo-192.png',
                                '_id_empresa' => $id_empresa,
                                '_id_remitente_login' => $id_login,
                                '_web_push_extra_login_ids' => [$id_login],
                                '_fcm_extra_login_ids' => [$id_login],
                            ]);
                        }
                    } catch (Throwable $e) {
                        // Nunca romper la solicitud por falla de push.
                    }

                    echo json_encode([
                        'success' => true,
                        'pending_approval' => true,
                        'approval_id' => (int)$pdo->lastInsertId(),
                        'message' => 'Solicitud enviada. Debe ser aprobada por un usuario administrador.'
                    ]);
                    break;
                } catch (Throwable $e) {
                    echo json_encode([
                        'success' => false,
                        'pending_approval' => false,
                        'message' => 'No se pudo registrar la solicitud de autorización',
                        'error' => $e->getMessage()
                    ]);
                    break;
                }
            }

            $pdo->beginTransaction();
            try {
                // 1. Obtener datos de la operación antes de anular
                $stmtGet = $pdo->prepare("SELECT * FROM $dbName.extracto_caja WHERE id = :id");
                $stmtGet->execute([':id' => $id]);
                $op = $stmtGet->fetch(PDO::FETCH_ASSOC);

                if (!$op) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Operación no encontrada']);
                    break;
                }
                if ($op['estado'] == 0) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'La operación ya está anulada']);
                    break;
                }

                $relTable = $op['tabla_relacion'];
                $relId = (int)$op['id_relacion'];
                $ref = (int)$op['referencia'];
                $monto = (float)($op['credito'] > 0 ? $op['credito'] : $op['debito']);

                // 2. Lógica de Reversa según referencia
                reverseAccounting($pdo, $dbName, $id, $op, $relTable, $relId, $monto, $ref);
                $stmt = $pdo->prepare("UPDATE $dbName.extracto_caja SET estado = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);

                // Si había solicitud pendiente, marcarla como aprobada/ejecutada por admin.
                try {
                    $stmtApprove = $pdo->prepare("
                        UPDATE $dbName.caja_void_approvals
                        SET estado = 'EJECUTADO',
                            aprobado_por_id_login = :id_login,
                            aprobado_por_login = :login,
                            fecha_resolucion = NOW()
                        WHERE id_operacion = :id_op
                          AND tipo = 'VOID_OPERACION'
                          AND estado IN ('PENDIENTE', 'APROBADO')
                    ");
                    $stmtApprove->execute([
                        ':id_login' => $id_login,
                        ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                        ':id_op' => $id
                    ]);
                } catch (Throwable $e) {
                    // no bloquear anulación si falla actualización de solicitud
                }

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Operación anulada por usuario administrador',
                    'admin_check' => [
                        'id_login' => $id_login,
                        'role' => $admin_role_resolved,
                        'priv_admin' => $admin_priv_resolved
                    ]
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'No se pudo anular la operación',
                    'error' => $e->getMessage()
                ]);
            }
            break;

        // ===== SUPRIMIR/ELIMINAR OPERACIÓN =====
        case 'delete':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception("ID de operación inválido");
            }
            assertOperacionPropia($pdo, $dbName, $id, $id_login, $login_usuario, $id_login_txt);

            $stmt = $pdo->prepare("DELETE FROM $dbName.extracto_caja WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Operación suprimida permanentemente'
            ]);
            break;

        // ===== APERTURA DE CAJA =====
        case 'open':
            $input = json_decode(file_get_contents('php://input'), true);
            $monto_inicial = abs((float)($input['monto'] ?? 0));
            $id_caja_open = (int)($input['id_caja'] ?? $id_caja);

            if ($id_caja_open <= 0) {
                echo json_encode([
                    'success' => false,
                    'code' => 'INVALID_CAJA',
                    'message' => 'Caja invalida para apertura'
                ]);
                break;
            }

            if (cajaAbiertaHoy($pdo, $dbName, $id_caja_open)) {
                echo json_encode([
                    'success' => false,
                    'code' => 'CAJA_ALREADY_OPEN',
                    'message' => 'La caja ya fue abierta. Debe realizar cierre antes de una nueva apertura.'
                ]);
                break;
            }

            // Regla fija para Apertura de Caja
            // Debe quedar en referencia 16 para que la UI y el cierre detecten caja abierta.
            $operacionId = 2;
            $referenciaId = 16;

            if ($hasMedioCobro) {
                $stmt = $pdo->prepare("
                    INSERT INTO $dbName.extracto_caja 
                    (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, medio_cobro)
                    VALUES (1, :id_caja, 'APERTURA DE CAJA', 0, :monto, NOW(), :login, :id_login, 1, 1, 1, :operacion, :referencia, '1')
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO $dbName.extracto_caja 
                    (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia)
                    VALUES (1, :id_caja, 'APERTURA DE CAJA', 0, :monto, NOW(), :login, :id_login, 1, 1, 1, :operacion, :referencia)
                ");
            }
            $stmt->execute([
                ':id_caja' => $id_caja_open,
                ':monto' => $monto_inicial,
                ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                ':id_login' => $id_login,
                ':operacion' => $operacionId,
                ':referencia' => $referenciaId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Caja abierta correctamente'
            ]);
            break;

        // ===== CIERRE DE CAJA =====
        case 'close':
            $input = json_decode(file_get_contents('php://input'), true);
            $efectivo_contado = (float)($input['efectivo_contado'] ?? 0);
            $monto_entregado_supervisor = (float)($input['monto_entregado_supervisor'] ?? 0);
            $supervisor_nombre = trim((string)($input['supervisor_nombre'] ?? ''));
            $observacion = trim($input['observacion'] ?? '');
            $valores = $input['valores'] ?? [];

            if ($efectivo_contado < 0) {
                throw new Exception('El efectivo contado no puede ser negativo');
            }
            if ($supervisor_nombre === '') {
                throw new Exception('Debe indicar el supervisor que recibe el cierre');
            }
            if (!cajaAbiertaHoy($pdo, $dbName, $id_caja)) {
                throw new Exception('No hay una caja abierta para cerrar');
            }

            // Asegurar tablas de cierre
            $pdo->exec("CREATE TABLE IF NOT EXISTS $dbName.caja_cierres (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_caja INT NOT NULL,
                id_empresa INT NOT NULL,
                id_login INT NOT NULL,
                fecha_cierre DATETIME NOT NULL,
                fecha_operativa DATE NOT NULL,
                saldo_sistema DECIMAL(15,2) NOT NULL DEFAULT 0,
                efectivo_contado DECIMAL(15,2) NOT NULL DEFAULT 0,
                diferencia DECIMAL(15,2) NOT NULL DEFAULT 0,
                tipo_diferencia VARCHAR(20) NOT NULL DEFAULT 'CUADRE',
                monto_ajuste DECIMAL(15,2) NOT NULL DEFAULT 0,
                monto_entregado_supervisor DECIMAL(15,2) NOT NULL DEFAULT 0,
                supervisor_nombre VARCHAR(120) NOT NULL,
                observacion VARCHAR(255) NULL,
                resumen_json TEXT NULL,
                token_verificacion VARCHAR(64) NOT NULL,
                id_extracto_ajuste INT NULL,
                id_extracto_cierre INT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_caja_fecha (id_caja, fecha_operativa),
                INDEX idx_token (token_verificacion)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS $dbName.caja_cierres_valores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_cierre INT NOT NULL,
                denominacion DECIMAL(15,2) NOT NULL DEFAULT 0,
                cantidad INT NOT NULL DEFAULT 0,
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
                orden_item INT NOT NULL DEFAULT 0,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cierre (id_cierre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Re-calcular saldo actual para seguridad
            $stmtSaldo = $pdo->prepare("
                SELECT SUM(credito - debito) as saldo 
                FROM $dbName.extracto_caja 
                WHERE codigo = :id_caja AND DATE(fecha) = CURDATE() AND estado = 1
            ");
            $stmtSaldo->execute([':id_caja' => $id_caja]);
            $saldo_sistema = (float)$stmtSaldo->fetchColumn();

            $diferencia = $efectivo_contado - $saldo_sistema;
            $cuadra = abs($diferencia) < 0.5;
            $tipo_diferencia = 'CUADRE';
            if ($diferencia > 0.5) $tipo_diferencia = 'SOBRANTE';
            if ($diferencia < -0.5) $tipo_diferencia = 'FALTANTE';

            // Resumen financiero del día
            $stmtResumen = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN e.medio_cobro = 'EFECTIVO' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%EFECTIVO%') THEN e.credito - e.debito ELSE 0 END), 0) as efectivo,
                    COALESCE(SUM(CASE WHEN e.medio_cobro = 'TARJETA' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TARJETA%') THEN e.credito - e.debito ELSE 0 END), 0) as tarjeta,
                    COALESCE(SUM(CASE WHEN e.medio_cobro = 'TRANSFERENCIA' OR e.medio_cobro = 'TRANSFER' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TRANSFER%') THEN e.credito - e.debito ELSE 0 END), 0) as transferencia,
                    COALESCE(SUM(CASE WHEN e.medio_cobro IN ('QR', 'PIX') OR (e.medio_cobro IS NULL AND (e.concepto LIKE '%QR%' OR e.concepto LIKE '%PIX%')) THEN e.credito - e.debito ELSE 0 END), 0) as qr_pix,
                    COALESCE(SUM(e.credito), 0) as total_entradas,
                    COALESCE(SUM(e.debito), 0) as total_salidas,
                    COALESCE(SUM(e.credito - e.debito), 0) as saldo
                FROM $dbName.extracto_caja e
                WHERE e.codigo = :id_caja 
                  AND DATE(e.fecha) = CURDATE()
                  AND e.estado = 1
            ");
            $stmtResumen->execute([':id_caja' => $id_caja]);
            $resumenDia = $stmtResumen->fetch(PDO::FETCH_ASSOC) ?: [];

            $saldo_ajustado = $saldo_sistema + $diferencia; // Debe igualar al contado
            if ($monto_entregado_supervisor <= 0) {
                $monto_entregado_supervisor = $saldo_ajustado;
            }
            if ($monto_entregado_supervisor > $saldo_ajustado + 0.5) {
                throw new Exception('El monto entregado al supervisor no puede superar el efectivo contado');
            }

            $pdo->beginTransaction();
            try {
                $id_extracto_ajuste = null;
                $id_extracto_cierre = null;

                // Generar faltante/sobrante como ajuste automático
                if (!$cuadra) {
                if ($tipo_diferencia === 'SOBRANTE') {
                    $conceptoAjuste = "AJUSTE CIERRE - SOBRANTE " . number_format(abs($diferencia), 0, ',', '.');
                    $stmtAj = $pdo->prepare("
                        INSERT INTO $dbName.extracto_caja
                        (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, medio_cobro)
                        VALUES (1, :id_caja, :concepto, 0, :credito, NOW(), :login, :id_login, 1, 1, 1, 2, 16, 'EFECTIVO')
                    ");
                    $stmtAj->execute([
                        ':id_caja' => $id_caja,
                        ':concepto' => substr($conceptoAjuste, 0, 200),
                        ':credito' => abs($diferencia),
                        ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                        ':id_login' => $id_login
                    ]);
                    $id_extracto_ajuste = (int)$pdo->lastInsertId();
                } else if ($tipo_diferencia === 'FALTANTE') {
                    $conceptoAjuste = "AJUSTE CIERRE - FALTANTE " . number_format(abs($diferencia), 0, ',', '.');
                    $stmtAj = $pdo->prepare("
                        INSERT INTO $dbName.extracto_caja
                        (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, medio_cobro)
                        VALUES (1, :id_caja, :concepto, :debito, 0, NOW(), :login, :id_login, 1, 1, 1, 1, 15, 'EFECTIVO')
                    ");
                    $stmtAj->execute([
                        ':id_caja' => $id_caja,
                        ':concepto' => substr($conceptoAjuste, 0, 200),
                        ':debito' => abs($diferencia),
                        ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                        ':id_login' => $id_login
                    ]);
                    $id_extracto_ajuste = (int)$pdo->lastInsertId();
                }
                }

                // Registrar cierre principal
                $concepto = "CIERRE DE CAJA | Sistema: " . number_format($saldo_sistema, 0, ',', '.') .
                    " | Contado: " . number_format($efectivo_contado, 0, ',', '.') .
                    " | Dif: " . number_format($diferencia, 0, ',', '.') .
                    " | Entregado: " . number_format($monto_entregado_supervisor, 0, ',', '.');

                if ($observacion) {
                    $concepto .= " | Obs: " . $observacion;
                }

                $stmt = $pdo->prepare("
                INSERT INTO $dbName.extracto_caja 
                (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, medio_cobro, beneficiario)
                VALUES (1, :id_caja, :concepto, :debito, 0, NOW(), :login, :id_login, 1, 1, 1, 4, 11, 'EFECTIVO', :beneficiario)
            ");
                $stmt->execute([
                ':id_caja' => $id_caja,
                ':concepto' => substr($concepto, 0, 200),
                ':debito' => $monto_entregado_supervisor,
                ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                ':id_login' => $id_login,
                ':beneficiario' => substr($supervisor_nombre, 0, 80),
                ]);
                $id_extracto_cierre = (int)$pdo->lastInsertId();

                $token_verificacion = bin2hex(random_bytes(16));
                $resumen_json = json_encode([
                'efectivo' => (float)($resumenDia['efectivo'] ?? 0),
                'tarjeta' => (float)($resumenDia['tarjeta'] ?? 0),
                'transferencia' => (float)($resumenDia['transferencia'] ?? 0),
                'qr_pix' => (float)($resumenDia['qr_pix'] ?? 0),
                'total_entradas' => (float)($resumenDia['total_entradas'] ?? 0),
                'total_salidas' => (float)($resumenDia['total_salidas'] ?? 0),
                'saldo' => (float)($resumenDia['saldo'] ?? 0),
                ], JSON_UNESCAPED_UNICODE);

                $stmtCierre = $pdo->prepare("
                INSERT INTO $dbName.caja_cierres
                (id_caja, id_empresa, id_login, fecha_cierre, fecha_operativa, saldo_sistema, efectivo_contado, diferencia, tipo_diferencia, monto_ajuste, monto_entregado_supervisor, supervisor_nombre, observacion, resumen_json, token_verificacion, id_extracto_ajuste, id_extracto_cierre)
                VALUES
                (:id_caja, :id_empresa, :id_login, NOW(), CURDATE(), :saldo_sistema, :efectivo_contado, :diferencia, :tipo_diferencia, :monto_ajuste, :monto_entregado_supervisor, :supervisor_nombre, :observacion, :resumen_json, :token, :id_extracto_ajuste, :id_extracto_cierre)
            ");
                $stmtCierre->execute([
                ':id_caja' => $id_caja,
                ':id_empresa' => $id_empresa,
                ':id_login' => $id_login,
                ':saldo_sistema' => $saldo_sistema,
                ':efectivo_contado' => $efectivo_contado,
                ':diferencia' => $diferencia,
                ':tipo_diferencia' => $tipo_diferencia,
                ':monto_ajuste' => abs($diferencia),
                ':monto_entregado_supervisor' => $monto_entregado_supervisor,
                ':supervisor_nombre' => substr($supervisor_nombre, 0, 120),
                ':observacion' => $observacion !== '' ? substr($observacion, 0, 255) : null,
                ':resumen_json' => $resumen_json,
                ':token' => $token_verificacion,
                ':id_extracto_ajuste' => $id_extracto_ajuste,
                ':id_extracto_cierre' => $id_extracto_cierre,
                ]);
                $id_cierre = (int)$pdo->lastInsertId();

                if (is_array($valores)) {
                    $stmtVal = $pdo->prepare("
                    INSERT INTO $dbName.caja_cierres_valores
                    (id_cierre, denominacion, cantidad, subtotal, orden_item)
                    VALUES (:id_cierre, :denominacion, :cantidad, :subtotal, :orden_item)
                ");
                    $orden = 1;
                    foreach ($valores as $v) {
                        $den = (float)($v['denominacion'] ?? 0);
                        $cant = (int)($v['cantidad'] ?? 0);
                        if ($den <= 0 || $cant < 0) continue;
                        $sub = $den * $cant;
                        $stmtVal->execute([
                            ':id_cierre' => $id_cierre,
                            ':denominacion' => $den,
                            ':cantidad' => $cant,
                            ':subtotal' => $sub,
                            ':orden_item' => $orden++,
                        ]);
                    }
                }
                $pdo->commit();

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $comprobante_url = $scheme . '://' . $host . '/public/pos/cierre_comprobante.php?id=' . $id_cierre . '&token=' . $token_verificacion . '&id_empresa=' . $id_empresa;
                $ticket_url = $scheme . '://' . $host . '/public/pos/cierre_comprobante_ticket.php?id=' . $id_cierre . '&token=' . $token_verificacion . '&id_empresa=' . $id_empresa . '&autoprint=1';

                echo json_encode([
                'success' => true,
                'message' => 'Caja cerrada correctamente',
                'saldo_cierre' => $monto_entregado_supervisor,
                'diferencia' => $diferencia,
                'cuadra' => $cuadra,
                'tipo_diferencia' => $tipo_diferencia,
                'id_cierre' => $id_cierre,
                'comprobante_url' => $comprobante_url,
                'ticket_url' => $ticket_url
            ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        // ===== LISTA FACTURAS PENDIENTES DE COBRO =====
        case 'facturas_pendientes_list':
            $limit = (int)($_GET['limit'] ?? 2000);
            $query = trim((string)($_GET['q'] ?? ''));
            $factCols = tableColumns($pdo, $dbName, 'factura_ventas');
            [$where, $saldoExpr] = facturaPendienteWhereSql($factCols);
            $params = [];

            if ($query !== '') {
                $where[] = "(f.nro_factura LIKE :q OR c.nombre LIKE :q OR c.numero LIKE :q)";
                $params[':q'] = '%' . $query . '%';
            }

            $whereSql = implode(" AND ", $where);

            $sql = "SELECT 
                        f.id_factura,
                        f.nro_factura,
                        f.fecha,
                        f.total,
                        COALESCE(f.tipo_documento, 0) AS tipo_documento,
                        COALESCE(f.cdc, '') AS cdc,
                        $saldoExpr as saldo,
                        c.nombre as cliente,
                        c.numero as ruc
                    FROM $dbName.factura_ventas f
                    LEFT JOIN $dbName.clientes c ON f.id_cliente = c.id
                    WHERE $whereSql
                    ORDER BY f.fecha DESC, f.id_factura DESC
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'facturas' => $facturas]);
            break;

        // ===== OPERACIONES POR MEDIO DE PAGO =====
        case 'operaciones_por_medio':
            $medio = strtoupper(trim($_GET['medio'] ?? ''));
            $fecha = $_GET['fecha'] ?? date('Y-m-d');
            $whereUsuario = $solo_mias ? " AND (e.id_login = :id_login OR CAST(e.login AS CHAR) = :login_usuario OR CAST(e.login AS CHAR) = :id_login_txt)" : "";

            // Build WHERE condition based on medio
            $medioCond = "";
            if ($medio === 'TARJETA') {
                $medioCond = "(e.medio_cobro = 'TARJETA' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TARJETA%'))";
            } elseif ($medio === 'TRANSFERENCIA' || $medio === 'TRANSFER') {
                $medioCond = "(e.medio_cobro = 'TRANSFERENCIA' OR e.medio_cobro = 'TRANSFER' OR (e.medio_cobro IS NULL AND e.concepto LIKE '%TRANSFER%'))";
            } elseif ($medio === 'QR' || $medio === 'PIX' || $medio === 'QR/PIX') {
                $medioCond = "(e.medio_cobro IN ('QR', 'PIX') OR (e.medio_cobro IS NULL AND (e.concepto LIKE '%QR%' OR e.concepto LIKE '%PIX%')))";
            } else {
                echo json_encode(['success' => false, 'message' => 'Medio no válido']);
                break;
            }

            $sql = "SELECT 
                        e.id,
                        e.fecha,
                        e.concepto,
                        e.credito,
                        e.debito,
                        e.medio_cobro,
                        e.comprobante,
                        r.referencia as referencia_nombre
                    FROM $dbName.extracto_caja e
                    LEFT JOIN " . MASTER_DB . ".referencia r ON e.referencia = r.id
                    WHERE e.codigo = :id_caja 
                      AND DATE(e.fecha) = :fecha 
                      AND e.estado = 1
                      $whereUsuario
                      AND $medioCond
                    ORDER BY e.fecha DESC";

            $stmt = $pdo->prepare($sql);
            $params = [':id_caja' => $id_caja, ':fecha' => $fecha];
            if ($solo_mias) {
                $params[':id_login'] = $id_login;
                $params[':login_usuario'] = $login_usuario;
                $params[':id_login_txt'] = $id_login_txt;
            }
            $stmt->execute($params);
            $operaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'operaciones' => $operaciones]);
            break;

        // ===== PENDING INVOICES (COBRO FACTURA) =====
        case 'pending_invoices':
            $query = trim($_GET['query'] ?? '');
            $limit = (int)($_GET['limit'] ?? 50);

            // Query facturas - usar columna saldo si existe
            $sql = "SELECT 
                        f.id_factura,
                        f.nro_factura,
                        f.fecha,
                        f.total,
                        c.nombre as cliente,
                        c.numero as ruc,
                        COALESCE(f.saldo, f.total) as saldo_factura
                    FROM $dbName.factura_ventas f
                    LEFT JOIN $dbName.clientes c ON f.id_cliente = c.id
                    WHERE (f.nro_factura LIKE :query 
                        OR c.nombre LIKE :query 
                        OR c.numero LIKE :query)
                        AND (COALESCE(f.saldo, f.total) > 0.01)
                    ORDER BY f.id_factura DESC
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calcular totales
            foreach ($invoices as &$inv) {
                $inv['saldo_pendiente'] = (float)$inv['saldo_factura'];
                $inv['total_pagado'] = (float)$inv['total'] - (float)$inv['saldo_factura'];
            }

            echo json_encode([
                'success' => true,
                'invoices' => $invoices
            ]);
            break;

        // ===== DETALLE DE FACTURA CON HISTORIAL DE PAGOS =====
        case 'invoice_detail':
            $idFactura = (int)($_GET['id'] ?? 0);

            if ($idFactura <= 0) {
                throw new Exception("ID de factura inválido");
            }

            // Obtener datos de la factura (tolerante a estructuras sin columna saldo)
            $factCols = tableColumns($pdo, $dbName, 'factura_ventas');
            $saldoCol = null;
            if (in_array('saldo', $factCols, true)) {
                $saldoCol = 'saldo';
            } elseif (in_array('pendiente', $factCols, true)) {
                $saldoCol = 'pendiente';
            }
            $saldoExpr = $saldoCol ? "COALESCE(f.$saldoCol, f.total)" : "COALESCE(f.total, 0)";

            $cliJoin = '';
            $cliSelect = "'' AS cliente, '' AS ruc";
            if (tableExists($pdo, $dbName, 'clientes') && in_array('id_cliente', $factCols, true)) {
                $cliCols = tableColumns($pdo, $dbName, 'clientes');
                $cliIdCol = pickColumn($cliCols, ['id', 'codigo']);
                $cliNombreCol = pickColumn($cliCols, ['nombre', 'razon_social', 'cliente']);
                $cliRucCol = pickColumn($cliCols, ['numero', 'ruc', 'documento']);
                if ($cliIdCol && $cliNombreCol) {
                    $cliJoin = " LEFT JOIN $dbName.clientes c ON f.id_cliente = c.$cliIdCol ";
                    $cliSelect = "COALESCE(c.$cliNombreCol, '') AS cliente, COALESCE(" . ($cliRucCol ? "c.$cliRucCol" : "''") . ", '') AS ruc";
                }
            }

            $stmtFact = $pdo->prepare("
                SELECT f.id_factura, f.id_cliente, f.nro_factura, f.fecha, f.total,
                       COALESCE(f.tipo_documento, 0) AS tipo_documento,
                       COALESCE(f.cdc, '') AS cdc,
                       $saldoExpr AS saldo,
                       $cliSelect
                FROM $dbName.factura_ventas f
                $cliJoin
                WHERE f.id_factura = :id
            ");
            $stmtFact->execute([':id' => $idFactura]);
            $factura = $stmtFact->fetch(PDO::FETCH_ASSOC);

            if (!$factura) {
                throw new Exception("Factura no encontrada");
            }

            // Obtener historial de pagos (con manejo de errores)
            $pagos = [];
            try {
                // Intentar primero con extracto_cliente
                $stmtPagos = $pdo->prepare("
                    SELECT ec.id, ec.fecha, ec.credito as monto, ec.concepto, 
                           COALESCE(ec.medio_cobro, 'EFECTIVO') as medio_cobro
                    FROM $dbName.extracto_cliente ec
                    WHERE ec.id_factura = :id AND ec.estado = 1 AND ec.credito > 0
                    ORDER BY ec.fecha DESC
                ");
                $stmtPagos->execute([':id' => $idFactura]);
                $pagos = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Si falla, intentar con extracto_caja donde concepto contenga el nro de factura
                try {
                    $stmtPagos2 = $pdo->prepare("
                        SELECT ec.id, ec.fecha, ec.credito as monto, ec.concepto,
                               COALESCE(ec.medio_cobro, 'EFECTIVO') as medio_cobro
                        FROM $dbName.extracto_caja ec
                        WHERE ec.concepto LIKE :nro AND ec.estado = 1 AND ec.credito > 0
                        ORDER BY ec.fecha DESC
                    ");
                    $stmtPagos2->execute([':nro' => '%' . $factura['nro_factura'] . '%']);
                    $pagos = $stmtPagos2->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e2) {
                    $pagos = [];
                }
            }

            // Obtener items de la factura (extracto_productos) + imagen por proxy
            $items = [];
            try {
                $idFacturaCol = null;
                $idProductoCol = null;
                $hasSalidaCol = false;
                $stmtColsEp = $pdo->query("SHOW COLUMNS FROM $dbName.extracto_productos");
                foreach (($stmtColsEp ? $stmtColsEp->fetchAll(PDO::FETCH_ASSOC) : []) as $c) {
                    $field = strtolower((string)($c['Field'] ?? ''));
                    if ($field === 'idfactura' || $field === 'id_factura') {
                        $idFacturaCol = $field;
                    }
                    if ($field === 'idproducto' || $field === 'id_producto') {
                        $idProductoCol = $field;
                    }
                    if ($field === 'salida') {
                        $hasSalidaCol = true;
                    }
                }

                if ($idFacturaCol) {
                    $exprIdProducto = $idProductoCol ? "COALESCE(ep.$idProductoCol, 0)" : "0";
                    $exprCantidad = $hasSalidaCol ? "SUM(COALESCE(ep.salida, 0))" : "COUNT(*)";

                    $sqlItems = "
                        SELECT
                            $exprIdProducto AS idproducto,
                            COALESCE(ep.descripcion, 'Producto') AS producto,
                            COALESCE(ep.precio, 0) AS precio,
                            $exprCantidad AS cantidad
                        FROM $dbName.extracto_productos ep
                        WHERE ep.$idFacturaCol = :id
                        " . ($hasSalidaCol ? "AND ep.salida > 0" : "") . "
                        GROUP BY idproducto, producto, precio
                        ORDER BY producto
                    ";
                    $stmtItems = $pdo->prepare($sqlItems);
                    $stmtItems->execute([':id' => $idFactura]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    foreach ($items as &$it) {
                        $pid = (int)($it['idproducto'] ?? 0);
                        $q = rawurlencode((string)($it['producto'] ?? 'producto'));
                        $it['imagen_url'] = "/public/pos/api/imagen_proxy.php?id={$pid}&q={$q}";
                    }
                    unset($it);
                }
            } catch (Exception $e) {
                $items = [];
            }

            // Usar saldo de la factura directamente
            $saldoPendiente = (float)$factura['saldo'];
            $totalPagado = (float)$factura['total'] - $saldoPendiente;

            echo json_encode([
                'success' => true,
                'factura' => $factura,
                'items' => $items,
                'pagos' => $pagos,
                'total_pagado' => $totalPagado,
                'saldo_pendiente' => $saldoPendiente
            ]);
            break;
        // ===== PENDING DOCUMENTS =====
        case 'pending_documents':
            $query = trim($_GET['query'] ?? '');
            $limit = (int)($_GET['limit'] ?? 50);

            $sql = "SELECT 
                        d.numero as id_documento, 
                        d.id_cliente,
                        d.id_factura,
                        d.concepto,
                        d.cantidad_cuota,
                        d.fecha_creacion,
                        d.fecha_vencimiento,
                        d.total,
                        d.pagado,
                        d.pendiente,
                        c.nombre as cliente,
                        c.numero as ruc
                    FROM $dbName.documentos d
                    LEFT JOIN $dbName.clientes c ON d.id_cliente = c.id
                    WHERE (d.concepto LIKE :query 
                        OR c.nombre LIKE :query 
                        OR c.numero LIKE :query)
                        AND (d.pendiente > 0)
                        AND (d.estado = 1)
                    ORDER BY d.fecha_vencimiento ASC
                    LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'documents' => $documents
            ]);
            break;

        case 'document_detail':
            $idDocumento = (int)($_GET['id'] ?? 0);
            if ($idDocumento <= 0) {
                throw new Exception("Documento inválido");
            }

            $stmtDoc = $pdo->prepare("
                SELECT
                    d.numero as id_documento,
                    d.id_cliente,
                    d.id_factura,
                    d.concepto,
                    d.cantidad_cuota,
                    d.fecha_creacion,
                    d.fecha_vencimiento,
                    COALESCE(d.capital, 0) as capital,
                    COALESCE(d.int_capital, 0) as interes,
                    COALESCE(d.total, 0) as total,
                    COALESCE(d.pagado, 0) as pagado,
                    COALESCE(d.pendiente, 0) as pendiente,
                    COALESCE(d.estado, 1) as estado,
                    COALESCE(c.nombre, '') as cliente,
                    COALESCE(c.numero, '') as ruc,
                    COALESCE(f.nro_factura, '') as nro_factura,
                    COALESCE(f.fecha, d.fecha_creacion) as fecha_factura
                FROM $dbName.documentos d
                LEFT JOIN $dbName.clientes c ON c.id = d.id_cliente
                LEFT JOIN $dbName.factura_ventas f ON f.id_factura = d.id_factura
                WHERE d.numero = :id
                LIMIT 1
            ");
            $stmtDoc->execute([':id' => $idDocumento]);
            $documento = $stmtDoc->fetch(PDO::FETCH_ASSOC);

            if (!$documento) {
                throw new Exception("Documento no encontrado");
            }

            echo json_encode([
                'success' => true,
                'document' => $documento
            ]);
            break;

        // ===== COBRO DOCUMENTO =====
        case 'cobro_documento':
            $input = json_decode(file_get_contents('php://input'), true);
            $idDocumento = (int)($input['id_documento'] ?? 0);
            $monto = abs((float)($input['monto'] ?? 0));
            $paymentMethod = $input['payment_method'] ?? 'EFECTIVO';
            $paymentRef = $input['payment_ref'] ?? '';
            $conceptoInput = $input['concepto'] ?? '';

            if ($idDocumento <= 0 || $monto <= 0) {
                throw new Exception("Datos de documento inválidos");
            }
            if (strtoupper(trim((string)$paymentMethod)) === 'CREDITO') {
                throw new Exception("Crédito está deprecado en el módulo Caja");
            }

            $pdo->beginTransaction();
            try {
                // Si es una EDICIÓN (viene con ID), primero ANULAMOS el registro anterior
                $editingId = (int)($input['id'] ?? 0);
                if ($editingId > 0) {
                    assertOperacionPropia($pdo, $dbName, $editingId, $id_login, $login_usuario, $id_login_txt);
                    $stmtOld = $pdo->prepare("SELECT sucursal, id, credito, debito, referencia, tabla_relacion, id_relacion, concepto, comprobante, estado FROM $dbName.extracto_caja WHERE id = :id");
                    $stmtOld->execute([':id' => $editingId]);
                    $oldOp = $stmtOld->fetch(PDO::FETCH_ASSOC);

                    if ($oldOp && $oldOp['estado'] != 0) {
                        $oldRelTable = $oldOp['tabla_relacion'];
                        $oldRelId = (int)$oldOp['id_relacion'];
                        $oldMonto = (float)($oldOp['credito'] > 0 ? $oldOp['credito'] : $oldOp['debito']);

                        // Reversa Cobro Documento
                        if ($oldRelTable == 'documentos') {
                            $pdo->exec("UPDATE $dbName.documentos SET pagado = pagado - $oldMonto, pendiente = pendiente + $oldMonto WHERE numero = $oldRelId");
                            // Si el documento tiene factura vinculada, restaurar saldo factura
                            $stmtDocLink = $pdo->prepare("SELECT id_factura FROM $dbName.documentos WHERE numero = :id");
                            $stmtDocLink->execute([':id' => $oldRelId]);
                            $oldIdFact = $stmtDocLink->fetchColumn();
                            if ($oldIdFact) {
                                restoreFacturaPendiente($pdo, $dbName, $oldIdFact, $oldMonto);
                            }
                        }
                        $pdo->exec("UPDATE $dbName.extracto_caja SET estado = 0 WHERE id = $editingId");
                    }
                }
                // Obtener Documento PK es 'numero'
                $stmtDoc = $pdo->prepare("SELECT * FROM $dbName.documentos WHERE numero = :id");
                $stmtDoc->execute([':id' => $idDocumento]);
                $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);

                if (!$doc) throw new Exception("Documento no encontrado");
                if ($doc['pendiente'] < ($monto - 1)) { // Tolerancia
                    throw new Exception("El monto excede el saldo pendiente");
                }

                // 1. Actualizar Documento
                $nuevoPendiente = $doc['pendiente'] - $monto;
                if ($nuevoPendiente < 0) $nuevoPendiente = 0;
                $nuevoPagado = $doc['pagado'] + $monto;

                $stmtUpd = $pdo->prepare("UPDATE $dbName.documentos SET pagado = :pagado, pendiente = :pendiente WHERE numero = :id");
                $stmtUpd->execute([':pagado' => $nuevoPagado, ':pendiente' => $nuevoPendiente, ':id' => $idDocumento]);

                // 2. Procesar Pago
                {
                    // Obtener o Crear Referencia 'Cobro de documentos'
                    $stmtRef = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".referencia WHERE referencia = 'Cobro de documentos' LIMIT 1");
                    $stmtRef->execute();
                    $refDocsId = $stmtRef->fetchColumn();

                    if (!$refDocsId) {
                        $pdo->exec("INSERT INTO " . MASTER_DB . ".referencia (referencia, tabla_extracto) VALUES ('Cobro de documentos', 'extracto_caja')");
                        $refDocsId = $pdo->lastInsertId();
                    }

                    // Caja
                    $conceptoCaja = $paymentMethod . " | Cobro Doc. " . substr($doc['concepto'], 0, 30);
                    $stmtCaja = $pdo->prepare("
                        INSERT INTO $dbName.extracto_caja 
                        (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante, medio_cobro, tabla_relacion, id_relacion)
                        VALUES (1, :id_caja, :concepto, 0, :credito, NOW(), :login, :id_login, 1, 1, 1, 2, :referencia, :comprobante, :medio_cobro, 'documentos', :id_documento)
                     ");
                    $stmtCaja->execute([
                        ':id_caja' => $id_caja,
                        ':concepto' => substr($conceptoCaja, 0, 60),
                        ':credito' => $monto,
                        ':login' => ($login_usuario !== '' ? $login_usuario : $id_login_txt),
                        ':id_login' => $id_login,
                        ':referencia' => $refDocsId,
                        ':comprobante' => substr($paymentRef, 0, 20),
                        ':medio_cobro' => $paymentMethod,
                        ':id_documento' => $idDocumento
                    ]);
                    $idCajaCobro = (int)$pdo->lastInsertId();

                    // Cliente (Crédito)
                    $conceptoCliente = "Pago Doc. " . substr($doc['concepto'], 0, 40) . " - " . $paymentMethod;
                    try {
                        $stmtCliente = $pdo->prepare("
                            INSERT INTO $dbName.extracto_cliente 
                            (codigo, concepto, debito, credito, fecha, login, estado, id_factura, referencia, tabla_relacion, id_relacion)
                            VALUES (:id_cliente, :concepto, 0, :credito, NOW(), :id_login, 1, :id_factura, :referencia, 'documentos', :id_documento)
                        ");
                        $stmtCliente->execute([
                            ':id_cliente' => $doc['id_cliente'],
                            ':concepto' => substr($conceptoCliente, 0, 60),
                            ':credito' => $monto,
                            ':id_login' => $id_login,
                            ':id_factura' => $doc['id_factura'],
                            ':referencia' => $refDocsId,
                            ':id_documento' => $idDocumento
                        ]);
                    } catch (Exception $e) {
                    }

                    // Actualizar Saldo Factura relacionada
                    if ($doc['id_factura'] > 0) {
                        try {
                            // Crear columna saldo si no existe (ya lo hacemos en cobro_factura, reusar logica)
                            $checkCol = $pdo->query("SHOW COLUMNS FROM $dbName.factura_ventas LIKE 'saldo'");
                            if ($checkCol->rowCount() == 0) {
                                $pdo->exec("ALTER TABLE $dbName.factura_ventas ADD COLUMN saldo DECIMAL(15,2) NULL AFTER total");
                                $pdo->exec("UPDATE $dbName.factura_ventas SET saldo = total WHERE saldo IS NULL");
                            }

                            $pdo->exec("UPDATE $dbName.factura_ventas SET saldo = saldo - $monto WHERE id_factura = " . $doc['id_factura']);
                        } catch (Exception $e) {
                        }
                    }

                    $receiptUrl = $idCajaCobro > 0
                        ? buildPublicDocReceiptUrl($id_empresa, $idCajaCobro, $idDocumento)
                        : '';
                }

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Cobro de documento registrado',
                    'receipt_url' => $receiptUrl ?? '',
                    'receipt_token' => isset($idCajaCobro) && $idCajaCobro > 0 ? buildPublicDocReceiptToken($id_empresa, $idCajaCobro, $idDocumento) : '',
                    'id_extracto_caja' => (int)($idCajaCobro ?? 0),
                    'id_documento' => $idDocumento
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
        case 'pago_factura_compra':
            $input = json_decode(file_get_contents('php://input'), true);
            $idFactura = (int)($input['id_factura'] ?? 0);
            $monto = abs((float)($input['monto'] ?? 0));
            $concepto = $input['concepto'] ?? 'Pago Factura Compra';
            $paymentMethod = strtoupper($input['payment_method'] ?? 'EFECTIVO');
            $editingId = (int)($input['id'] ?? 0);

            if ($idFactura <= 0 || $monto <= 0) {
                throw new Exception("Datos de pago inválidos");
            }

            sxComprasCreditoEnsureDocumentosCompraTable($pdo, $dbName);

            $pdo->beginTransaction();
            try {
                $stmtCompra = $pdo->prepare("SELECT id_factura, nro_factura, id_cliente, pendiente FROM $dbName.factura_compras WHERE id_factura = :id LIMIT 1");
                $stmtCompra->execute([':id' => $idFactura]);
                $compra = $stmtCompra->fetch(PDO::FETCH_ASSOC);
                if (!$compra) {
                    throw new Exception("Compra no encontrada");
                }
                $pendingDisponible = (float)$compra['pendiente'];
                $oldMonto = 0.0;
                $oldOp = null;

                // Si es edición, anular el anterior
                if ($editingId > 0) {
                    assertOperacionPropia($pdo, $dbName, $editingId, $id_login, $login_usuario, $id_login_txt);
                    $stmtOp = $pdo->prepare("SELECT * FROM $dbName.extracto_caja WHERE id = :id AND estado = 1");
                    $stmtOp->execute([':id' => $editingId]);
                    $oldOp = $stmtOp->fetch(PDO::FETCH_ASSOC);
                    if ($oldOp) {
                        $oldMonto = (float)$oldOp['debito'];
                        $pendingDisponible += $oldMonto;
                    }
                }
                if ($monto > ($pendingDisponible + 0.009)) {
                    throw new Exception("El monto supera el pendiente de la compra");
                }
                if ($editingId > 0 && $oldOp) {
                    reverseAccounting($pdo, $dbName, $editingId, $oldOp, $oldOp['tabla_relacion'], $oldOp['id_relacion'], $oldMonto, $oldOp['referencia']);
                    $pdo->prepare("UPDATE $dbName.extracto_caja SET estado = 0 WHERE id = :id")->execute([':id' => $editingId]);
                }

                // 1. Actualizar Factura Compra
                $stmtFact = $pdo->prepare("UPDATE $dbName.factura_compras SET pagado = pagado + :monto, pendiente = pendiente - :monto WHERE id_factura = :id");
                $stmtFact->execute([':monto' => $monto, ':id' => $idFactura]);

                // 2. Insertar en extracto_caja (Salida)
                $stmtCaja = $pdo->prepare("
                    INSERT INTO $dbName.extracto_caja 
                    (sucursal, codigo, concepto, debito, credito, fecha, login, id_login, estado, moneda, cambio, operacion, referencia, comprobante, medio_cobro, tabla_relacion, id_relacion)
                    VALUES (1, :id_caja, :concepto, :debito, 0, NOW(), :login, :id_login, 1, 1, 1, 1, 39, :comprobante, :medio, 'factura_compras', :id_fact)
                ");

                // Obtener numero factura para el comprobante
                $stmtNro = $pdo->prepare("SELECT nro_factura FROM $dbName.factura_compras WHERE id_factura = :id");
                $stmtNro->execute([':id' => $idFactura]);
                $nroFact = $stmtNro->fetchColumn() ?: '';

                $stmtCaja->execute([
                    ':id_caja' => $id_caja,
                    ':concepto' => substr($concepto, 0, 150),
                    ':debito' => $monto,
                    ':login' => ($login_usuario !== '' ? $login_usuario : ($_SESSION['login'] ?? 'admin')),
                    ':id_login' => $id_login,
                    ':comprobante' => $nroFact,
                    ':medio' => $paymentMethod,
                    ':id_fact' => $idFactura
                ]);

                sxComprasCreditoInsertSupplierPaymentLedger(
                    $pdo,
                    $dbName,
                    $idFactura,
                    (int)($compra['id_cliente'] ?? 0),
                    $id_login,
                    $monto,
                    $paymentMethod,
                    (string)($compra['nro_factura'] ?? ''),
                    (string)$concepto
                );
                sxComprasCreditoApplyPaymentToInstallments($pdo, $dbName, $idFactura, $monto);
                sxComprasCreditoSyncFacturaBalance($pdo, $dbName, $idFactura);

                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        default:
            throw new Exception("Acción no reconocida: $action");
    }
} catch (Throwable $e) {
    // Para anulación en Caja, devolver siempre 200 con JSON controlado
    // y evitar que el frontend lo trate como "HTTP error".
    if (!in_array($action, ['void', 'close'], true) && !$soft_error) {
        http_response_code(400);
    } else {
        http_response_code(200);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
