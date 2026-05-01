<?php
require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
Session::requireLogin('/public/login.php');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function gs($n): string { return number_format((float)$n, 0, ',', '.'); }
function money($n): string { return number_format((float)$n, 0, ',', '.'); }
function shortText(string $txt, int $len = 70): string {
    $txt = trim($txt);
    if ($txt === '') return '';
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($txt, 0, $len, '...');
    }
    return strlen($txt) > $len ? substr($txt, 0, $len - 3) . '...' : $txt;
}

function firstColumn(array $cols, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $cols, true)) {
            return $candidate;
        }
    }
    return null;
}

function tableExists(PDO $pdo, string $db, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :tb");
    $stmt->execute([':db' => $db, ':tb' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function getColumns(PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME AS column_name FROM information_schema.columns WHERE table_schema = :db AND table_name = :tb");
    $stmt->execute([':db' => $db, ':tb' => $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($rows as $r) {
        if (isset($r['column_name'])) {
            $cols[] = strtolower((string)$r['column_name']);
            continue;
        }
        // Fallback defensivo para drivers/esquemas que devuelven claves distintas
        $first = reset($r);
        if ($first !== false && $first !== null) {
            $cols[] = strtolower((string)$first);
        }
    }
    return $cols;
}

function dateParam(string $value): ?string {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

$idEmpresa = (int)Session::getIdEmpresa();
$userName = Session::get('user_name', Session::get('usuario', 'Usuario'));
$dbNameRaw = (string)(Session::getDbase() ?? '');
$dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbNameRaw);
$empresaActual = MultiTenant::getEmpresaActual();
$empresaNombre = trim((string)($empresaActual['empresa'] ?? ($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? '')));
if ($empresaNombre === '') {
    $empresaNombre = 'Empresa #' . $idEmpresa;
}
$empresaRuc = trim((string)($empresaActual['ruc'] ?? ''));
$empresaDv = trim((string)($empresaActual['dv'] ?? ''));
$empresaRucFull = trim($empresaRuc . ($empresaDv !== '' ? '-' . $empresaDv : ''));
$empresaDb = trim((string)($empresaActual['dbase'] ?? $dbName));
$empresaServer = trim((string)($empresaActual['server'] ?? ($_SESSION['server'] ?? '')));
$empresaUserDb = trim((string)($empresaActual['user'] ?? ($_SESSION['user'] ?? '')));
$empresaMoneda = trim((string)($empresaActual['moneda_principal'] ?? ''));

$periodo = $_GET['periodo'] ?? 'mes';
$periodoSolicitado = isset($_GET['periodo']) || isset($_GET['desde']) || isset($_GET['hasta']);
$periodosValidos = ['hoy', 'semana', 'mes', 'personalizado'];
if (!in_array($periodo, $periodosValidos, true)) {
    $periodo = 'mes';
}

$today = new DateTime('today');
$fechaInicio = clone $today;
$fechaFin = clone $today;

if ($periodo === 'hoy') {
    $fechaInicio = clone $today;
    $fechaFin = clone $today;
} elseif ($periodo === 'semana') {
    $fechaInicio = new DateTime('monday this week');
    $fechaFin = clone $today;
} elseif ($periodo === 'mes') {
    $fechaInicio = new DateTime('first day of this month');
    $fechaFin = clone $today;
} else {
    $fi = dateParam($_GET['desde'] ?? '');
    $ff = dateParam($_GET['hasta'] ?? '');
    if ($fi && $ff && $fi <= $ff) {
        $fechaInicio = new DateTime($fi);
        $fechaFin = new DateTime($ff);
    } else {
        $fechaInicio = new DateTime('first day of this month');
        $fechaFin = clone $today;
    }
}

$desde = $fechaInicio->format('Y-m-d');
$hasta = $fechaFin->format('Y-m-d');
$diasRango = (int)$fechaInicio->diff($fechaFin)->days + 1;

$cmpFin = (clone $fechaInicio)->modify('-1 day');
$cmpInicio = (clone $cmpFin)->modify('-' . ($diasRango - 1) . ' day');
$cmpDesde = $cmpInicio->format('Y-m-d');
$cmpHasta = $cmpFin->format('Y-m-d');

$panel = [
    'ventas' => 0,
    'monto' => 0,
    'ticket' => 0,
    'anuladas' => 0,
    'sifen' => 0,
    'ventas_cmp' => 0,
    'monto_cmp' => 0,
];
$serieDiasLabels = [];
$serieDiasTotales = [];
$serieDiasCant = [];
$serieDiasCosto = [];
$serieDiasResultado = [];
$metodosPago = [];
$ultimasVentas = [];
$topProductos = [];
$cuentasCobrar = [
    'cantidad' => 0,
    'monto' => 0,
    'vencidas_cantidad' => 0,
    'vencidas_monto' => 0,
];
$gastosRubro = [];
$gastosTotalRango = 0;
$costoVentaTotalRango = 0;
$impuestosVentasRango = 0;
$impuestosComprasRango = 0;
$errorPanel = null;

try {
    $pdo = Database::getSessionEmpresaConnection();

    if ($dbName === '') {
        throw new Exception('No hay base de datos de empresa en sesión.');
    }
    if (!tableExists($pdo, $dbName, 'factura_ventas')) {
        throw new Exception("No existe la tabla {$dbName}.factura_ventas");
    }

    $colsFv = getColumns($pdo, $dbName, 'factura_ventas');
    $hasFecha = in_array('fecha', $colsFv, true);
    $hasTotal = in_array('total', $colsFv, true);
    $hasEstado = in_array('estado', $colsFv, true);
    $hasFormaPago = in_array('forma_pago', $colsFv, true);
    $hasCdc = in_array('cdc', $colsFv, true);
    $hasNro = in_array('nro_factura', $colsFv, true);
    $hasIdFactura = in_array('id_factura', $colsFv, true);

    if (!$hasFecha || !$hasTotal) {
        throw new Exception('La tabla factura_ventas no tiene columnas mínimas requeridas (fecha/total).');
    }

    $activeExpr = "1=1";
    $anuladoExpr = $hasEstado
        ? "(LOWER(TRIM(CAST(fv.estado AS CHAR))) IN ('anulado','anulada','cancelado','cancelada'))"
        : "0=1";

    if (!$periodoSolicitado) {
        $sqlMaxFecha = "
            SELECT MAX(fv.fecha) AS max_fecha
            FROM {$dbName}.factura_ventas fv
            WHERE {$activeExpr}
        ";
        $rowMaxFecha = $pdo->query($sqlMaxFecha)->fetch(PDO::FETCH_ASSOC) ?: [];
        $maxFechaRaw = (string)($rowMaxFecha['max_fecha'] ?? '');
        if ($maxFechaRaw !== '') {
            $maxFecha = new DateTime($maxFechaRaw);
            if ($maxFecha < $fechaInicio || $maxFecha > $fechaFin) {
                $fechaInicio = new DateTime($maxFecha->format('Y-m-01'));
                $fechaFin = new DateTime($maxFecha->format('Y-m-d'));
                $desde = $fechaInicio->format('Y-m-d');
                $hasta = $fechaFin->format('Y-m-d');
                $diasRango = (int)$fechaInicio->diff($fechaFin)->days + 1;
                $cmpFin = (clone $fechaInicio)->modify('-1 day');
                $cmpInicio = (clone $cmpFin)->modify('-' . ($diasRango - 1) . ' day');
                $cmpDesde = $cmpInicio->format('Y-m-d');
                $cmpHasta = $cmpFin->format('Y-m-d');
                $periodo = 'personalizado';
            }
        }
    }

    $sqlKpi = "
        SELECT
            COUNT(*) AS ventas,
            COALESCE(SUM(fv.total),0) AS monto,
            COALESCE(AVG(fv.total),0) AS ticket,
            SUM(CASE WHEN {$anuladoExpr} THEN 1 ELSE 0 END) AS anuladas,
            SUM(CASE WHEN " . ($hasCdc ? "TRIM(COALESCE(fv.cdc,'')) <> ''" : "0=1") . " THEN 1 ELSE 0 END) AS sifen
        FROM {$dbName}.factura_ventas fv
        WHERE DATE(fv.fecha) BETWEEN :desde AND :hasta
          AND {$activeExpr}
    ";
    $stmtKpi = $pdo->prepare($sqlKpi);
    $stmtKpi->execute([':desde' => $desde, ':hasta' => $hasta]);
    $rowKpi = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];

    $panel['ventas'] = (int)($rowKpi['ventas'] ?? 0);
    $panel['monto'] = (float)($rowKpi['monto'] ?? 0);
    $panel['ticket'] = (float)($rowKpi['ticket'] ?? 0);
    $panel['anuladas'] = (int)($rowKpi['anuladas'] ?? 0);
    $panel['sifen'] = (int)($rowKpi['sifen'] ?? 0);

    $sqlCmp = "
        SELECT COUNT(*) AS ventas_cmp, COALESCE(SUM(fv.total),0) AS monto_cmp
        FROM {$dbName}.factura_ventas fv
        WHERE DATE(fv.fecha) BETWEEN :desde AND :hasta
          AND {$activeExpr}
    ";
    $stmtCmp = $pdo->prepare($sqlCmp);
    $stmtCmp->execute([':desde' => $cmpDesde, ':hasta' => $cmpHasta]);
    $rowCmp = $stmtCmp->fetch(PDO::FETCH_ASSOC) ?: [];
    $panel['ventas_cmp'] = (int)($rowCmp['ventas_cmp'] ?? 0);
    $panel['monto_cmp'] = (float)($rowCmp['monto_cmp'] ?? 0);

    $pIvaVenta10Col = firstColumn($colsFv, ['piva10', 'piva_10']);
    $pIvaVenta5Col = firstColumn($colsFv, ['piva5', 'piva_5']);
    $ivaVenta10Col = firstColumn($colsFv, ['iva10', 'iva_10']);
    $ivaVenta5Col = firstColumn($colsFv, ['iva5', 'iva_5']);
    $ivaVentaTotalCol = firstColumn($colsFv, ['iva', 'iva_total']);
    if ($pIvaVenta10Col || $pIvaVenta5Col || $ivaVenta10Col || $ivaVenta5Col || $ivaVentaTotalCol) {
        $taxParts = [];
        if ($pIvaVenta10Col) $taxParts[] = "COALESCE(fv.{$pIvaVenta10Col}, 0)";
        if ($pIvaVenta5Col) $taxParts[] = "COALESCE(fv.{$pIvaVenta5Col}, 0)";
        if (empty($taxParts)) {
            if ($ivaVenta10Col) $taxParts[] = "(COALESCE(fv.{$ivaVenta10Col}, 0) / 11)";
            if ($ivaVenta5Col) $taxParts[] = "(COALESCE(fv.{$ivaVenta5Col}, 0) / 21)";
            if (empty($taxParts) && $ivaVentaTotalCol) {
                $taxParts[] = "COALESCE(fv.{$ivaVentaTotalCol}, 0)";
            }
        }
        $sqlTaxVentas = "
            SELECT COALESCE(SUM(" . implode(' + ', $taxParts) . "), 0) AS impuestos_ventas
            FROM {$dbName}.factura_ventas fv
            WHERE DATE(fv.fecha) BETWEEN :desde AND :hasta
              AND {$activeExpr}
        ";
        $stmtTaxVentas = $pdo->prepare($sqlTaxVentas);
        $stmtTaxVentas->execute([':desde' => $desde, ':hasta' => $hasta]);
        $impuestosVentasRango = (float)$stmtTaxVentas->fetchColumn();
    }

    $chartStart = clone $fechaInicio;
    $maxChartDays = 60;
    if ($diasRango > $maxChartDays) {
        $chartStart = (clone $fechaFin)->modify('-' . ($maxChartDays - 1) . ' day');
    }
    $chartDesde = $chartStart->format('Y-m-d');
    $chartHasta = $fechaFin->format('Y-m-d');

    $sqlSerie = "
        SELECT DATE(fv.fecha) AS dia, COUNT(*) AS cantidad, COALESCE(SUM(fv.total),0) AS monto
        FROM {$dbName}.factura_ventas fv
        WHERE DATE(fv.fecha) BETWEEN :desde AND :hasta
          AND {$activeExpr}
        GROUP BY DATE(fv.fecha)
        ORDER BY DATE(fv.fecha) ASC
    ";
    $stmtSerie = $pdo->prepare($sqlSerie);
    $stmtSerie->execute([':desde' => $chartDesde, ':hasta' => $chartHasta]);
    $rowsSerie = $stmtSerie->fetchAll(PDO::FETCH_ASSOC);
    $idxSerie = [];
    foreach ($rowsSerie as $r) {
        $idxSerie[$r['dia']] = $r;
    }

    $idxCostoSerie = [];
    if (
        tableExists($pdo, $dbName, 'extracto_productos') &&
        tableExists($pdo, $dbName, 'tblproductos')
    ) {
        $colsEpChart = getColumns($pdo, $dbName, 'extracto_productos');
        $hasEpChartIdFact = in_array('idfactura', $colsEpChart, true);
        $hasEpChartIdProd = in_array('idproducto', $colsEpChart, true);
        $hasEpChartSalida = in_array('salida', $colsEpChart, true);
        $epCostoCol = firstColumn($colsEpChart, ['costo', 'precio_costo']);
        $colsProdChart = getColumns($pdo, $dbName, 'tblproductos');
        $precioCompraCol = firstColumn($colsProdChart, ['precio_compra', 'precio_costo', 'costo']);

        if ($hasEpChartIdFact && $hasEpChartIdProd && $hasEpChartSalida && ($epCostoCol !== null || $precioCompraCol !== null)) {
            $costoExpr = $epCostoCol !== null
                ? "COALESCE(NULLIF(ep.{$epCostoCol}, 0), " . ($precioCompraCol !== null ? "NULLIF(p.{$precioCompraCol}, 0), " : "") . "0)"
                : "COALESCE(NULLIF(p.{$precioCompraCol}, 0), 0)";
            $sqlCostoSerie = "
                SELECT
                    DATE(fv.fecha) AS dia,
                    COALESCE(SUM(ep.salida * {$costoExpr}), 0) AS costo
                FROM {$dbName}.extracto_productos ep
                INNER JOIN {$dbName}.tblproductos p ON p.idproducto = ep.idproducto
                INNER JOIN {$dbName}.factura_ventas fv ON fv.id_factura = ep.idfactura
                WHERE ep.salida > 0
                  AND DATE(fv.fecha) BETWEEN :desde AND :hasta
                  AND {$activeExpr}
                GROUP BY DATE(fv.fecha)
                ORDER BY DATE(fv.fecha) ASC
            ";
            $stmtCostoSerie = $pdo->prepare($sqlCostoSerie);
            $stmtCostoSerie->execute([':desde' => $chartDesde, ':hasta' => $chartHasta]);
            $rowsCostoSerie = $stmtCostoSerie->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rowsCostoSerie as $rowCosto) {
                $idxCostoSerie[$rowCosto['dia']] = (float)($rowCosto['costo'] ?? 0);
                $costoVentaTotalRango += (float)($rowCosto['costo'] ?? 0);
            }
        }
    }

    $cursor = clone $chartStart;
    while ($cursor <= $fechaFin) {
        $d = $cursor->format('Y-m-d');
        $montoDia = (float)($idxSerie[$d]['monto'] ?? 0);
        $costoDia = (float)($idxCostoSerie[$d] ?? 0);
        $serieDiasLabels[] = $cursor->format('d/m');
        $serieDiasTotales[] = $montoDia;
        $serieDiasCant[] = (int)($idxSerie[$d]['cantidad'] ?? 0);
        $serieDiasCosto[] = $costoDia;
        $serieDiasResultado[] = $montoDia - $costoDia;
        $cursor->modify('+1 day');
    }

    if ($hasFormaPago) {
        $sqlPago = "
            SELECT fv.forma_pago, COUNT(*) AS cantidad, COALESCE(SUM(fv.total),0) AS monto
            FROM {$dbName}.factura_ventas fv
            WHERE DATE(fv.fecha) BETWEEN :desde AND :hasta
              AND {$activeExpr}
            GROUP BY fv.forma_pago
            ORDER BY monto DESC
        ";
        $stmtPago = $pdo->prepare($sqlPago);
        $stmtPago->execute([':desde' => $desde, ':hasta' => $hasta]);
        $rowsPago = $stmtPago->fetchAll(PDO::FETCH_ASSOC);

        $mapPago = [
            '1' => 'Contado / Efectivo',
            '2' => 'Tarjeta',
            '3' => 'Transferencia',
            '4' => 'QR',
            '5' => 'Credito',
        ];
        foreach ($rowsPago as $p) {
            $cod = (string)($p['forma_pago'] ?? '');
            $metodosPago[] = [
                'label' => $mapPago[$cod] ?? ('Forma ' . ($cod !== '' ? $cod : 'N/D')),
                'cantidad' => (int)$p['cantidad'],
                'monto' => (float)$p['monto']
            ];
        }
    }

    $saldoPendienteExpr = null;
    if (in_array('saldo', $colsFv, true)) {
        $saldoPendienteExpr = "COALESCE(fv.saldo, fv.total, 0)";
    } elseif (in_array('pendiente', $colsFv, true)) {
        $saldoPendienteExpr = "COALESCE(fv.pendiente, fv.total, 0)";
    }

    if ($saldoPendienteExpr !== null) {
        $whereCxC = ["{$saldoPendienteExpr} > 0.01"];
        if (in_array('medio_cobro', $colsFv, true)) {
            $whereCxC[] = "UPPER(TRIM(COALESCE(fv.medio_cobro, ''))) = 'PENDIENTE'";
        }
        if ($hasEstado) {
            $whereCxC[] = "COALESCE(fv.estado, 1) = 1";
        }

        $vencimientoCol = firstColumn($colsFv, ['fecha_vencimiento', 'vencimiento', 'fecha_vto', 'fecha_vto_pago']);
        $whereCxCSql = implode(' AND ', $whereCxC);
        $sqlCxC = "
            SELECT
                COUNT(*) AS cantidad,
                COALESCE(SUM({$saldoPendienteExpr}),0) AS monto,
                SUM(CASE WHEN " . ($vencimientoCol ? "DATE(fv.{$vencimientoCol}) < CURDATE()" : "0=1") . " THEN 1 ELSE 0 END) AS vencidas_cantidad,
                COALESCE(SUM(CASE WHEN " . ($vencimientoCol ? "DATE(fv.{$vencimientoCol}) < CURDATE()" : "0=1") . " THEN {$saldoPendienteExpr} ELSE 0 END),0) AS vencidas_monto
            FROM {$dbName}.factura_ventas fv
            WHERE {$whereCxCSql}
        ";
        $rowCxC = $pdo->query($sqlCxC)->fetch(PDO::FETCH_ASSOC) ?: [];
        $cuentasCobrar['cantidad'] = (int)($rowCxC['cantidad'] ?? 0);
        $cuentasCobrar['monto'] = (float)($rowCxC['monto'] ?? 0);
        $cuentasCobrar['vencidas_cantidad'] = (int)($rowCxC['vencidas_cantidad'] ?? 0);
        $cuentasCobrar['vencidas_monto'] = (float)($rowCxC['vencidas_monto'] ?? 0);
    }

    if (tableExists($pdo, $dbName, 'gastos_empresa')) {
        $colsGastos = getColumns($pdo, $dbName, 'gastos_empresa');
        $fechaGastoCol = firstColumn($colsGastos, ['fecha', 'created_at', 'updated_at']);
        $montoGastoCol = firstColumn($colsGastos, ['monto', 'total']);
        $conceptoGastoCol = firstColumn($colsGastos, ['concepto', 'rubro', 'descripcion']);
        if ($fechaGastoCol && $montoGastoCol && $conceptoGastoCol) {
            $sqlGastos = "
                SELECT
                    COALESCE(NULLIF(TRIM(g.{$conceptoGastoCol}), ''), 'Sin rubro') AS rubro,
                    COUNT(*) AS cantidad,
                    COALESCE(SUM(g.{$montoGastoCol}), 0) AS monto
                FROM {$dbName}.gastos_empresa g
                WHERE DATE(g.{$fechaGastoCol}) BETWEEN :desde AND :hasta
                  AND UPPER(TRIM(COALESCE(g.estado, 'ACTIVO'))) = 'ACTIVO'
                GROUP BY COALESCE(NULLIF(TRIM(g.{$conceptoGastoCol}), ''), 'Sin rubro')
                ORDER BY monto DESC, cantidad DESC, rubro ASC
                LIMIT 8
            ";
            $stmtGastos = $pdo->prepare($sqlGastos);
            $stmtGastos->execute([':desde' => $desde, ':hasta' => $hasta]);
            $gastosRubro = $stmtGastos->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($gastosRubro as $gastoItem) {
                $gastosTotalRango += (float)($gastoItem['monto'] ?? 0);
            }
        }
    }

    if (tableExists($pdo, $dbName, 'factura_compras')) {
        $colsFc = getColumns($pdo, $dbName, 'factura_compras');
        $fechaCompraCol = firstColumn($colsFc, ['fecha', 'created_at', 'updated_at']);
        $pIvaCompra10Col = firstColumn($colsFc, ['piva10', 'piva_10']);
        $pIvaCompra5Col = firstColumn($colsFc, ['piva5', 'piva_5']);
        $ivaCompra10Col = firstColumn($colsFc, ['iva10', 'iva_10']);
        $ivaCompra5Col = firstColumn($colsFc, ['iva5', 'iva_5']);
        $estadoCompraCol = firstColumn($colsFc, ['estado']);
        if ($fechaCompraCol && ($pIvaCompra10Col || $pIvaCompra5Col || $ivaCompra10Col || $ivaCompra5Col)) {
            $whereCompras = ["DATE(fc.{$fechaCompraCol}) BETWEEN :desde AND :hasta"];
            if ($estadoCompraCol) {
                $whereCompras[] = "(
                    fc.{$estadoCompraCol} = 1 OR
                    fc.{$estadoCompraCol} = '1' OR
                    LOWER(TRIM(CAST(fc.{$estadoCompraCol} AS CHAR))) IN ('activo','activa')
                )";
            }
            $taxCompraParts = [];
            if ($pIvaCompra10Col) $taxCompraParts[] = "COALESCE(fc.{$pIvaCompra10Col}, 0)";
            if ($pIvaCompra5Col) $taxCompraParts[] = "COALESCE(fc.{$pIvaCompra5Col}, 0)";
            if (empty($taxCompraParts)) {
                if ($ivaCompra10Col) $taxCompraParts[] = "(COALESCE(fc.{$ivaCompra10Col}, 0) / 11)";
                if ($ivaCompra5Col) $taxCompraParts[] = "(COALESCE(fc.{$ivaCompra5Col}, 0) / 21)";
            }
            $sqlTaxCompras = "
                SELECT COALESCE(SUM(" . implode(' + ', $taxCompraParts) . "), 0) AS impuestos_compras
                FROM {$dbName}.factura_compras fc
                WHERE " . implode(' AND ', $whereCompras) . "
            ";
            $stmtTaxCompras = $pdo->prepare($sqlTaxCompras);
            $stmtTaxCompras->execute([':desde' => $desde, ':hasta' => $hasta]);
            $impuestosComprasRango = (float)$stmtTaxCompras->fetchColumn();
        }
    }

    $joinCliente = '';
    $selCliente = "'Sin cliente' AS cliente";
    if (tableExists($pdo, $dbName, 'clientes')) {
        $colFvIdCli = in_array('id_cliente', $colsFv, true) ? 'id_cliente' : (in_array('idcliente', $colsFv, true) ? 'idcliente' : null);
        if ($colFvIdCli !== null) {
            $joinCliente = " LEFT JOIN {$dbName}.clientes c ON c.id = fv.{$colFvIdCli} ";
            $selCliente = "COALESCE(NULLIF(TRIM(c.nombre),''),'Sin cliente') AS cliente";
        }
    }

    $selNro = $hasNro ? "fv.nro_factura" : ($hasIdFactura ? "CONCAT('#', fv.id_factura)" : "'-'");
    $selId = $hasIdFactura ? 'fv.id_factura' : '0 AS id_factura';

    $sqlUlt = "
        SELECT {$selId}, {$selNro} AS nro_factura, fv.fecha, fv.total,
               " . ($hasFormaPago ? "fv.forma_pago" : "NULL AS forma_pago") . ",
               {$selCliente}
        FROM {$dbName}.factura_ventas fv
        {$joinCliente}
        WHERE DATE(fv.fecha) BETWEEN :desde AND :hasta
          AND {$activeExpr}
        ORDER BY " . ($hasIdFactura ? 'fv.id_factura DESC' : 'fv.fecha DESC') . "
        LIMIT 20
    ";
    $stmtUlt = $pdo->prepare($sqlUlt);
    $stmtUlt->execute([':desde' => $desde, ':hasta' => $hasta]);
    $ultimasVentas = $stmtUlt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (
        tableExists($pdo, $dbName, 'extracto_productos') &&
        tableExists($pdo, $dbName, 'tblproductos')
    ) {
        $colsEp = getColumns($pdo, $dbName, 'extracto_productos');
        $hasEpIdFact = in_array('idfactura', $colsEp, true);
        $hasEpIdProd = in_array('idproducto', $colsEp, true);
        $hasEpSalida = in_array('salida', $colsEp, true);
        $hasEpDesc = in_array('descripcion', $colsEp, true);
        $hasEpDescripcio = in_array('descripcio', $colsEp, true);
        $colsProd = getColumns($pdo, $dbName, 'tblproductos');
        $hasDesc = in_array('descripcion', $colsProd, true);
        $hasDesProducto = in_array('desproducto', $colsProd, true);
        $hasCve = in_array('cve_producto', $colsProd, true);
        $prodDescLargaCol = in_array('descripcion_larga', $colsProd, true) ? 'descripcion_larga' : null;
        $prodFotoCol = in_array('foto', $colsProd, true) ? 'foto' : (in_array('imagen', $colsProd, true) ? 'imagen' : null);

        if ($hasEpIdFact && $hasEpIdProd && $hasEpSalida) {
            $descParts = [];
            if ($hasDesc) $descParts[] = "MAX(NULLIF(TRIM(p.descripcion), ''))";
            if ($hasDesProducto) $descParts[] = "MAX(NULLIF(TRIM(p.desproducto), ''))";
            if ($hasEpDesc) $descParts[] = "MAX(NULLIF(TRIM(ep.descripcion), ''))";
            if ($hasEpDescripcio) $descParts[] = "MAX(NULLIF(TRIM(ep.descripcio), ''))";
            $descExpr = !empty($descParts)
                ? "COALESCE(" . implode(', ', $descParts) . ", CONCAT('Producto ', p.idproducto))"
                : "CONCAT('Producto ', p.idproducto)";
            $descLargaExpr = $prodDescLargaCol ? "MAX(NULLIF(TRIM(p.{$prodDescLargaCol}), ''))" : "NULL";
            $fotoExpr = $prodFotoCol ? "MAX(NULLIF(TRIM(p.{$prodFotoCol}), ''))" : "NULL";
            $cveExpr = $hasCve ? "MAX(p.cve_producto)" : "NULL";
            $fotoJoinExpr = $hasCve
                ? "(mf.codigo = p.cve_producto OR mf.codigo = p.idproducto)"
                : "(mf.codigo = p.idproducto)";
            $sqlTop = "
                SELECT
                    p.idproducto,
                    {$cveExpr} AS cve_producto,
                    {$descExpr} AS producto,
                    " . ($hasDesc ? "MAX(NULLIF(TRIM(p.descripcion), ''))" : "NULL") . " AS descripcion_base,
                    " . ($hasDesProducto ? "MAX(NULLIF(TRIM(p.desproducto), ''))" : "NULL") . " AS desproducto_base,
                    {$descLargaExpr} AS descripcion_larga,
                    {$fotoExpr} AS foto_producto,
                    (SELECT NULLIF(TRIM(mf.foto), '') FROM {$dbName}.mercaderia_foto mf WHERE {$fotoJoinExpr} LIMIT 1) AS foto_mercaderia,
                    SUM(ep.salida) AS unidades,
                    SUM(ep.salida * COALESCE(NULLIF(p.precio_venta,0),0)) AS monto
                FROM {$dbName}.extracto_productos ep
                INNER JOIN {$dbName}.tblproductos p ON p.idproducto = ep.idproducto
                INNER JOIN {$dbName}.factura_ventas fv ON fv.id_factura = ep.idfactura
                WHERE ep.salida > 0
                  AND DATE(fv.fecha) BETWEEN :desde AND :hasta
                  AND {$activeExpr}
                GROUP BY p.idproducto
                ORDER BY unidades DESC
                LIMIT 8
            ";
            $stmtTop = $pdo->prepare($sqlTop);
            $stmtTop->execute([':desde' => $desde, ':hasta' => $hasta]);
            $topProductos = $stmtTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($topProductos as &$tp) {
                $foto = trim((string)($tp['foto_mercaderia'] ?? ''));
                if ($foto === '') {
                    $foto = trim((string)($tp['foto_producto'] ?? ''));
                }
                if ($foto !== '' && !preg_match('/^https?:\\/\\//i', $foto) && strpos($foto, '/public/') !== 0) {
                    $foto = '/public/_lib/file/img/productos/' . $dbName . '/' . ltrim($foto, '/');
                }
                if ($foto === '') {
                    $foto = '/public/_lib/file/img/empresa/logo_sistemax.png';
                }
                $tp['foto_url'] = $foto;
                $descCorta = trim((string)($tp['descripcion_larga'] ?? ''));
                if ($descCorta === '') $descCorta = trim((string)($tp['descripcion_base'] ?? ''));
                if ($descCorta === '') $descCorta = trim((string)($tp['desproducto_base'] ?? ''));
                if ($descCorta === '' && !empty($tp['cve_producto'])) $descCorta = 'Codigo: ' . (string)$tp['cve_producto'];
                $tp['descripcion_corta'] = $descCorta;
                $proxyQ = rawurlencode((string)($tp['producto'] ?? 'producto'));
                $tp['foto_proxy_url'] = "/public/pos/api/imagen_proxy.php?id=" . (int)$tp['idproducto'] . "&q={$proxyQ}";
            }
            unset($tp);
        }
    }

    if (($_GET['export'] ?? '') === 'ventas') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ventas_' . $dbName . '_' . $desde . '_' . $hasta . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Nro Factura', 'Fecha', 'Cliente', 'Forma Pago', 'Total']);

        $mapPago = ['1' => 'Contado / Efectivo', '2' => 'Tarjeta', '3' => 'Transferencia', '4' => 'QR', '5' => 'Credito'];
        foreach ($ultimasVentas as $v) {
            $fp = (string)($v['forma_pago'] ?? '');
            fputcsv($out, [
                $v['id_factura'] ?? '',
                $v['nro_factura'] ?? '',
                $v['fecha'] ?? '',
                $v['cliente'] ?? 'Sin cliente',
                $mapPago[$fp] ?? $fp,
                (float)($v['total'] ?? 0),
            ]);
        }
        fclose($out);
        exit;
    }

} catch (Throwable $e) {
    $errorPanel = $e->getMessage();
}

$deltaMonto = $panel['monto_cmp'] > 0
    ? (($panel['monto'] - $panel['monto_cmp']) / $panel['monto_cmp']) * 100
    : ($panel['monto'] > 0 ? 100 : 0);

$deltaVentas = $panel['ventas_cmp'] > 0
    ? (($panel['ventas'] - $panel['ventas_cmp']) / $panel['ventas_cmp']) * 100
    : ($panel['ventas'] > 0 ? 100 : 0);
$gananciaReal = $panel['monto'] - $costoVentaTotalRango - $gastosTotalRango - $impuestosVentasRango - $impuestosComprasRango;

$paramsBase = [
    'periodo' => $periodo,
    'desde' => $desde,
    'hasta' => $hasta,
];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel Tienda Fisica</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --panel-bg: #f3f6fb;
      --panel-text: #1f2937;
      --panel-card: #ffffff;
      --panel-border: #e5e7eb;
      --panel-muted: #64748b;
      --panel-table-head: #f8fafc;
      --panel-input-bg: #ffffff;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --panel-bg: #020617;
        --panel-text: #e2e8f0;
        --panel-card: #0f172a;
        --panel-border: #1e293b;
        --panel-muted: #94a3b8;
        --panel-table-head: #111827;
        --panel-input-bg: #0b1220;
      }
    }
    body { background: var(--panel-bg); color: var(--panel-text); }
    .panel-card { background:var(--panel-card); border:1px solid var(--panel-border); border-radius:14px; box-shadow:0 4px 12px rgba(15,23,42,.08); }
    .kpi-sub, .text-secondary, .small.text-secondary { color:var(--panel-muted) !important; }
    .kpi-value { font-size:1.6rem; font-weight:700; color: var(--panel-text); }
    .table { color: var(--panel-text); }
    .table > :not(caption) > * > * { background: transparent; border-bottom-color: var(--panel-border); color: inherit; }
    .table-light, .table-light > th, .table-light > td { --bs-table-bg: var(--panel-table-head); --bs-table-color: var(--panel-text); }
    .form-control, .form-select { background: var(--panel-input-bg); color: var(--panel-text); border-color: var(--panel-border); }
    .form-control:focus, .form-select:focus { background: var(--panel-input-bg); color: var(--panel-text); border-color:#3b82f6; box-shadow:0 0 0 .2rem rgba(59,130,246,.2); }
    .border-top, .border-bottom { border-color: var(--panel-border) !important; }
  </style>
</head>
<body>
  <div class="container-fluid py-3 py-lg-4 px-3 px-lg-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          onclick="salirApp()"
          class="inline-flex items-center justify-center w-11 h-11 rounded-xl border border-red-200/70 bg-red-50 text-red-600 hover:bg-red-100 hover:text-red-700 transition"
          title="Salir">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M7.5 3.75A2.25 2.25 0 0 0 5.25 6v12a2.25 2.25 0 0 0 2.25 2.25h3a.75.75 0 0 0 0-1.5h-3A.75.75 0 0 1 6.75 18V6a.75.75 0 0 1 .75-.75h3a.75.75 0 0 0 0-1.5h-3Zm11.03 4.22a.75.75 0 0 1 0 1.06L16.31 11.25H9.75a.75.75 0 0 0 0 1.5h6.56l2.22 2.22a.75.75 0 1 1-1.06 1.06l-3.5-3.5a.75.75 0 0 1 0-1.06l3.5-3.5a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
          </svg>
        </button>
        <div>
        <h1 class="h4 fw-bold mb-0">Panel de Tienda Fisica</h1>
        <small class="text-secondary"><?php echo h($empresaNombre); ?> | DB: <?php echo h($empresaDb !== '' ? $empresaDb : $dbName); ?></small>
        </div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-success btn-sm" href="?<?php echo h(http_build_query(array_merge($paramsBase, ['export' => 'ventas']))); ?>">Exportar CSV</a>
      </div>
    </div>

    <div class="panel-card p-3 mb-3">
      <div class="row g-3">
        <div class="col-12 col-md-4 col-xl-3">
          <div class="kpi-sub">Empresa</div>
          <div class="fw-semibold"><?php echo h($empresaNombre); ?></div>
          <div class="small text-secondary">ID: <?php echo (int)$idEmpresa; ?></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="kpi-sub">RUC</div>
          <div class="fw-semibold"><?php echo h($empresaRucFull !== '' ? $empresaRucFull : 'Sin dato'); ?></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="kpi-sub">Base</div>
          <div class="fw-semibold"><?php echo h($empresaDb !== '' ? $empresaDb : 'Sin dato'); ?></div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="kpi-sub">Servidor</div>
          <div class="fw-semibold"><?php echo h($empresaServer !== '' ? $empresaServer : 'Sin dato'); ?></div>
        </div>
        <div class="col-6 col-md-4 col-xl-1">
          <div class="kpi-sub">Usuario DB</div>
          <div class="fw-semibold"><?php echo h($empresaUserDb !== '' ? $empresaUserDb : 'Sin dato'); ?></div>
        </div>
        <div class="col-12 col-md-4 col-xl-2">
          <div class="kpi-sub">Moneda</div>
          <div class="fw-semibold"><?php echo h($empresaMoneda !== '' ? $empresaMoneda : 'Sin dato'); ?></div>
          <div class="small text-secondary">Usuario: <?php echo h($userName); ?></div>
        </div>
      </div>
    </div>

    <div class="panel-card p-3 mb-3">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Periodo</label>
          <select name="periodo" id="periodo" class="form-select form-select-sm" onchange="toggleCustom(this.value)">
            <option value="hoy" <?php echo $periodo === 'hoy' ? 'selected' : ''; ?>>Hoy</option>
            <option value="semana" <?php echo $periodo === 'semana' ? 'selected' : ''; ?>>Semana</option>
            <option value="mes" <?php echo $periodo === 'mes' ? 'selected' : ''; ?>>Mes</option>
            <option value="personalizado" <?php echo $periodo === 'personalizado' ? 'selected' : ''; ?>>Personalizado</option>
          </select>
        </div>
        <div class="col-6 col-md-3 custom-range" style="display:<?php echo $periodo === 'personalizado' ? 'block' : 'none'; ?>;">
          <label class="form-label mb-1">Desde</label>
          <input type="date" name="desde" value="<?php echo h($desde); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3 custom-range" style="display:<?php echo $periodo === 'personalizado' ? 'block' : 'none'; ?>;">
          <label class="form-label mb-1">Hasta</label>
          <input type="date" name="hasta" value="<?php echo h($hasta); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3">
          <button class="btn btn-primary btn-sm w-100" type="submit">Aplicar filtro</button>
        </div>
      </form>
      <div class="small text-secondary mt-2">
        Rango actual: <strong><?php echo h($desde); ?></strong> a <strong><?php echo h($hasta); ?></strong> |
        Comparativo: <strong><?php echo h($cmpDesde); ?></strong> a <strong><?php echo h($cmpHasta); ?></strong>
      </div>
    </div>

    <?php if ($errorPanel): ?>
      <div class="alert alert-danger">Error cargando panel: <?php echo h($errorPanel); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-12 col-xl-6">
        <div class="panel-card p-3 h-100">
          <h2 class="h4 text-primary fw-bold mb-1">Buen dia, <?php echo h($userName); ?>!</h2>
          <p class="text-secondary mb-3">Resumen real del rango seleccionado.</p>
          <div class="row g-3">
            <div class="col-6"><div class="kpi-sub">Ventas</div><div class="kpi-value"><?php echo gs($panel['ventas']); ?></div></div>
            <div class="col-6"><div class="kpi-sub">Monto</div><div class="kpi-value"><?php echo money($panel['monto']); ?> Gs</div></div>
            <div class="col-6"><div class="kpi-sub">Ticket promedio</div><div class="kpi-value"><?php echo money($panel['ticket']); ?> Gs</div></div>
            <div class="col-6"><div class="kpi-sub">FE emitidas</div><div class="kpi-value"><?php echo gs($panel['sifen']); ?></div></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-xl-6">
        <div class="panel-card p-3 h-100">
          <div class="row g-3">
            <div class="col-6">
              <div class="kpi-sub">Comparativo monto</div>
              <div class="h3 mb-0 <?php echo $deltaMonto >= 0 ? 'text-success' : 'text-danger'; ?>">
                <?php echo ($deltaMonto >= 0 ? '+' : '') . number_format($deltaMonto, 1, ',', '.'); ?>%
              </div>
            </div>
            <div class="col-6">
              <div class="kpi-sub">Comparativo ventas</div>
              <div class="h3 mb-0 <?php echo $deltaVentas >= 0 ? 'text-success' : 'text-danger'; ?>">
                <?php echo ($deltaVentas >= 0 ? '+' : '') . number_format($deltaVentas, 1, ',', '.'); ?>%
              </div>
            </div>
            <div class="col-6">
              <div class="kpi-sub">Monto comparativo</div>
              <div class="h5 mb-0"><?php echo money($panel['monto_cmp']); ?> Gs</div>
            </div>
            <div class="col-6">
              <div class="kpi-sub">Ventas comparativas</div>
              <div class="h5 mb-0"><?php echo gs($panel['ventas_cmp']); ?></div>
            </div>
            <div class="col-12 pt-2 border-top">
              <span class="badge text-bg-warning">Anuladas: <?php echo gs($panel['anuladas']); ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-12">
        <div class="panel-card p-3">
          <div class="row g-3 align-items-center">
            <div class="col-12 col-md-3">
              <div class="kpi-sub">Ingresos</div>
              <div class="kpi-value text-primary"><?php echo money($panel['monto']); ?> Gs</div>
            </div>
            <div class="col-12 col-md-3">
              <div class="kpi-sub">Gastos</div>
              <div class="kpi-value text-danger"><?php echo money($gastosTotalRango); ?> Gs</div>
            </div>
            <div class="col-12 col-md-3">
              <div class="kpi-sub">Costo de venta</div>
              <div class="kpi-value text-warning"><?php echo money($costoVentaTotalRango); ?> Gs</div>
            </div>
            <div class="col-12 col-md-3">
              <div class="kpi-sub">Impuestos</div>
              <div class="kpi-value text-warning"><?php echo money($impuestosVentasRango + $impuestosComprasRango); ?> Gs</div>
              <div class="small text-secondary mt-2">
                Ventas: <?php echo money($impuestosVentasRango); ?> | Compras: <?php echo money($impuestosComprasRango); ?>
              </div>
            </div>
            <div class="col-12">
              <div class="kpi-sub">Ganancia Real</div>
              <div class="kpi-value <?php echo $gananciaReal >= 0 ? 'text-success' : 'text-danger'; ?>">
                <?php echo money($gananciaReal); ?> Gs
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <div class="panel-card p-3 h-100">
          <div class="kpi-sub">Cuentas a Cobrar</div>
          <div class="kpi-value"><?php echo money($cuentasCobrar['monto']); ?> Gs</div>
          <div class="small text-secondary mt-2"><?php echo gs($cuentasCobrar['cantidad']); ?> documento(s) pendientes</div>
        </div>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <div class="panel-card p-3 h-100">
          <div class="kpi-sub">Cuentas vencidas</div>
          <div class="kpi-value text-danger"><?php echo money($cuentasCobrar['vencidas_monto']); ?> Gs</div>
          <div class="small text-secondary mt-2"><?php echo gs($cuentasCobrar['vencidas_cantidad']); ?> documento(s) vencidos</div>
        </div>
      </div>
      <div class="col-12 col-xl-6">
        <div class="panel-card p-3 h-100">
          <div class="fw-bold mb-2">Gastos por rubro</div>
          <?php if (empty($gastosRubro)): ?>
            <div class="text-secondary small">Sin gastos registrados en el rango seleccionado.</div>
          <?php else: ?>
            <?php foreach ($gastosRubro as $g): ?>
              <div class="d-flex justify-content-between align-items-center border-bottom py-2 small gap-3">
                <div>
                  <div class="fw-semibold"><?php echo h($g['rubro'] ?? 'Sin rubro'); ?></div>
                  <div class="text-secondary"><?php echo gs($g['cantidad'] ?? 0); ?> movimiento(s)</div>
                </div>
                <div class="fw-semibold text-end"><?php echo money($g['monto'] ?? 0); ?> Gs</div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-12 col-lg-8">
        <div class="panel-card p-3">
          <div class="fw-bold mb-2">Ventas, costo y resultado</div>
          <canvas id="chartVentas" height="100"></canvas>
        </div>
      </div>
      <div class="col-12 col-lg-4">
        <div class="panel-card p-3 h-100">
          <div class="fw-bold mb-2">Metodos de pago</div>
          <?php if (empty($metodosPago)): ?>
            <div class="text-secondary small">Sin datos de forma de pago.</div>
          <?php else: ?>
            <?php foreach ($metodosPago as $m): ?>
              <div class="d-flex justify-content-between border-bottom py-2 small">
                <span><?php echo h($m['label']); ?></span>
                <span class="fw-semibold"><?php echo money($m['monto']); ?> Gs</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-12 col-lg-8">
        <div class="panel-card p-3 h-100">
          <div class="fw-bold mb-2">Ultimas ventas del rango</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Nro</th>
                  <th>Fecha</th>
                  <th>Cliente</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ultimasVentas)): ?>
                  <tr><td colspan="5" class="text-center text-secondary">Sin ventas registradas.</td></tr>
                <?php else: ?>
                  <?php foreach ($ultimasVentas as $v): ?>
                    <tr>
                      <td><?php echo h($v['id_factura'] ?? ''); ?></td>
                      <td><?php echo h($v['nro_factura'] ?? ''); ?></td>
                      <td><?php echo h(isset($v['fecha']) ? date('d/m/Y H:i', strtotime((string)$v['fecha'])) : ''); ?></td>
                      <td><?php echo h($v['cliente'] ?? 'Sin cliente'); ?></td>
                      <td class="text-end fw-semibold"><?php echo money($v['total'] ?? 0); ?> Gs</td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-4">
        <div class="panel-card p-3 h-100">
          <div class="fw-bold mb-2">Top productos</div>
          <?php if (empty($topProductos)): ?>
            <div class="text-secondary small">Sin datos de productos.</div>
          <?php else: ?>
	            <div class="table-responsive">
	              <table class="table table-sm">
	                <thead><tr><th>Producto</th><th class="text-end">Unid.</th></tr></thead>
	                <tbody>
	                  <?php foreach ($topProductos as $p): ?>
	                    <tr>
	                      <td>
                            <div class="d-flex align-items-start gap-2">
                              <img src="<?php echo h($p['foto_proxy_url'] ?? ($p['foto_url'] ?? '/public/_lib/file/img/empresa/logo_sistemax.png')); ?>"
                                   alt="Producto"
                                   style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;"
                                   onerror="this.onerror=null; this.src='<?php echo h($p['foto_url'] ?? '/public/_lib/file/img/empresa/logo_sistemax.png'); ?>'; if (this.src.endsWith('/public/_lib/file/img/empresa/logo_sistemax.png')) { this.onerror=null; }">
                              <div>
                                <div class="fw-semibold"><?php echo h($p['producto'] ?? ''); ?></div>
                                <?php if (!empty($p['descripcion_corta'])): ?>
                                  <div class="text-secondary small"><?php echo h(shortText((string)$p['descripcion_corta'], 70)); ?></div>
                                <?php else: ?>
                                  <div class="text-secondary small">Sin descripcion</div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </td>
	                      <td class="text-end fw-semibold"><?php echo gs($p['unidades'] ?? 0); ?></td>
	                    </tr>
	                  <?php endforeach; ?>
	                </tbody>
	              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    function salirApp() {
      try {
        if (window.parent && window.parent !== window && typeof window.parent.cerrarApp === 'function') {
          window.parent.cerrarApp();
          setTimeout(() => {
            try {
              if (window.top && window.top.location) {
                window.top.location.href = '/public/menu/menu.php';
              }
            } catch (e) {
              window.location.href = '/public/menu/menu.php';
            }
          }, 500);
          return;
        }
      } catch (e) {}
      try {
        if (window.top && window.top !== window) {
          window.top.location.href = '/public/menu/menu.php';
          return;
        }
      } catch (e) {}
      window.location.href = '/public/menu/menu.php';
    }

    function toggleCustom(val) {
      document.querySelectorAll('.custom-range').forEach(el => {
        el.style.display = (val === 'personalizado') ? 'block' : 'none';
      });
    }

    const labels = <?php echo json_encode($serieDiasLabels, JSON_UNESCAPED_UNICODE); ?>;
    const montos = <?php echo json_encode($serieDiasTotales, JSON_UNESCAPED_UNICODE); ?>;
    const cantidades = <?php echo json_encode($serieDiasCant, JSON_UNESCAPED_UNICODE); ?>;
    const costos = <?php echo json_encode($serieDiasCosto, JSON_UNESCAPED_UNICODE); ?>;
    const resultados = <?php echo json_encode($serieDiasResultado, JSON_UNESCAPED_UNICODE); ?>;

    const ctx = document.getElementById('chartVentas');
    const isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const gridColor = isDark ? 'rgba(148,163,184,0.16)' : 'rgba(15,23,42,0.08)';
    const tickColor = isDark ? '#cbd5e1' : '#475569';
    const legendColor = isDark ? '#e2e8f0' : '#1f2937';
    if (ctx) {
      new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label: 'Venta (Gs)', data: montos, borderColor: '#2c7be5', backgroundColor: 'rgba(44,123,229,.12)', fill: true, tension: .3, yAxisID: 'y' },
            { label: 'Costo de venta (Gs)', data: costos, borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.1)', fill: true, tension: .3, yAxisID: 'y' },
            { label: 'Resultado (Gs)', data: resultados, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.12)', fill: false, tension: .3, yAxisID: 'y' },
            { label: 'Cantidad', data: cantidades, borderColor: '#fd7e14', backgroundColor: 'rgba(253,126,20,.12)', fill: false, tension: .3, yAxisID: 'y1', hidden: true }
          ]
        },
        options: {
          responsive: true,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'top', labels: { color: legendColor } } },
          scales: {
            x: { ticks: { color: tickColor }, grid: { color: gridColor } },
            y: {
              type: 'linear',
              position: 'left',
              ticks: { color: tickColor, callback: v => new Intl.NumberFormat('es-PY').format(v) },
              grid: { color: gridColor }
            },
            y1: {
              type: 'linear',
              position: 'right',
              ticks: { color: tickColor },
              grid: { drawOnChartArea: false, color: gridColor }
            }
          }
        }
      });
    }
  </script>
</body>
</html>
