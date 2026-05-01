<?php

require_once __DIR__ . '/common.php';

try {
    [$pdo, $db] = smxAlqDb();
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $rows = $pdo->query("
            SELECT
                f.*,
                c.numero_contrato,
                c.moneda,
                p.nombre AS propiedad,
                i.nombre_razon,
                i.ruc,
                i.documento
            FROM `{$db}`.`alq_facturas` f
            INNER JOIN `{$db}`.`alq_contratos` c ON c.id_contrato = f.id_contrato
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
            INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
            ORDER BY f.fecha_vencimiento DESC, f.id_factura DESC
            LIMIT 120
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmtG = $pdo->prepare("
            SELECT id_gasto, concepto, monto, categoria
            FROM `{$db}`.`alq_gastos_propiedad`
            WHERE id_factura = ?
            ORDER BY fecha_gasto ASC, id_gasto ASC
        ");
        foreach ($rows as &$row) {
            $stmtG->execute([(int)($row['id_factura'] ?? 0)]);
            $gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $row['gastos_json'] = json_encode($gastos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row['monto_gastos'] = array_reduce($gastos, static fn($sum, $g) => $sum + (float)($g['monto'] ?? 0), 0.0);
        }
        unset($row);
        smxAlqJson(['ok' => true, 'rows' => $rows]);
    }

    if ($action === 'generate_month') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $period = trim((string)($payload['periodo'] ?? date('Y-m')));
        $contracts = $pdo->query("
            SELECT c.*, p.nombre, p.codigo, p.direccion, p.tipo_inmueble, i.nombre_razon, i.documento, i.ruc, i.telefono, i.email
            FROM `{$db}`.`alq_contratos` c
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
            INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
            WHERE c.estado = 'activo'
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $created = [];
        foreach ($contracts as $contract) {
            $invoice = smxAlqCreateInvoice($pdo, $db, $contract, $period);
            if ($invoice) {
                $created[] = $invoice;
            }
        }
        smxAlqJson(['ok' => true, 'generated' => count($created), 'rows' => $created]);
    }

    if ($action === 'prepare_fe') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idFactura = (int)($payload['id_factura'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT
                f.*,
                c.numero_contrato,
                c.moneda,
                p.codigo,
                p.nombre,
                p.direccion,
                p.tipo_inmueble,
                i.nombre_razon,
                i.documento,
                i.ruc,
                i.telefono,
                i.email
            FROM `{$db}`.`alq_facturas` f
            INNER JOIN `{$db}`.`alq_contratos` c ON c.id_contrato = f.id_contrato
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
            INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
            WHERE f.id_factura = ?
            LIMIT 1
        ");
        $stmt->execute([$idFactura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            smxAlqJson(['ok' => false, 'error' => 'Factura no encontrada'], 404);
        }

        $stmtG = $pdo->prepare("
            SELECT id_gasto, concepto, monto, categoria
            FROM `{$db}`.`alq_gastos_propiedad`
            WHERE id_factura = ?
            ORDER BY fecha_gasto ASC, id_gasto ASC
        ");
        $stmtG->execute([$idFactura]);
        $row['gastos_json'] = json_encode($stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $payloadFe = smxAlqBuildFePayload($row, $row);
        $upd = $pdo->prepare("
            UPDATE `{$db}`.`alq_facturas`
            SET fe_estado = 'preparada', fe_payload_json = ?
            WHERE id_factura = ?
        ");
        $upd->execute([json_encode($payloadFe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $idFactura]);

        smxAlqJson(['ok' => true, 'payload' => $payloadFe]);
    }

    if ($action === 'mark_paid') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idFactura = (int)($payload['id_factura'] ?? 0);
        $stmt = $pdo->prepare("UPDATE `{$db}`.`alq_facturas` SET estado = 'pagada' WHERE id_factura = ?");
        $stmt->execute([$idFactura]);
        smxAlqJson(['ok' => true]);
    }

    smxAlqJson(['ok' => false, 'error' => 'Acción no soportada'], 400);
} catch (Throwable $e) {
    smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
