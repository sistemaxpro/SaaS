<?php
/**
 * Cajas API - Kardex basado en extracto_caja
 * GET: ?id_caja=X&page=1&per_page=50&fecha_desde=&fecha_hasta=&operacion=&referencia=&q=
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_caja');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_caja    = (int)($_GET['id_caja'] ?? 0);

if ($id_caja <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID de caja requerido']);
    exit;
}

function sx_table_exists(PDO $pdo, string $schema, string $table): bool
{
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = :s AND table_name = :t LIMIT 1");
    $st->execute([':s' => $schema, ':t' => $table]);
    return (bool)$st->fetchColumn();
}

function sx_stripos(string $haystack, string $needle)
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle);
    }
    return stripos($haystack, $needle);
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = Database::getMasterConnection();
    $masterDb = defined('MASTER_DB') ? (string)MASTER_DB : Database::getMasterDbName();

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $fechaDesde = trim((string)($_GET['fecha_desde'] ?? ''));
    $fechaHasta = trim((string)($_GET['fecha_hasta'] ?? ''));
    $operacion  = (int)($_GET['operacion'] ?? 0);
    $referencia = (int)($_GET['referencia'] ?? 0);
    $q          = trim((string)($_GET['q'] ?? ''));

    $stmtCaja = $pdo->prepare("SELECT caja FROM {$db}.cajas WHERE id_caja = ?");
    $stmtCaja->execute([$id_caja]);
    $cajaNombre = $stmtCaja->fetchColumn() ?: 'Caja #' . $id_caja;

    $where = ["e.codigo = :id_caja", "COALESCE(e.estado, 1) <> 0"];
    $params = [':id_caja' => $id_caja];

    if ($fechaDesde !== '') {
        $where[] = "e.fecha >= :fecha_desde";
        $params[':fecha_desde'] = $fechaDesde . ' 00:00:00';
    }
    if ($fechaHasta !== '') {
        $where[] = "e.fecha <= :fecha_hasta";
        $params[':fecha_hasta'] = $fechaHasta . ' 23:59:59';
    }
    if ($operacion > 0) {
        $where[] = "e.operacion = :operacion";
        $params[':operacion'] = $operacion;
    }
    if ($referencia > 0) {
        $where[] = "e.referencia = :referencia";
        $params[':referencia'] = $referencia;
    }
    if ($q !== '') {
        $where[] = "(e.concepto LIKE :q OR e.descripcion LIKE :q OR e.comprobante LIKE :q OR e.beneficiario LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmtC = $pdo->prepare("SELECT COUNT(*) as total FROM {$db}.extracto_caja e {$whereSQL}");
    $stmtC->execute($params);
    $total = (int)$stmtC->fetch()['total'];

    $sql = "SELECT
                e.id,
                e.fecha,
                e.concepto,
                e.descripcion,
                e.medio_cobro,
                e.credito,
                e.debito,
                e.saldo,
                e.login,
                e.comprobante,
                e.beneficiario,
                e.sucursal,
                e.confirmado,
                e.conciliado,
                e.nro_liquidacion,
                e.operacion,
                e.referencia,
                o.operacion as operacion_nombre,
                r.referencia as referencia_nombre,
                CASE
                    WHEN LOWER(e.concepto) LIKE '%apertura%' THEN 'Apertura'
                    WHEN LOWER(e.concepto) LIKE '%cierre%' THEN 'Cierre'
                    WHEN e.credito > 0 AND e.debito = 0 THEN 'Entrada'
                    WHEN e.debito > 0 AND e.credito = 0 THEN 'Salida'
                    ELSE 'Movimiento'
                END as tipo_fallback
            FROM {$db}.extracto_caja e
            LEFT JOIN " . MASTER_DB . ".operaciones o ON e.operacion = o.id
            LEFT JOIN " . MASTER_DB . ".referencia r ON e.referencia = r.id
            {$whereSQL}
            ORDER BY e.fecha DESC, e.id DESC
            LIMIT {$limit} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $loginIds = array_values(array_unique(array_filter(array_column($movimientos, 'login'))));
    $nombresLogin = [];
    if (count($loginIds) > 0) {
        $ph = implode(',', array_fill(0, count($loginIds), '?'));
        $tableUsers = null;
        if (sx_table_exists($masterPdo, $masterDb, 'sec_users')) {
            $tableUsers = "{$masterDb}.sec_users";
        } elseif (sx_table_exists($masterPdo, $masterDb, 'sec_logins')) {
            $tableUsers = "{$masterDb}.sec_logins";
        }

        if ($tableUsers) {
            $stmtN = $masterPdo->prepare("SELECT id_login, name, login FROM {$tableUsers} WHERE id_login IN ({$ph})");
            $stmtN->execute($loginIds);
            while ($row = $stmtN->fetch(PDO::FETCH_ASSOC)) {
                $label = trim((string)($row['name'] ?? '')) ?: trim((string)($row['login'] ?? ''));
                if ($label === '') $label = 'Usuario #' . (int)$row['id_login'];
                $nombresLogin[(string)$row['id_login']] = $label;
            }
        }
    }

    foreach ($movimientos as &$m) {
        $loginKey = (string)($m['login'] ?? '');
        $m['nombre_usuario'] = $nombresLogin[$loginKey] ?? ('Usuario #' . (int)($m['login'] ?? 0));
        $m['tipo_operacion'] = $m['operacion_nombre'] ?: $m['tipo_fallback'];
        if (sx_stripos((string)$m['tipo_operacion'], 'entrada') !== false || (float)$m['credito'] > 0) {
            $m['color_op'] = 'green';
        } elseif (sx_stripos((string)$m['tipo_operacion'], 'salida') !== false || (float)$m['debito'] > 0) {
            $m['color_op'] = 'red';
        } else {
            $m['color_op'] = 'blue';
        }
    }
    unset($m);

    $stmtSum = $pdo->prepare("SELECT
        COUNT(*) as total_movimientos,
        COALESCE(SUM(e.credito), 0) as total_ingresos,
        COALESCE(SUM(e.debito), 0) as total_egresos,
        COALESCE(SUM(e.credito - e.debito), 0) as saldo_neto,
        MIN(e.fecha) as primera_operacion,
        MAX(e.fecha) as ultima_operacion
        FROM {$db}.extracto_caja e {$whereSQL}");
    $stmtSum->execute($params);
    $resumen = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtOpRes = $pdo->prepare("SELECT
            COALESCE(o.operacion, CONCAT('Operación #', e.operacion)) as etiqueta,
            COUNT(*) as cantidad,
            COALESCE(SUM(e.credito), 0) as ingresos,
            COALESCE(SUM(e.debito), 0) as egresos,
            COALESCE(SUM(e.credito - e.debito), 0) as saldo
        FROM {$db}.extracto_caja e
        LEFT JOIN " . MASTER_DB . ".operaciones o ON e.operacion = o.id
        {$whereSQL}
        GROUP BY e.operacion, o.operacion
        ORDER BY cantidad DESC
        LIMIT 10");
    $stmtOpRes->execute($params);
    $resumenOperaciones = $stmtOpRes->fetchAll(PDO::FETCH_ASSOC);

    $stmtRefRes = $pdo->prepare("SELECT
            COALESCE(r.referencia, CONCAT('Referencia #', e.referencia)) as etiqueta,
            COUNT(*) as cantidad,
            COALESCE(SUM(e.credito), 0) as ingresos,
            COALESCE(SUM(e.debito), 0) as egresos,
            COALESCE(SUM(e.credito - e.debito), 0) as saldo
        FROM {$db}.extracto_caja e
        LEFT JOIN " . MASTER_DB . ".referencia r ON e.referencia = r.id
        {$whereSQL}
        GROUP BY e.referencia, r.referencia
        ORDER BY cantidad DESC
        LIMIT 10");
    $stmtRefRes->execute($params);
    $resumenReferencias = $stmtRefRes->fetchAll(PDO::FETCH_ASSOC);

    $catalogos = [
        'operaciones' => [],
        'referencias' => [],
    ];

    $stmtOps = $pdo->query("SELECT id, operacion FROM " . MASTER_DB . ".operaciones WHERE tabla_extracto = 'extracto_caja' OR operacion LIKE '%Caja%' ORDER BY operacion");
    $catalogos['operaciones'] = $stmtOps->fetchAll(PDO::FETCH_ASSOC);

    $stmtRefs = $pdo->query("SELECT id, referencia FROM " . MASTER_DB . ".referencia WHERE tabla_extracto = 'extracto_caja' OR referencia LIKE '%Caja%' OR referencia LIKE '%Cobro%' OR referencia LIKE '%Pago%' ORDER BY referencia");
    $catalogos['referencias'] = $stmtRefs->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'       => true,
        'caja'     => $cajaNombre,
        'data'     => $movimientos,
        'total'    => $total,
        'pages'    => max(1, (int)ceil($total / $limit)),
        'page'     => $page,
        'catalogos' => $catalogos,
        'resumen' => [
            'total_movimientos' => (int)($resumen['total_movimientos'] ?? 0),
            'total_ingresos'    => (float)($resumen['total_ingresos'] ?? 0),
            'total_egresos'     => (float)($resumen['total_egresos'] ?? 0),
            'saldo_neto'        => (float)($resumen['saldo_neto'] ?? 0),
            'primera_operacion' => $resumen['primera_operacion'] ?? null,
            'ultima_operacion'  => $resumen['ultima_operacion'] ?? null,
            'por_operacion'     => $resumenOperaciones,
            'por_referencia'    => $resumenReferencias,
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
