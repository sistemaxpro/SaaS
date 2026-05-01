<?php
/**
 * Exportar ventas a XLS (HTML compatible con Excel)
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

Session::start();
Permission::requireAccess('app_grid_factura_venta_global');

$id_empresa = (int)Session::get('id_empresa');
if ($id_empresa <= 0) {
    http_response_code(403);
    echo 'Empresa no definida en sesión';
    exit;
}

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$fechaDesde = isset($_GET['fecha_desde']) ? (string)$_GET['fecha_desde'] : '';
$fechaHasta = isset($_GET['fecha_hasta']) ? (string)$_GET['fecha_hasta'] : '';
$estado = isset($_GET['estado']) ? (string)$_GET['estado'] : '';

try {
    $empresa = Database::getEmpresaInfo($id_empresa);
    if (!$empresa || empty($empresa['dbase'])) {
        throw new Exception('Empresa no encontrada o sin base configurada');
    }

    $pdo = Database::getEmpresaConnection($id_empresa);
    $dbName = (string)$empresa['dbase'];

    $ventasTable = null;
    foreach (['factura_ventas', 'factura_venta'] as $candidateTable) {
        $stTbl = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($candidateTable));
        if ($stTbl->fetchColumn()) {
            $ventasTable = $candidateTable;
            break;
        }
    }
    if ($ventasTable === null) {
        throw new Exception("No existe tabla de ventas compatible en {$dbName}");
    }

    $columns = [];
    $stCols = $pdo->query("DESCRIBE {$ventasTable}");
    while ($col = $stCols->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = strtolower((string)($col['Field'] ?? ''));
    }
    $hasTipoDocumento = in_array('tipo_documento', $columns, true);
    $hasEstadoSifen = in_array('estado_sifen', $columns, true);

    $estadoProfileSql = "SELECT
            SUM(CASE WHEN LOWER(TRIM(CAST(fv.estado AS CHAR))) = '0' THEN 1 ELSE 0 END) AS count_0,
            SUM(CASE WHEN LOWER(TRIM(CAST(fv.estado AS CHAR))) = '1' THEN 1 ELSE 0 END) AS count_1,
            SUM(CASE WHEN LOWER(TRIM(CAST(fv.estado AS CHAR))) IN ('anulado', 'anulada') THEN 1 ELSE 0 END) AS count_anulado_texto,
            SUM(CASE WHEN fv.fecha_anulacion IS NOT NULL THEN 1 ELSE 0 END) AS count_fecha_anulacion,
            SUM(CASE WHEN fv.fecha_anulacion IS NOT NULL AND LOWER(TRIM(CAST(fv.estado AS CHAR))) = '0' THEN 1 ELSE 0 END) AS count_fecha_0,
            SUM(CASE WHEN fv.fecha_anulacion IS NOT NULL AND LOWER(TRIM(CAST(fv.estado AS CHAR))) = '1' THEN 1 ELSE 0 END) AS count_fecha_1
        FROM {$ventasTable} fv";
    $estadoProfile = $pdo->query($estadoProfileSql)->fetch(PDO::FETCH_ASSOC) ?: [];
    $countEstado0 = (int)($estadoProfile['count_0'] ?? 0);
    $countEstado1 = (int)($estadoProfile['count_1'] ?? 0);
    $countAnuladoTexto = (int)($estadoProfile['count_anulado_texto'] ?? 0);
    $countFechaAnulacion = (int)($estadoProfile['count_fecha_anulacion'] ?? 0);
    $countFecha0 = (int)($estadoProfile['count_fecha_0'] ?? 0);
    $countFecha1 = (int)($estadoProfile['count_fecha_1'] ?? 0);
    $activeNumericStates = [];
    $anuladoNumericStates = [];
    if ($countFecha0 > $countFecha1) {
        $activeNumericStates = ['1'];
        $anuladoNumericStates = ['0'];
    } elseif ($countFecha1 > $countFecha0) {
        $activeNumericStates = ['0'];
        $anuladoNumericStates = ['1'];
    } elseif ($countFechaAnulacion === 0 && $countAnuladoTexto === 0) {
        if ($countEstado0 > 0) {
            $activeNumericStates[] = '0';
        }
        if ($countEstado1 > 0) {
            $activeNumericStates[] = '1';
        }
    } elseif ($countEstado0 > 0 || $countEstado1 > 0) {
        $activeNumericStates[] = $countEstado1 > $countEstado0 ? '1' : '0';
        $anuladoCandidate = $activeNumericStates[0] === '1' ? '0' : '1';
        if (($anuladoCandidate === '0' && $countEstado0 > 0) || ($anuladoCandidate === '1' && $countEstado1 > 0)) {
            $anuladoNumericStates[] = $anuladoCandidate;
        }
    }
    $estadoSqlExpr = static function (string $field, string $target, array $activeNumericStates, array $anuladoNumericStates): string {
        $fieldTxt = "LOWER(TRIM(CAST({$field} AS CHAR)))";
        $parts = [];
        if ($target === 'activo') {
            foreach ($activeNumericStates as $state) {
                $parts[] = "{$fieldTxt} = '{$state}'";
            }
            $parts[] = "{$field} IS NULL";
            $parts[] = "{$fieldTxt} = ''";
            $parts[] = "{$fieldTxt} IN ('activo', 'activa')";
        } else {
            foreach ($anuladoNumericStates as $state) {
                $parts[] = "{$fieldTxt} = '{$state}'";
            }
            $parts[] = "{$fieldTxt} IN ('anulado', 'anulada')";
        }
        return '(' . implode(' OR ', array_unique($parts)) . ')';
    };
    $estadoActivoExpr = $estadoSqlExpr('fv.estado', 'activo', $activeNumericStates, $anuladoNumericStates);
    $estadoAnuladoExpr = $estadoSqlExpr('fv.estado', 'anulado', $activeNumericStates, $anuladoNumericStates);
    $estadoUiExpr = "CASE
        WHEN {$estadoActivoExpr} THEN 'activo'
        WHEN {$estadoAnuladoExpr} THEN 'anulado'
        ELSE 'activo'
    END";

    $clienteJoin = "LEFT JOIN clientes c ON c.id = fv.id_cliente";
    $clienteNombreExpr = "c.nombre";
    $clienteRucExpr = "c.numero";
    $searchFields = ["fv.nro_factura"];
    $hasClientesTable = (bool)$pdo->query("SHOW TABLES LIKE 'clientes'")->fetchColumn();
    if ($hasClientesTable) {
        $clientColumns = [];
        $stClientCols = $pdo->query("DESCRIBE clientes");
        while ($col = $stClientCols->fetch(PDO::FETCH_ASSOC)) {
            $clientColumns[] = strtolower((string)($col['Field'] ?? ''));
        }
        $clienteIdCol = in_array('id', $clientColumns, true) ? 'id' : (in_array('id_cliente', $clientColumns, true) ? 'id_cliente' : null);
        $clienteNombreCol = in_array('nombre', $clientColumns, true) ? 'nombre' : (in_array('razon_social', $clientColumns, true) ? 'razon_social' : null);
        $clienteNumeroCol = in_array('numero', $clientColumns, true) ? 'numero' : (in_array('ruc', $clientColumns, true) ? 'ruc' : null);

        if ($clienteIdCol !== null && in_array('id_cliente', $columns, true)) {
            $clienteNombreAgg = $clienteNombreCol !== null
                ? "COALESCE(
                    MAX(CASE
                        WHEN NULLIF(TRIM({$clienteNombreCol}), '') IS NOT NULL
                         AND UPPER(TRIM({$clienteNombreCol})) NOT IN ('SIN NOMBRE', 'CONSUMIDOR FINAL')
                        THEN TRIM({$clienteNombreCol})
                    END),
                    MAX(NULLIF(TRIM({$clienteNombreCol}), '')),
                    'Consumidor Final'
                )"
                : "'Consumidor Final'";
            $clienteRucAgg = $clienteNumeroCol !== null
                ? "COALESCE(
                    MAX(CASE
                        WHEN NULLIF(TRIM({$clienteNumeroCol}), '') IS NOT NULL
                         AND UPPER(TRIM({$clienteNumeroCol})) NOT IN ('X', '0')
                        THEN TRIM({$clienteNumeroCol})
                    END),
                    MAX(NULLIF(TRIM({$clienteNumeroCol}), ''))
                )"
                : "NULL";
            $clienteJoin = "LEFT JOIN (
                    SELECT
                        {$clienteIdCol} AS cliente_id_ref,
                        {$clienteNombreAgg} AS cliente_nombre,
                        {$clienteRucAgg} AS cliente_ruc
                    FROM clientes
                    GROUP BY {$clienteIdCol}
                ) c ON c.cliente_id_ref = fv.id_cliente";
            $clienteNombreExpr = "c.cliente_nombre";
            $clienteRucExpr = "c.cliente_ruc";
            $searchFields[] = $clienteNombreExpr;
            $searchFields[] = $clienteRucExpr;
        } else {
            $clienteJoin = "";
            $clienteNombreExpr = "NULL";
            $clienteRucExpr = "NULL";
        }
    } else {
        $clienteJoin = "";
        $clienteNombreExpr = "NULL";
        $clienteRucExpr = "NULL";
    }

    $where = ['1=1'];
    $params = [];

    if ($search !== '') {
        $searchConditions = [];
        foreach ($searchFields as $idx => $field) {
            $paramKey = ':search' . $idx;
            $searchConditions[] = "{$field} LIKE {$paramKey}";
            $params[$paramKey] = "%{$search}%";
        }
        $where[] = '(' . implode(' OR ', $searchConditions) . ')';
    }
    if ($fechaDesde !== '') {
        $where[] = "DATE(fv.fecha) >= :fecha_desde";
        $params[':fecha_desde'] = $fechaDesde;
    }
    if ($fechaHasta !== '') {
        $where[] = "DATE(fv.fecha) <= :fecha_hasta";
        $params[':fecha_hasta'] = $fechaHasta;
    }
    if ($estado === 'activo') {
        $where[] = $estadoActivoExpr;
    } elseif ($estado === 'anulado') {
        $where[] = $estadoAnuladoExpr;
    }

    $whereClause = implode(' AND ', $where);
    $sql = "SELECT
                fv.id_factura,
                fv.nro_factura,
                fv.fecha,
                fv.total,
                fv.estado,
                {$estadoUiExpr} AS estado_ui,
                " . ($hasTipoDocumento ? "fv.tipo_documento" : "0 AS tipo_documento") . ",
                " . ($hasEstadoSifen ? "fv.estado_sifen" : "NULL AS estado_sifen") . ",
                {$clienteNombreExpr} AS cliente_nombre,
                {$clienteRucExpr} AS cliente_ruc
            FROM {$ventasTable} fv
            {$clienteJoin}
            WHERE {$whereClause}
            ORDER BY fv.id_factura DESC, fv.fecha DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'ventas_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";

    $tipoDocLabel = static function ($tipo) {
        $t = (int)$tipo;
        if ($t === 3) return 'Factura Electronica';
        if ($t === 1) return 'Autoimpresa';
        return 'Nota de control';
    };

    $sifenLabel = static function ($estadoSifen) {
        $val = strtolower(trim((string)$estadoSifen));
        if ($val === 'aprobado') return 'Aprobado';
        if ($val === 'rechazado') return 'Rechazado';
        return 'Pendiente';
    };

    $estadoLabel = static function ($estadoUi, $estadoRaw) {
        $val = strtolower(trim((string)$estadoUi));
        if ($val === 'anulado') {
            return 'Anulado';
        }
        return 'Activo';
    };

    $esc = static function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>ID</th><th>Nro Factura</th><th>Fecha</th><th>Cliente</th><th>Tipo Doc</th><th>Estado SIFEN</th><th>Estado</th><th>Total</th>';
    echo '</tr>';

    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . (int)($r['id_factura'] ?? 0) . '</td>';
        echo '<td>' . $esc($r['nro_factura'] ?? '') . '</td>';
        echo '<td>' . $esc($r['fecha'] ?? '') . '</td>';
        echo '<td>' . $esc($r['cliente_nombre'] ?? 'Consumidor Final') . '</td>';
        echo '<td>' . $esc($tipoDocLabel($r['tipo_documento'] ?? 0)) . '</td>';
        echo '<td>' . $esc($sifenLabel($r['estado_sifen'] ?? '')) . '</td>';
        echo '<td>' . $esc($estadoLabel($r['estado_ui'] ?? '', $r['estado'] ?? 0)) . '</td>';
        echo '<td>' . (float)($r['total'] ?? 0) . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error al exportar: ' . $e->getMessage();
}
