<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idContrato = (int)($_GET['id_contrato'] ?? 0);
if ($idContrato <= 0) {
    http_response_code(400);
    exit('Contrato inválido');
}

$pdo = Database::getSessionEmpresaConnection();
$db = Session::getDbase();

$stmt = $pdo->prepare("
    SELECT
        c.*,
        p.codigo,
        p.nombre,
        p.direccion,
        p.ciudad,
        p.tipo_inmueble,
        p.matricula,
        p.padron,
        i.nombre_razon,
        i.documento,
        i.ruc,
        i.telefono,
        i.email,
        i.whatsapp,
        i.domicilio
    FROM `{$db}`.`alq_contratos` c
    INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
    INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
    WHERE c.id_contrato = ?
    LIMIT 1
");
$stmt->execute([$idContrato]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Contrato no encontrado');
}

$clauses = json_decode((string)($row['clausulas_json'] ?? ''), true);
if (!is_array($clauses) || !$clauses) {
    $canon = number_format((float)($row['canon_mensual'] ?? 0), 0, ',', '.');
    $dep = number_format((float)($row['deposito_garantia'] ?? 0), 0, ',', '.');
    $dia = (int)($row['dia_vencimiento'] ?? 10);
    $mora = number_format((float)($row['mora_fija'] ?? 0), 0, ',', '.');
    $clauses = [
        'objeto' => 'El locador da en alquiler el inmueble identificado en este contrato, exclusivamente para el destino declarado por las partes.',
        'plazo' => 'El plazo contractual corre desde la fecha de inicio hasta la fecha de finalización consignadas, con restitución obligatoria al vencimiento salvo renovación expresa.',
        'canon' => "El canon locativo mensual se fija en {$canon} {$row['moneda']}, pagadero hasta el día {$dia} de cada mes.",
        'deposito' => "El depósito de garantía se fija en {$dep} {$row['moneda']} y responderá por daños, servicios impagos y obligaciones pendientes.",
        'mora' => "La falta de pago en término habilita mora automática y un recargo fijo de {$mora} {$row['moneda']}.",
        'subarriendo' => 'Queda prohibido subarrendar o ceder el uso del inmueble sin autorización escrita del locador.',
        'conservacion' => 'El inquilino se obliga a conservar el inmueble y devolverlo en estado compatible con el uso normal.',
        'notificaciones' => 'Las partes aceptan notificaciones al domicilio contractual y, con consentimiento expreso, por medios electrónicos y WhatsApp.',
        'datos' => 'El tratamiento de datos personales se realiza para administración del alquiler, cobranzas, facturación y cumplimiento normativo.',
        'jurisdiccion' => 'Las partes se someten a la jurisdicción competente de la República del Paraguay.',
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contrato <?= htmlspecialchars((string)$row['numero_contrato'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.45; color: #111827; margin: 24px auto; max-width: 900px; }
        h1, h2, h3 { margin: 0 0 10px; }
        .box { border: 1px solid #cbd5e1; border-radius: 12px; padding: 14px; margin-bottom: 14px; }
        .muted { color: #475569; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .clause { margin-bottom: 10px; }
        .sign { margin-top: 42px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 30px; }
        .line { border-top: 1px solid #111827; padding-top: 6px; }
        @media print {
            body { margin: 0; max-width: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right; margin-bottom:12px;">
        <button onclick="window.print()">Imprimir</button>
    </div>

    <h1>Contrato privado de locación</h1>
    <p class="muted">Documento base editable para inmobiliaria en Paraguay. Requiere revisión legal previa a su firma definitiva.</p>

    <div class="box grid">
        <div><strong>Contrato:</strong> <?= htmlspecialchars((string)$row['numero_contrato'], ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>Firmado en:</strong> <?= htmlspecialchars((string)$row['firmado_en'], ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>Fecha de firma:</strong> <?= htmlspecialchars((string)$row['fecha_firma'], ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>Plazo:</strong> <?= htmlspecialchars((string)$row['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?> a <?= htmlspecialchars((string)$row['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="box">
        <h3>Partes</h3>
        <p><strong>Locador / Administrador:</strong> <?= htmlspecialchars((string)($_SESSION['empresa_nombre'] ?? $_SESSION['empresa'] ?? 'Inmobiliaria'), ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Locatario:</strong> <?= htmlspecialchars((string)$row['nombre_razon'], ENT_QUOTES, 'UTF-8') ?>, documento <?= htmlspecialchars((string)$row['documento'], ENT_QUOTES, 'UTF-8') ?>, RUC <?= htmlspecialchars((string)$row['ruc'], ENT_QUOTES, 'UTF-8') ?>, domicilio <?= htmlspecialchars((string)$row['domicilio'], ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>

    <div class="box">
        <h3>Inmueble</h3>
        <p><strong>Código:</strong> <?= htmlspecialchars((string)$row['codigo'], ENT_QUOTES, 'UTF-8') ?> | <strong>Nombre:</strong> <?= htmlspecialchars((string)$row['nombre'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Dirección:</strong> <?= htmlspecialchars((string)$row['direccion'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars((string)$row['ciudad'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Tipo:</strong> <?= htmlspecialchars((string)$row['tipo_inmueble'], ENT_QUOTES, 'UTF-8') ?> | <strong>Matrícula:</strong> <?= htmlspecialchars((string)$row['matricula'], ENT_QUOTES, 'UTF-8') ?> | <strong>Padrón:</strong> <?= htmlspecialchars((string)$row['padron'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="box">
        <h3>Condiciones económicas</h3>
        <p><strong>Canon mensual:</strong> <?= number_format((float)$row['canon_mensual'], 0, ',', '.') . ' ' . htmlspecialchars((string)$row['moneda'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Expensas:</strong> <?= number_format((float)$row['expensas_mensuales'], 0, ',', '.') . ' ' . htmlspecialchars((string)$row['moneda'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Servicios:</strong> <?= number_format((float)$row['servicios_mensuales'], 0, ',', '.') . ' ' . htmlspecialchars((string)$row['moneda'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Depósito de garantía:</strong> <?= number_format((float)$row['deposito_garantia'], 0, ',', '.') . ' ' . htmlspecialchars((string)$row['moneda'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Vencimiento:</strong> día <?= (int)$row['dia_vencimiento'] ?> de cada mes | <strong>Mora:</strong> <?= number_format((float)$row['mora_fija'], 0, ',', '.') . ' ' . htmlspecialchars((string)$row['moneda'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="box">
        <h3>Cláusulas</h3>
        <?php foreach ($clauses as $title => $text): ?>
            <div class="clause">
                <strong><?= htmlspecialchars((string)ucfirst((string)$title), ENT_QUOTES, 'UTF-8') ?>.</strong>
                <?= nl2br(htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8')) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="box">
        <h3>Aviso legal y de datos</h3>
        <p>Este documento fue confeccionado con base contractual privada y referencias al régimen paraguayo de locación, vivienda, protección de datos y facturación electrónica. La versión definitiva debe ser validada por el profesional jurídico de la inmobiliaria antes de su firma.</p>
    </div>

    <div class="sign">
        <div>
            <div class="line">Locador / Administrador</div>
        </div>
        <div>
            <div class="line">Locatario / Inquilino</div>
        </div>
    </div>
</body>
</html>
