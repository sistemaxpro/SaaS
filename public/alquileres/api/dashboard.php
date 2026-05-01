<?php

require_once __DIR__ . '/common.php';

try {
    [$pdo, $db] = smxAlqDb();

    $stats = [
        'propiedades' => (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`alq_propiedades`")->fetchColumn(),
        'contratos_activos' => (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`alq_contratos` WHERE estado = 'activo'")->fetchColumn(),
        'facturas_pendientes' => (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`alq_facturas` WHERE estado IN ('pendiente','vencida')")->fetchColumn(),
        'monto_pendiente' => (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM `{$db}`.`alq_facturas` WHERE estado IN ('pendiente','vencida')")->fetchColumn(),
        'gastos_pendientes_traslado' => (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM `{$db}`.`alq_gastos_propiedad` WHERE trasladar_inquilino = 1 AND estado_facturacion = 'pendiente'")->fetchColumn(),
    ];

    $dueStmt = $pdo->query("
        SELECT
            f.id_factura,
            f.periodo,
            f.fecha_vencimiento,
            f.total,
            f.estado,
            c.numero_contrato,
            i.nombre_razon,
            p.nombre AS propiedad
        FROM `{$db}`.`alq_facturas` f
        INNER JOIN `{$db}`.`alq_contratos` c ON c.id_contrato = f.id_contrato
        INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
        INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
        ORDER BY f.fecha_vencimiento ASC, f.id_factura DESC
        LIMIT 10
    ");

    $contractsStmt = $pdo->query("
        SELECT
            c.id_contrato,
            c.numero_contrato,
            c.fecha_inicio,
            c.fecha_fin,
            c.estado,
            c.canon_mensual,
            c.moneda,
            p.nombre AS propiedad,
            i.nombre_razon
        FROM `{$db}`.`alq_contratos` c
        INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
        INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
        ORDER BY c.created_at DESC
        LIMIT 8
    ");

    smxAlqJson([
        'ok' => true,
        'stats' => $stats,
        'vencimientos' => $dueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'contratos' => $contractsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'legal' => [
            'civil_code' => 'https://www.bacn.gov.py/leyes-paraguayas/522/codigo-civil-iii-parte-libro-tercero',
            'vivienda' => 'https://www.bacn.gov.py/leyes-paraguayas/5155/ley-n-5638-fomento-de-la-vivienda-',
            'datos' => 'https://www.bacn.gov.py/leyes-paraguayas/12924/ley-n-75932025-de-proteccion-de-datos-personales-en-la-republica-del-paraguay',
            'ekuatia' => 'https://www.dnit.gov.py/en/web/e-kuatia/informacion',
        ],
    ]);
} catch (Throwable $e) {
    smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
