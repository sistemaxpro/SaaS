<?php
/**
 * Contactos API - Detalle completo de un contacto
 * GET: ?id=123
 * GET: ?action=cuentas (catálogo de cuentas)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_clientes');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$action = $_GET['action'] ?? 'detalle';

// Catálogo de cuentas
if ($action === 'cuentas') {
    try {
        $conn = getEmpresaConnection($id_empresa);
        $pdo = $conn['pdo'];
        $db = $conn['dbName'];
        $stmt = $pdo->query("SELECT id, cuenta FROM {$db}.cuentas WHERE fijo = 1 ORDER BY id");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Catálogo de sucursales
if ($action === 'sucursales') {
    try {
        $conn = getEmpresaConnection($id_empresa);
        $pdo = $conn['pdo'];
        $db = $conn['dbName'];
        $stmt = $pdo->query("SELECT id_sucursal, sucursal FROM {$db}.sucursales ORDER BY id_sucursal");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$idContacto = (int)($_GET['id'] ?? 0);

if ($idContacto <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de contacto requerido']);
    exit;
}

try {
    $masterPdo = Database::getMasterConnection();
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $extractoCols = [];
    try {
        $extractoColsStmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'extracto_cliente'");
        $extractoCols = $extractoColsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $extractoCols = [];
    }
    $extractoColMeta = [];
    try {
        $extractoMetaStmt = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'extracto_cliente'");
        foreach (($extractoMetaStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $metaRow) {
            $extractoColMeta[(string)$metaRow['COLUMN_NAME']] = strtolower((string)$metaRow['COLUMN_TYPE']);
        }
    } catch (Throwable $e) {
        $extractoColMeta = [];
    }
    $hasExtracto = static function (string $col) use ($extractoCols): bool {
        return in_array($col, $extractoCols, true);
    };

    $stmt = $pdo->prepare("SELECT c.*, cu.cuenta AS cuenta_nombre
            FROM {$db}.clientes c
            LEFT JOIN {$db}.cuentas cu ON cu.id = c.cuenta
            WHERE c.id = :id");
    $stmt->execute([':id' => $idContacto]);
    $contacto = $stmt->fetch();

    if (!$contacto) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Contacto no encontrado']);
        exit;
    }

    // Normalizar defaults
    $defaults = [
        'telefono' => '', 'email' => '', 'ciudad' => '', 'pais' => '',
        'fecha_nacimiento' => null, 'linea_credito' => 0, 'vendedor_asignado' => 0,
        'complemento_direccion1' => '', 'complemento_direccion2' => '', 'numero_casa' => '',
        'salario' => 0, 'comision' => 0, 'tipo_comision' => 0, 'periodo' => 0,
        'dia_pago_salario' => 0, 'login_vinculado' => 0
    ];
    foreach ($defaults as $col => $defVal) {
        if (!array_key_exists($col, $contacto)) $contacto[$col] = $defVal;
    }

    // Remove firma blob (heavy)
    unset($contacto['firma']);

    // Cuenta tipo label
    $cuentaMap = [3 => 'Cliente', 4 => 'Proveedor', 5 => 'Empleado'];
    $contacto['cuenta_tipo'] = $cuentaMap[(int)$contacto['cuenta']] ?? ($contacto['cuenta_nombre'] ?? 'Otro');

    $kardex = [];
    $saldoKardex = null;
    try {
        try {
            $masterPdo->exec("
                CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_kardex_auth_requests (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    id_empresa INT NOT NULL DEFAULT 0,
                    modulo VARCHAR(50) NOT NULL DEFAULT 'contactos_kardex',
                    tabla VARCHAR(80) NOT NULL DEFAULT 'extracto_cliente',
                    record_id BIGINT NOT NULL,
                    accion VARCHAR(20) NOT NULL DEFAULT 'anular',
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    requested_by_login_id INT NOT NULL DEFAULT 0,
                    requested_by_login VARCHAR(120) NULL,
                    authorized_by_login_id INT NULL,
                    authorized_by_login VARCHAR(120) NULL,
                    titulo VARCHAR(150) NULL,
                    mensaje TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    resolved_at DATETIME NULL,
                    INDEX idx_empresa_record (id_empresa, record_id),
                    INDEX idx_status (status),
                    INDEX idx_modulo_tabla (modulo, tabla)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {}

        $pdo->query("SELECT 1 FROM {$db}.extracto_cliente LIMIT 1");
        $referenciaJoin = '';
        $referenciaExpr = "'' AS referencia";
        if ($hasExtracto('referencia')) {
            $referenciaType = (string)($extractoColMeta['referencia'] ?? '');
            if (strpos($referenciaType, 'int') !== false) {
                $referenciaJoin = "LEFT JOIN " . MASTER_DB . ".referencia refm ON refm.id = ec.referencia";
                $referenciaExpr = "COALESCE(refm.referencia, CAST(ec.referencia AS CHAR), '') AS referencia";
            } else {
                $referenciaExpr = "COALESCE(ec.referencia, '') AS referencia";
            }
        }

        $usuarioJoin = '';
        $idLoginExpr = "'' AS id_login";
        if ($hasExtracto('id_login')) {
            $usuarioJoin = "LEFT JOIN " . MASTER_DB . ".sec_users su ON su.id_login = ec.id_login";
            $idLoginExpr = "COALESCE(NULLIF(TRIM(su.login), ''), NULLIF(TRIM(su.name), ''), CAST(ec.id_login AS CHAR), '') AS id_login";
        }
        $stmtKardex = $pdo->prepare("
            SELECT
                ec.id,
                ec.fecha,
                ec.concepto,
                COALESCE(ec.debito, 0) AS debito,
                COALESCE(ec.credito, 0) AS credito,
                COALESCE(ec.acumulado, 0) AS saldo,
                COALESCE(ec.estado, 0) AS estado,
                COALESCE(ec.id_factura, 0) AS id_factura,
                COALESCE(ec.tabla_relacion, '') AS tabla_relacion,
                COALESCE(ec.id_relacion, 0) AS id_relacion,
                {$referenciaExpr},
                {$idLoginExpr}
            FROM {$db}.extracto_cliente ec
            {$referenciaJoin}
            {$usuarioJoin}
            WHERE ec.codigo = :id
            ORDER BY ec.fecha ASC, ec.id ASC
            LIMIT 50
        ");
        $stmtKardex->execute([':id' => $idContacto]);
        $kardex = $stmtKardex->fetchAll() ?: [];
        if (!empty($kardex)) {
            $runningSaldo = 0.0;
            foreach ($kardex as &$row) {
                $rowEstado = (int)($row['estado'] ?? 0);
                if ($rowEstado === 0) {
                    $runningSaldo += (float)($row['debito'] ?? 0) - (float)($row['credito'] ?? 0);
                }
                $row['saldo'] = $runningSaldo;
            }
            unset($row);
            $saldoKardex = $runningSaldo;
        }

        if (!empty($kardex)) {
            $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $kardex)));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $reqStmt = $masterPdo->prepare("
                    SELECT r.record_id, r.status
                    FROM " . MASTER_DB . ".smx_kardex_auth_requests r
                    INNER JOIN (
                        SELECT record_id, MAX(id) AS max_id
                        FROM " . MASTER_DB . ".smx_kardex_auth_requests
                        WHERE id_empresa = ?
                          AND modulo = 'contactos_kardex'
                          AND tabla = 'extracto_cliente'
                          AND record_id IN ({$placeholders})
                        GROUP BY record_id
                    ) x ON x.max_id = r.id
                ");
                $reqStmt->execute(array_merge([$id_empresa], $ids));
                $statusByRecord = [];
                foreach (($reqStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $reqRow) {
                    $statusByRecord[(int)$reqRow['record_id']] = (string)($reqRow['status'] ?? '');
                }
                foreach ($kardex as &$row) {
                    $row['request_status'] = $statusByRecord[(int)($row['id'] ?? 0)] ?? '';
                }
                unset($row);
            }
        }
    } catch (Throwable $e) {
        $kardex = [];
    }

    if ($saldoKardex === null) {
        $saldoKardex = (float)($contacto['saldo_guaranies'] ?? $contacto['saldo'] ?? 0);
    }

    // Saldo formateado priorizando extracto_cliente
    $contacto['saldo_display'] = [
        'guaranies' => $saldoKardex,
        'dolares'   => (float)($contacto['saldo_dolares'] ?? 0),
        'reales'    => (float)($contacto['saldo_reales'] ?? 0),
    ];
    $contacto['saldo_guaranies'] = $saldoKardex;
    $contacto['saldo'] = $saldoKardex;

    $documentosCobrar = [];
    try {
        $pdo->query("SELECT 1 FROM {$db}.documentos LIMIT 1");
        $stmtDocs = $pdo->prepare("
            SELECT
                d.numero,
                d.fecha_creacion,
                d.fecha_vencimiento,
                COALESCE(d.concepto, '') AS concepto,
                COALESCE(d.total, 0) AS total,
                COALESCE(d.pagado, 0) AS pagado,
                COALESCE(d.pendiente, 0) AS pendiente,
                COALESCE(d.estado, 1) AS estado,
                COALESCE(d.id_factura, 0) AS id_factura,
                COALESCE(d.cantidad_cuota, '') AS cantidad_cuota
            FROM {$db}.documentos d
            WHERE d.id_cliente = :id
            ORDER BY d.fecha_vencimiento DESC, d.numero DESC
            LIMIT 50
        ");
        $stmtDocs->execute([':id' => $idContacto]);
        $documentosCobrar = $stmtDocs->fetchAll() ?: [];
    } catch (Throwable $e) {
        $documentosCobrar = [];
    }

    $documentosPagar = [];
    try {
        $pdo->query("SELECT 1 FROM {$db}.documentos_compra LIMIT 1");
        $stmtDocsCompra = $pdo->prepare("
            SELECT
                dc.id,
                dc.id_factura,
                dc.nro_factura,
                dc.cuota_numero,
                COALESCE(dc.cantidad_cuota, '') AS cantidad_cuota,
                COALESCE(dc.observacion, '') AS concepto,
                dc.fecha_emision AS fecha_creacion,
                dc.fecha_vencimiento,
                COALESCE(dc.total, 0) AS total,
                COALESCE(dc.pagado, 0) AS pagado,
                COALESCE(dc.pendiente, 0) AS pendiente,
                COALESCE(dc.estado, 1) AS estado
            FROM {$db}.documentos_compra dc
            WHERE dc.id_proveedor = :id
              AND COALESCE(dc.estado, 1) = 1
            ORDER BY dc.fecha_vencimiento DESC, dc.id DESC
            LIMIT 50
        ");
        $stmtDocsCompra->execute([':id' => $idContacto]);
        $documentosPagar = $stmtDocsCompra->fetchAll() ?: [];
    } catch (Throwable $e) {
        $documentosPagar = [];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'contacto' => $contacto,
            'kardex' => $kardex,
            'documentos_cobrar' => $documentosCobrar,
            'documentos_pagar' => $documentosPagar,
            'documentos' => $documentosCobrar
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
