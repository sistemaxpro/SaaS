<?php

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Support/whatsapp_queue.php';

header('Content-Type: application/json; charset=utf-8');

Session::requireLogin('/public/login.php');

function smxAlqJson(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function smxAlqDb(): array
{
    $pdo = Database::getSessionEmpresaConnection();
    $db = Session::getDbase();
    if (!$db) {
        throw new RuntimeException('Base de empresa no disponible en sesión');
    }
    smxAlqEnsureSchema($pdo, $db);
    return [$pdo, $db];
}

function smxAlqEnsureSchema(PDO $pdo, string $db): void
{
    static $done = [];
    if (isset($done[$db])) {
        return;
    }
    $done[$db] = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`alq_propiedades` (
            `id_propiedad` INT NOT NULL AUTO_INCREMENT,
            `codigo` VARCHAR(40) NOT NULL,
            `nombre` VARCHAR(160) NOT NULL,
            `tipo_inmueble` VARCHAR(60) NOT NULL DEFAULT 'departamento',
            `direccion` VARCHAR(255) NULL,
            `ciudad` VARCHAR(120) NULL,
            `matricula` VARCHAR(80) NULL,
            `padron` VARCHAR(80) NULL,
            `area_m2` DECIMAL(12,2) NULL DEFAULT 0,
            `moneda` VARCHAR(10) NOT NULL DEFAULT 'PYG',
            `canon_mensual` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `deposito_garantia` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `dia_vencimiento` TINYINT NOT NULL DEFAULT 10,
            `mora_diaria` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `iva_incluido` TINYINT(1) NOT NULL DEFAULT 1,
            `estado` VARCHAR(20) NOT NULL DEFAULT 'disponible',
            `observacion` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_propiedad`),
            UNIQUE KEY `uk_alq_propiedad_codigo` (`codigo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`alq_inquilinos` (
            `id_inquilino` INT NOT NULL AUTO_INCREMENT,
            `tipo_persona` VARCHAR(20) NOT NULL DEFAULT 'fisica',
            `nombre_razon` VARCHAR(180) NOT NULL,
            `documento` VARCHAR(40) NULL,
            `ruc` VARCHAR(30) NULL,
            `telefono` VARCHAR(40) NULL,
            `email` VARCHAR(160) NULL,
            `whatsapp` VARCHAR(40) NULL,
            `domicilio` VARCHAR(255) NULL,
            `consentimiento_whatsapp` TINYINT(1) NOT NULL DEFAULT 0,
            `consentimiento_fecha` DATETIME NULL,
            `observacion` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_inquilino`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`alq_contratos` (
            `id_contrato` INT NOT NULL AUTO_INCREMENT,
            `numero_contrato` VARCHAR(40) NOT NULL,
            `id_propiedad` INT NOT NULL,
            `id_inquilino` INT NOT NULL,
            `fecha_firma` DATE NOT NULL,
            `fecha_inicio` DATE NOT NULL,
            `fecha_fin` DATE NOT NULL,
            `destino_inmueble` VARCHAR(120) NOT NULL DEFAULT 'vivienda',
            `moneda` VARCHAR(10) NOT NULL DEFAULT 'PYG',
            `canon_mensual` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `expensas_mensuales` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `servicios_mensuales` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `deposito_garantia` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `dia_vencimiento` TINYINT NOT NULL DEFAULT 10,
            `mora_fija` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `incremento_tipo` VARCHAR(30) NOT NULL DEFAULT 'manual',
            `incremento_valor` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `ajuste_observacion` VARCHAR(255) NULL,
            `firmado_en` VARCHAR(120) NULL DEFAULT 'Asunción',
            `estado` VARCHAR(20) NOT NULL DEFAULT 'activo',
            `clausulas_json` LONGTEXT NULL,
            `observacion` TEXT NULL,
            `created_by` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_contrato`),
            UNIQUE KEY `uk_alq_contrato_numero` (`numero_contrato`),
            KEY `idx_alq_contrato_estado` (`estado`, `fecha_fin`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`alq_facturas` (
            `id_factura` INT NOT NULL AUTO_INCREMENT,
            `id_contrato` INT NOT NULL,
            `periodo` CHAR(7) NOT NULL,
            `fecha_emision` DATE NOT NULL,
            `fecha_vencimiento` DATE NOT NULL,
            `concepto` VARCHAR(255) NOT NULL,
            `monto_alquiler` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `monto_expensas` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `monto_servicios` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `monto_mora` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `monto_descuento` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            `fe_estado` VARCHAR(20) NOT NULL DEFAULT 'borrador',
            `fe_payload_json` LONGTEXT NULL,
            `fe_cdc` VARCHAR(80) NULL,
            `fe_numero` VARCHAR(60) NULL,
            `observacion` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_factura`),
            UNIQUE KEY `uk_alq_factura_periodo` (`id_contrato`, `periodo`),
            KEY `idx_alq_factura_venc` (`estado`, `fecha_vencimiento`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`alq_gastos_propiedad` (
            `id_gasto` INT NOT NULL AUTO_INCREMENT,
            `id_propiedad` INT NOT NULL,
            `id_contrato` INT NULL,
            `fecha_gasto` DATE NOT NULL,
            `periodo_aplicable` CHAR(7) NOT NULL,
            `categoria` VARCHAR(60) NOT NULL DEFAULT 'mantenimiento',
            `concepto` VARCHAR(180) NOT NULL,
            `proveedor` VARCHAR(160) NULL,
            `comprobante` VARCHAR(80) NULL,
            `moneda` VARCHAR(10) NOT NULL DEFAULT 'PYG',
            `monto` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `pagado_por` VARCHAR(30) NOT NULL DEFAULT 'propietario',
            `trasladar_inquilino` TINYINT(1) NOT NULL DEFAULT 0,
            `estado_facturacion` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            `id_factura` INT NULL,
            `observacion` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_gasto`),
            KEY `idx_alq_gasto_propiedad` (`id_propiedad`, `periodo_aplicable`),
            KEY `idx_alq_gasto_facturacion` (`trasladar_inquilino`, `estado_facturacion`, `id_contrato`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`alq_avisos` (
            `id_aviso` INT NOT NULL AUTO_INCREMENT,
            `id_contrato` INT NOT NULL,
            `id_factura` INT NULL,
            `tipo` VARCHAR(30) NOT NULL DEFAULT 'vencimiento',
            `canal` VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
            `destinatario` VARCHAR(40) NOT NULL,
            `mensaje` TEXT NOT NULL,
            `programado_para` DATETIME NOT NULL,
            `enviado_en` DATETIME NULL,
            `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            `provider` VARCHAR(40) NULL,
            `resultado_json` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_aviso`),
            KEY `idx_alq_aviso_estado` (`estado`, `programado_para`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`alq_propiedad_imagenes` (
            `id_imagen` INT NOT NULL AUTO_INCREMENT,
            `id_propiedad` INT NOT NULL,
            `origen` VARCHAR(20) NOT NULL DEFAULT 'local',
            `fuente` VARCHAR(40) NULL,
            `titulo` VARCHAR(180) NULL,
            `url_original` VARCHAR(700) NOT NULL,
            `url_preview` VARCHAR(700) NULL,
            `url_cover` VARCHAR(700) NULL,
            `archivo_local` VARCHAR(255) NULL,
            `principal` TINYINT(1) NOT NULL DEFAULT 0,
            `orden` INT NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_imagen`),
            KEY `idx_alq_propiedad_imagen_propiedad` (`id_propiedad`, `principal`, `orden`, `id_imagen`),
            KEY `idx_alq_propiedad_imagen_origen` (`origen`, `fuente`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $cols = [];
        $stmtCols = $pdo->query("SHOW COLUMNS FROM `{$db}`.`alq_propiedad_imagenes`");
        $rows = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
        if (!isset($cols['url_cover'])) {
            $pdo->exec("ALTER TABLE `{$db}`.`alq_propiedad_imagenes` ADD COLUMN `url_cover` VARCHAR(700) NULL AFTER `url_preview`");
        }
    } catch (Throwable $e) {
        error_log('Alquileres schema compat imágenes: ' . $e->getMessage());
    }
}

function smxAlqImagesDbSafe(string $db): string
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $db);
}

function smxAlqPropertyImagesDir(string $db, int $idPropiedad): string
{
    $dbSafe = smxAlqImagesDbSafe($db);
    return dirname(__DIR__, 3) . "/public/_lib/file/img/alquileres/{$dbSafe}/propiedades/{$idPropiedad}/";
}

function smxAlqPropertyImagesUrl(string $db, int $idPropiedad): string
{
    $dbSafe = smxAlqImagesDbSafe($db);
    return "/public/_lib/file/img/alquileres/{$dbSafe}/propiedades/{$idPropiedad}/";
}

function smxAlqPropertyImagePublicUrl(string $db, int $idPropiedad, string $filename): string
{
    return smxAlqPropertyImagesUrl($db, $idPropiedad) . ltrim($filename, '/');
}

function smxAlqEnsureDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function smxAlqContractClauses(array $row): array
{
    $canon = number_format((float)($row['canon_mensual'] ?? 0), 0, ',', '.');
    $dep = number_format((float)($row['deposito_garantia'] ?? 0), 0, ',', '.');
    $dia = (int)($row['dia_vencimiento'] ?? 10);
    $mora = number_format((float)($row['mora_fija'] ?? 0), 0, ',', '.');

    return [
        'objeto' => 'El locador da en alquiler el inmueble identificado en este contrato, exclusivamente para el destino declarado por las partes.',
        'plazo' => 'El plazo contractual corre desde la fecha de inicio hasta la fecha de finalización consignadas, con restitución obligatoria al vencimiento salvo renovación expresa.',
        'canon' => "El canon locativo mensual se fija en {$canon} {$row['moneda']}, pagadero hasta el día {$dia} de cada mes.",
        'deposito' => "El depósito de garantía se fija en {$dep} {$row['moneda']} y responderá por daños, servicios impagos y obligaciones pendientes.",
        'mora' => "La falta de pago en término habilita mora automática y un recargo fijo de {$mora} {$row['moneda']}, sin perjuicio de otras acciones.",
        'subarriendo' => 'Queda prohibido subarrendar o ceder el uso del inmueble sin autorización escrita del locador.',
        'conservacion' => 'El inquilino se obliga a conservar el inmueble y devolverlo en estado compatible con el uso normal, salvo desgaste por el tiempo.',
        'notificaciones' => 'Las partes aceptan notificaciones al domicilio contractual y, cuando exista consentimiento expreso, por medios electrónicos y WhatsApp.',
        'datos' => 'El tratamiento de datos personales se realiza para administración del alquiler, cobranzas, facturación y cumplimiento normativo.',
        'jurisdiccion' => 'Para toda controversia, las partes se someten a la jurisdicción competente de la República del Paraguay, salvo pacto específico distinto.',
    ];
}

function smxAlqBuildFePayload(array $factura, array $contrato): array
{
    $gastos = [];
    $gastosJson = json_decode((string)($factura['gastos_json'] ?? ''), true);
    if (is_array($gastosJson)) {
        $gastos = $gastosJson;
    }

    $items = [
        ['descripcion' => 'Alquiler mensual', 'cantidad' => 1, 'precio_unitario' => (float)$factura['monto_alquiler']],
        ['descripcion' => 'Expensas', 'cantidad' => 1, 'precio_unitario' => (float)$factura['monto_expensas']],
        ['descripcion' => 'Servicios trasladados', 'cantidad' => 1, 'precio_unitario' => (float)$factura['monto_servicios']],
        ['descripcion' => 'Mora', 'cantidad' => 1, 'precio_unitario' => (float)$factura['monto_mora']],
        ['descripcion' => 'Descuento', 'cantidad' => 1, 'precio_unitario' => (float)$factura['monto_descuento'] * -1],
    ];
    foreach ($gastos as $gasto) {
        $items[] = [
            'descripcion' => 'Gasto trasladado: ' . (string)($gasto['concepto'] ?? 'Gasto de propiedad'),
            'cantidad' => 1,
            'precio_unitario' => (float)($gasto['monto'] ?? 0),
        ];
    }

    return [
        'tipo_documento' => 'factura_electronica',
        'naturaleza_operacion' => 'servicio_locacion_inmueble',
        'periodo' => (string)$factura['periodo'],
        'fecha_emision' => (string)$factura['fecha_emision'],
        'fecha_vencimiento' => (string)$factura['fecha_vencimiento'],
        'cliente' => [
            'nombre_razon' => (string)$contrato['nombre_razon'],
            'documento' => (string)($contrato['documento'] ?? ''),
            'ruc' => (string)($contrato['ruc'] ?? ''),
            'telefono' => (string)($contrato['telefono'] ?? ''),
            'email' => (string)($contrato['email'] ?? ''),
        ],
        'inmueble' => [
            'codigo' => (string)$contrato['codigo'],
            'nombre' => (string)$contrato['nombre'],
            'direccion' => (string)($contrato['direccion'] ?? ''),
            'tipo_inmueble' => (string)($contrato['tipo_inmueble'] ?? ''),
        ],
        'items' => $items,
        'total' => (float)$factura['total'],
        'observacion' => 'Payload preparado para remisión SIFEN/DNIT. Requiere certificado, timbrado y secuencia vigente del emisor.',
    ];
}

function smxAlqPendingBillableExpenses(PDO $pdo, string $db, array $contract, string $period): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM `{$db}`.`alq_gastos_propiedad`
        WHERE id_propiedad = ?
          AND (id_contrato IS NULL OR id_contrato = ?)
          AND periodo_aplicable = ?
          AND trasladar_inquilino = 1
          AND estado_facturacion = 'pendiente'
        ORDER BY fecha_gasto ASC, id_gasto ASC
    ");
    $stmt->execute([
        (int)($contract['id_propiedad'] ?? 0),
        (int)($contract['id_contrato'] ?? 0),
        $period,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function smxAlqCreateInvoice(PDO $pdo, string $db, array $contract, string $period): ?array
{
    $period = trim($period);
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM `{$db}`.`alq_facturas` WHERE id_contrato = ? AND periodo = ? LIMIT 1");
    $stmt->execute([(int)$contract['id_contrato'], $period]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return $existing;
    }

    $fechaEmision = $period . '-01';
    $diaVenc = max(1, min(28, (int)($contract['dia_vencimiento'] ?? 10)));
    $fechaVenc = sprintf('%s-%02d', $period, $diaVenc);
    $montoAlquiler = (float)($contract['canon_mensual'] ?? 0);
    $montoExpensas = (float)($contract['expensas_mensuales'] ?? 0);
    $montoServicios = (float)($contract['servicios_mensuales'] ?? 0);
    $gastos = smxAlqPendingBillableExpenses($pdo, $db, $contract, $period);
    $montoGastos = 0.0;
    foreach ($gastos as $gasto) {
        $montoGastos += (float)($gasto['monto'] ?? 0);
    }
    $montoMora = 0.0;
    $montoDescuento = 0.0;
    $total = $montoAlquiler + $montoExpensas + $montoServicios + $montoGastos + $montoMora - $montoDescuento;

    $insert = $pdo->prepare("
        INSERT INTO `{$db}`.`alq_facturas`
            (id_contrato, periodo, fecha_emision, fecha_vencimiento, concepto, monto_alquiler, monto_expensas, monto_servicios, monto_mora, monto_descuento, total, observacion)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        (int)$contract['id_contrato'],
        $period,
        $fechaEmision,
        $fechaVenc,
        'Canon locativo del periodo ' . $period,
        $montoAlquiler,
        $montoExpensas,
        $montoServicios,
        $montoMora,
        $montoDescuento,
        $total,
        $montoGastos > 0 ? ('Incluye gastos trasladados por ' . number_format($montoGastos, 0, ',', '.') . ' ' . (string)$contract['moneda']) : null,
    ]);

    $idFactura = (int)$pdo->lastInsertId();
    if ($idFactura > 0 && !empty($gastos)) {
        $updGasto = $pdo->prepare("UPDATE `{$db}`.`alq_gastos_propiedad` SET estado_facturacion = 'facturado', id_factura = ? WHERE id_gasto = ?");
        foreach ($gastos as $gasto) {
            $updGasto->execute([$idFactura, (int)$gasto['id_gasto']]);
        }
    }

    $stmt->execute([(int)$contract['id_contrato'], $period]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$invoice) {
        return null;
    }
    $invoice['gastos_json'] = json_encode($gastos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $invoice;
}

function smxAlqScheduleReminder(PDO $pdo, string $db, array $factura, array $contract, int $daysBefore = 3): array
{
    $phone = trim((string)($contract['whatsapp'] ?? $contract['telefono'] ?? ''));
    if ($phone === '') {
        return ['success' => false, 'error' => 'El inquilino no tiene WhatsApp configurado'];
    }
    if ((int)($contract['consentimiento_whatsapp'] ?? 0) !== 1) {
        return ['success' => false, 'error' => 'El inquilino no otorgó consentimiento para WhatsApp'];
    }

    $programDate = new DateTimeImmutable((string)$factura['fecha_vencimiento']);
    $programDate = $programDate->modify(sprintf('-%d day', max(0, $daysBefore)))->setTime(9, 0);
    $mensaje = sprintf(
        'Aviso de vencimiento: %s, el alquiler del inmueble %s vence el %s. Monto: %s %s. Si ya abonó, favor ignorar.',
        (string)$contract['nombre_razon'],
        (string)$contract['nombre'],
        (string)$factura['fecha_vencimiento'],
        number_format((float)$factura['total'], 0, ',', '.'),
        (string)$contract['moneda']
    );

    $stmt = $pdo->prepare("
        INSERT INTO `{$db}`.`alq_avisos`
            (id_contrato, id_factura, tipo, canal, destinatario, mensaje, programado_para)
        VALUES (?, ?, 'vencimiento', 'whatsapp', ?, ?, ?)
    ");
    $stmt->execute([
        (int)$contract['id_contrato'],
        (int)$factura['id_factura'],
        $phone,
        $mensaje,
        $programDate->format('Y-m-d H:i:s'),
    ]);

    return ['success' => true];
}

function smxAlqDispatchReminder(PDO $pdo, string $db, int $idAviso): array
{
    $stmt = $pdo->prepare("SELECT * FROM `{$db}`.`alq_avisos` WHERE id_aviso = ? LIMIT 1");
    $stmt->execute([$idAviso]);
    $aviso = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$aviso) {
        return ['success' => false, 'error' => 'Aviso no encontrado'];
    }

    $res = sx_send_whatsapp_message((string)$aviso['destinatario'], (string)$aviso['mensaje']);
    $upd = $pdo->prepare("
        UPDATE `{$db}`.`alq_avisos`
        SET estado = ?, enviado_en = NOW(), provider = ?, resultado_json = ?
        WHERE id_aviso = ?
    ");
    $upd->execute([
        !empty($res['success']) ? 'enviado' : 'error',
        substr((string)($res['provider'] ?? ''), 0, 40),
        json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $idAviso,
    ]);

    return $res;
}

function smxAlqRequirePost(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        smxAlqJson(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
}
