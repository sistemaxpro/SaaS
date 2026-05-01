<?php

require_once __DIR__ . '/common.php';

try {
    [$pdo, $db] = smxAlqDb();
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $rows = $pdo->query("
            SELECT
                a.*,
                c.numero_contrato,
                p.nombre AS propiedad,
                i.nombre_razon
            FROM `{$db}`.`alq_avisos` a
            INNER JOIN `{$db}`.`alq_contratos` c ON c.id_contrato = a.id_contrato
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
            INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
            ORDER BY a.programado_para DESC, a.id_aviso DESC
            LIMIT 150
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $facturas = $pdo->query("
            SELECT
                f.id_factura,
                f.periodo,
                f.fecha_vencimiento,
                f.total,
                c.id_contrato,
                c.numero_contrato,
                c.moneda,
                p.nombre AS propiedad,
                i.nombre_razon,
                i.whatsapp,
                i.telefono,
                i.consentimiento_whatsapp
            FROM `{$db}`.`alq_facturas` f
            INNER JOIN `{$db}`.`alq_contratos` c ON c.id_contrato = f.id_contrato
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
            INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
            WHERE f.estado IN ('pendiente', 'vencida')
            ORDER BY f.fecha_vencimiento ASC
            LIMIT 60
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        smxAlqJson(['ok' => true, 'rows' => $rows, 'facturas' => $facturas]);
    }

    if ($action === 'schedule') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idFactura = (int)($payload['id_factura'] ?? 0);
        $daysBefore = (int)($payload['dias_antes'] ?? 3);
        $stmt = $pdo->prepare("
            SELECT
                f.*,
                c.id_contrato,
                c.numero_contrato,
                c.moneda,
                p.nombre,
                i.nombre_razon,
                i.whatsapp,
                i.telefono,
                i.consentimiento_whatsapp
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
        $res = smxAlqScheduleReminder($pdo, $db, $row, $row, $daysBefore);
        smxAlqJson(['ok' => !empty($res['success']), 'result' => $res], !empty($res['success']) ? 200 : 422);
    }

    if ($action === 'send_now') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idAviso = (int)($payload['id_aviso'] ?? 0);
        $res = smxAlqDispatchReminder($pdo, $db, $idAviso);
        smxAlqJson(['ok' => !empty($res['success']), 'result' => $res], !empty($res['success']) ? 200 : 422);
    }

    smxAlqJson(['ok' => false, 'error' => 'Acción no soportada'], 400);
} catch (Throwable $e) {
    smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
