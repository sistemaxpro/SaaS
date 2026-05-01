<?php

require_once __DIR__ . '/common.php';

try {
    [$pdo, $db] = smxAlqDb();
    $action = $_GET['action'] ?? 'list';

    if ($action === 'catalogs') {
        $propiedades = $pdo->query("SELECT id_propiedad, codigo, nombre, moneda, estado FROM `{$db}`.`alq_propiedades` ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $contratos = $pdo->query("
            SELECT c.id_contrato, c.numero_contrato, c.id_propiedad, c.moneda, i.nombre_razon, p.nombre AS propiedad
            FROM `{$db}`.`alq_contratos` c
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
            INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
            WHERE c.estado = 'activo'
            ORDER BY c.numero_contrato DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        smxAlqJson(['ok' => true, 'propiedades' => $propiedades, 'contratos' => $contratos]);
    }

    if ($action === 'list') {
        $rows = $pdo->query("
            SELECT
                g.*,
                p.codigo,
                p.nombre AS propiedad,
                c.numero_contrato,
                f.periodo AS factura_periodo
            FROM `{$db}`.`alq_gastos_propiedad` g
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = g.id_propiedad
            LEFT JOIN `{$db}`.`alq_contratos` c ON c.id_contrato = g.id_contrato
            LEFT JOIN `{$db}`.`alq_facturas` f ON f.id_factura = g.id_factura
            ORDER BY g.fecha_gasto DESC, g.id_gasto DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stats = $pdo->query("
            SELECT
                COUNT(*) AS cantidad,
                COALESCE(SUM(monto),0) AS monto_total,
                COALESCE(SUM(CASE WHEN trasladar_inquilino = 1 AND estado_facturacion = 'pendiente' THEN monto ELSE 0 END),0) AS pendiente_traslado
            FROM `{$db}`.`alq_gastos_propiedad`
        ")->fetch(PDO::FETCH_ASSOC) ?: ['cantidad' => 0, 'monto_total' => 0, 'pendiente_traslado' => 0];
        smxAlqJson(['ok' => true, 'rows' => $rows, 'stats' => $stats]);
    }

    if ($action === 'save') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['id_gasto'] ?? 0);
        $data = [
            'id_propiedad' => (int)($payload['id_propiedad'] ?? 0),
            'id_contrato' => (($payload['id_contrato'] ?? '') === '' ? null : (int)$payload['id_contrato']),
            'fecha_gasto' => trim((string)($payload['fecha_gasto'] ?? date('Y-m-d'))),
            'periodo_aplicable' => trim((string)($payload['periodo_aplicable'] ?? date('Y-m'))),
            'categoria' => trim((string)($payload['categoria'] ?? 'mantenimiento')),
            'concepto' => trim((string)($payload['concepto'] ?? '')),
            'proveedor' => trim((string)($payload['proveedor'] ?? '')),
            'comprobante' => trim((string)($payload['comprobante'] ?? '')),
            'moneda' => strtoupper(trim((string)($payload['moneda'] ?? 'PYG'))),
            'monto' => (float)($payload['monto'] ?? 0),
            'pagado_por' => trim((string)($payload['pagado_por'] ?? 'propietario')),
            'trasladar_inquilino' => (int)($payload['trasladar_inquilino'] ?? 0) === 1 ? 1 : 0,
            'estado_facturacion' => trim((string)($payload['estado_facturacion'] ?? 'pendiente')),
            'observacion' => trim((string)($payload['observacion'] ?? '')),
        ];
        if ($data['id_propiedad'] <= 0 || $data['concepto'] === '' || $data['monto'] <= 0) {
            smxAlqJson(['ok' => false, 'error' => 'Propiedad, concepto y monto son obligatorios'], 422);
        }

        if (!$data['trasladar_inquilino']) {
            $data['estado_facturacion'] = 'no_aplica';
        } elseif ($data['estado_facturacion'] === 'no_aplica') {
            $data['estado_facturacion'] = 'pendiente';
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE `{$db}`.`alq_gastos_propiedad`
                SET id_propiedad=:id_propiedad, id_contrato=:id_contrato, fecha_gasto=:fecha_gasto, periodo_aplicable=:periodo_aplicable,
                    categoria=:categoria, concepto=:concepto, proveedor=:proveedor, comprobante=:comprobante, moneda=:moneda,
                    monto=:monto, pagado_por=:pagado_por, trasladar_inquilino=:trasladar_inquilino, estado_facturacion=:estado_facturacion,
                    observacion=:observacion
                WHERE id_gasto=:id
            ");
            $data['id'] = $id;
            $stmt->execute($data);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `{$db}`.`alq_gastos_propiedad`
                    (id_propiedad, id_contrato, fecha_gasto, periodo_aplicable, categoria, concepto, proveedor, comprobante, moneda, monto,
                     pagado_por, trasladar_inquilino, estado_facturacion, observacion)
                VALUES
                    (:id_propiedad, :id_contrato, :fecha_gasto, :periodo_aplicable, :categoria, :concepto, :proveedor, :comprobante, :moneda, :monto,
                     :pagado_por, :trasladar_inquilino, :estado_facturacion, :observacion)
            ");
            $stmt->execute($data);
        }

        smxAlqJson(['ok' => true]);
    }

    smxAlqJson(['ok' => false, 'error' => 'Acción no soportada'], 400);
} catch (Throwable $e) {
    smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
