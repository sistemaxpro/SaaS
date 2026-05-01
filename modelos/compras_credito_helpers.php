<?php

function sxComprasCreditoTableExists(PDO $pdo, string $db, string $table): bool
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

function sxComprasCreditoColumnExists(PDO $pdo, string $db, string $table, string $column): bool
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

function sxComprasCreditoEnsureDocumentosCompraTable(PDO $pdo, string $db): void
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

function sxComprasCreditoFetchExtractoColumns(PDO $pdo, string $db): array
{
    if (!sxComprasCreditoTableExists($pdo, $db, 'extracto_cliente')) {
        return [];
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM {$db}.extracto_cliente");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = strtolower((string)($row['Field'] ?? ''));
    }
    return $columns;
}

function sxComprasCreditoInsertSupplierPaymentLedger(
    PDO $pdo,
    string $db,
    int $idFactura,
    int $idProveedor,
    int $idUsuario,
    float $monto,
    string $formaPago,
    string $nroFactura = '',
    string $observacion = ''
): bool {
    if ($idFactura <= 0 || $idProveedor <= 0 || $monto <= 0) {
        return false;
    }

    $available = sxComprasCreditoFetchExtractoColumns($pdo, $db);
    if (!$available) {
        return false;
    }

    $concepto = 'Pago compra Fact. ' . trim($nroFactura !== '' ? $nroFactura : ('#' . $idFactura));
    $formaPago = strtoupper(trim($formaPago !== '' ? $formaPago : 'EFECTIVO'));
    if ($formaPago !== '') {
        $concepto .= ' - ' . $formaPago;
    }
    $observacion = trim($observacion);
    if ($observacion !== '') {
        $concepto .= ' | ' . $observacion;
    }

    $map = [
        'codigo' => $idProveedor,
        'concepto' => mb_substr($concepto, 0, 255),
        'debito' => 0,
        'credito' => $monto,
        'fecha' => '__NOW__',
        'login' => $idUsuario,
        'id_login' => $idUsuario,
        'estado' => 1,
        'id_factura' => $idFactura,
        'referencia' => 39,
        'tabla_relacion' => 'factura_compras',
        'id_relacion' => $idFactura,
        'medio_cobro' => $formaPago,
    ];

    $cols = [];
    $vals = [];
    $params = [];
    foreach ($map as $col => $value) {
        if (!in_array($col, $available, true)) {
            continue;
        }
        $cols[] = $col;
        if ($value === '__NOW__') {
            $vals[] = 'NOW()';
            continue;
        }
        $param = ':' . $col;
        $vals[] = $param;
        $params[$param] = $value;
    }

    if (!$cols) {
        return false;
    }

    $sql = "INSERT INTO {$db}.extracto_cliente (" . implode(', ', $cols) . ")
            VALUES (" . implode(', ', $vals) . ")";
    $pdo->prepare($sql)->execute($params);
    return true;
}

function sxComprasCreditoApplyPaymentToInstallments(PDO $pdo, string $db, int $idFactura, float $monto): array
{
    $monto = round($monto, 2);
    if ($idFactura <= 0 || $monto <= 0) {
        return ['applied' => 0.0, 'remaining' => $monto, 'installments_touched' => 0];
    }

    sxComprasCreditoEnsureDocumentosCompraTable($pdo, $db);

    $stmt = $pdo->prepare("
        SELECT id, pagado, pendiente
        FROM {$db}.documentos_compra
        WHERE id_factura = :id_factura
          AND estado = 1
          AND pendiente > 0
        ORDER BY COALESCE(fecha_vencimiento, fecha_emision, CURDATE()) ASC, cuota_numero ASC, id ASC
        FOR UPDATE
    ");
    $stmt->execute([':id_factura' => $idFactura]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $monto;
    $touched = 0;

    foreach ($docs as $doc) {
        if ($remaining <= 0) {
            break;
        }
        $pendiente = (float)$doc['pendiente'];
        if ($pendiente <= 0) {
            continue;
        }
        $aplicar = min($remaining, $pendiente);
        $nuevoPagado = round((float)$doc['pagado'] + $aplicar, 2);
        $nuevoPendiente = round(max(0, $pendiente - $aplicar), 2);
        $pdo->prepare("
            UPDATE {$db}.documentos_compra
            SET pagado = :pagado,
                pendiente = :pendiente
            WHERE id = :id
        ")->execute([
            ':pagado' => $nuevoPagado,
            ':pendiente' => $nuevoPendiente,
            ':id' => (int)$doc['id'],
        ]);
        $remaining = round($remaining - $aplicar, 2);
        $touched++;
    }

    return [
        'applied' => round($monto - $remaining, 2),
        'remaining' => $remaining,
        'installments_touched' => $touched,
    ];
}

function sxComprasCreditoReversePaymentOnInstallments(PDO $pdo, string $db, int $idFactura, float $monto): array
{
    $monto = round($monto, 2);
    if ($idFactura <= 0 || $monto <= 0 || !sxComprasCreditoTableExists($pdo, $db, 'documentos_compra')) {
        return ['reversed' => 0.0, 'remaining' => $monto, 'installments_touched' => 0];
    }

    $stmt = $pdo->prepare("
        SELECT id, pagado, pendiente
        FROM {$db}.documentos_compra
        WHERE id_factura = :id_factura
          AND estado = 1
          AND pagado > 0
        ORDER BY COALESCE(fecha_vencimiento, fecha_emision, CURDATE()) DESC, cuota_numero DESC, id DESC
        FOR UPDATE
    ");
    $stmt->execute([':id_factura' => $idFactura]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $monto;
    $touched = 0;

    foreach ($docs as $doc) {
        if ($remaining <= 0) {
            break;
        }
        $pagado = (float)$doc['pagado'];
        if ($pagado <= 0) {
            continue;
        }
        $revertir = min($remaining, $pagado);
        $nuevoPagado = round(max(0, $pagado - $revertir), 2);
        $nuevoPendiente = round((float)$doc['pendiente'] + $revertir, 2);
        $pdo->prepare("
            UPDATE {$db}.documentos_compra
            SET pagado = :pagado,
                pendiente = :pendiente
            WHERE id = :id
        ")->execute([
            ':pagado' => $nuevoPagado,
            ':pendiente' => $nuevoPendiente,
            ':id' => (int)$doc['id'],
        ]);
        $remaining = round($remaining - $revertir, 2);
        $touched++;
    }

    return [
        'reversed' => round($monto - $remaining, 2),
        'remaining' => $remaining,
        'installments_touched' => $touched,
    ];
}

function sxComprasCreditoReverseSupplierPaymentLedger(PDO $pdo, string $db, int $idFactura, float $monto): bool
{
    $available = sxComprasCreditoFetchExtractoColumns($pdo, $db);
    if (!$available || !in_array('estado', $available, true)) {
        return false;
    }

    $where = [];
    $params = [];
    if (in_array('tabla_relacion', $available, true) && in_array('id_relacion', $available, true)) {
        $where[] = "tabla_relacion = 'factura_compras' AND id_relacion = :id_factura";
        $params[':id_factura'] = $idFactura;
    } elseif (in_array('id_factura', $available, true)) {
        $where[] = 'id_factura = :id_factura';
        $params[':id_factura'] = $idFactura;
    } else {
        return false;
    }

    if (in_array('credito', $available, true)) {
        $where[] = 'credito = :monto';
        $params[':monto'] = $monto;
    }

    $sqlId = "SELECT id FROM {$db}.extracto_cliente WHERE estado = 1 AND " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1";
    $stmtId = $pdo->prepare($sqlId);
    $stmtId->execute($params);
    $id = (int)$stmtId->fetchColumn();
    if ($id <= 0) {
        return false;
    }

    $pdo->prepare("UPDATE {$db}.extracto_cliente SET estado = 0 WHERE id = :id")->execute([':id' => $id]);
    return true;
}

function sxComprasCreditoSyncFacturaBalance(PDO $pdo, string $db, int $idFactura): array
{
    $stmt = $pdo->prepare("
        SELECT total, pagado, pendiente
        FROM {$db}.factura_compras
        WHERE id_factura = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $idFactura]);
    $factura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$factura) {
        return ['pagado' => 0.0, 'pendiente' => 0.0];
    }

    $total = round((float)$factura['total'], 2);
    $pagado = round((float)$factura['pagado'], 2);
    $pendiente = round((float)$factura['pendiente'], 2);

    if (sxComprasCreditoTableExists($pdo, $db, 'documentos_compra')) {
        $stmtDocs = $pdo->prepare("
            SELECT COUNT(*) AS qty, COALESCE(SUM(pendiente), 0) AS pendiente_total
            FROM {$db}.documentos_compra
            WHERE id_factura = :id
              AND estado = 1
        ");
        $stmtDocs->execute([':id' => $idFactura]);
        $agg = $stmtDocs->fetch(PDO::FETCH_ASSOC) ?: ['qty' => 0, 'pendiente_total' => 0];
        if ((int)($agg['qty'] ?? 0) > 0) {
            $pendiente = round(max(0, (float)$agg['pendiente_total']), 2);
            $pagado = round(max(0, $total - $pendiente), 2);
        } else {
            $pagado = round(min($total, max(0, $pagado)), 2);
            $pendiente = round(max(0, $total - $pagado), 2);
        }
    } else {
        $pagado = round(min($total, max(0, $pagado)), 2);
        $pendiente = round(max(0, $total - $pagado), 2);
    }

    $pdo->prepare("
        UPDATE {$db}.factura_compras
        SET pagado = :pagado,
            pendiente = :pendiente
        WHERE id_factura = :id
    ")->execute([
        ':pagado' => $pagado,
        ':pendiente' => $pendiente,
        ':id' => $idFactura,
    ]);

    return ['pagado' => $pagado, 'pendiente' => $pendiente];
}
