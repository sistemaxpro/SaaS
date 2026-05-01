<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';

function buildPublicDocReceiptTokenPage(int $idEmpresa, int $idCajaOp, int $idDocumento): string
{
    $secret = hash('sha256', 'sistemax-doc-receipt|' . MASTER_DB . '|v1');
    return hash_hmac('sha256', $idEmpresa . '|' . $idCajaOp . '|' . $idDocumento, $secret);
}

function moneyGs($value): string
{
    return number_format((float)$value, 0, ',', '.');
}

function fmtDateDoc($raw): string
{
    $ts = strtotime((string)$raw);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
$idCajaOp = (int)($_GET['id'] ?? 0);
$idDocumento = (int)($_GET['doc'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));

if ($idEmpresa <= 0 || $idCajaOp <= 0 || $idDocumento <= 0 || $token === '') {
    http_response_code(400);
    exit('Comprobante inválido');
}

if (!hash_equals(buildPublicDocReceiptTokenPage($idEmpresa, $idCajaOp, $idDocumento), $token)) {
    http_response_code(403);
    exit('Acceso denegado');
}

try {
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = $conn['masterPdo'];

    $empresa = 'SISTEMAX';
    try {
        $stmtEmp = $masterPdo->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
        $stmtEmp->execute([':id' => $idEmpresa]);
        $empresa = trim((string)($stmtEmp->fetchColumn() ?: 'SISTEMAX'));
    } catch (Throwable $e) {
    }

    $stmt = $pdo->prepare("
        SELECT
            ec.id,
            ec.fecha,
            ec.concepto,
            ec.credito,
            ec.comprobante,
            ec.medio_cobro,
            ec.login,
            ec.id_login,
            COALESCE(NULLIF(TRIM(CAST(ec.login AS CHAR)), ''), NULLIF(TRIM(CAST(ec.login AS CHAR)), '0'), su.login, CONCAT('#', ec.id_login)) AS operador_nombre,
            ec.codigo AS id_caja,
            d.numero AS id_documento,
            d.concepto AS documento_concepto,
            d.cantidad_cuota,
            d.fecha_vencimiento,
            d.total AS documento_total,
            d.pagado AS documento_pagado,
            d.pendiente AS documento_pendiente,
            d.id_factura,
            c.nombre AS cliente_nombre,
            c.numero AS cliente_ruc,
            fv.nro_factura
        FROM {$db}.extracto_caja ec
        INNER JOIN {$db}.documentos d ON d.numero = ec.id_relacion
        LEFT JOIN {$db}.clientes c ON c.id = d.id_cliente
        LEFT JOIN {$db}.factura_ventas fv ON fv.id_factura = d.id_factura
        LEFT JOIN " . MASTER_DB . ".sec_users su ON su.id_login = ec.id_login
        WHERE ec.id = :id
          AND ec.tabla_relacion = 'documentos'
          AND ec.id_relacion = :doc
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $idCajaOp,
        ':doc' => $idDocumento,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit('Comprobante no encontrado');
    }

    $publicUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? '')
        . $_SERVER['REQUEST_URI'];
    $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($publicUrl);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Error al generar comprobante');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante de Cobro</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="max-w-3xl mx-auto px-4 py-8">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="px-6 py-5 bg-gradient-to-r from-blue-700 to-cyan-600 text-white">
                <div class="text-sm uppercase tracking-[0.25em] opacity-80">Comprobante de Cobro</div>
                <h1 class="text-2xl font-bold mt-1"><?= htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8') ?></h1>
                <div class="text-sm mt-2">Recibo #<?= (int)$row['id'] ?> · Documento #<?= (int)$row['id_documento'] ?></div>
            </div>
            <div class="p-6 grid grid-cols-1 lg:grid-cols-[1fr_220px] gap-6">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                            <div class="text-xs uppercase text-slate-500">Fecha</div>
                            <div class="mt-1 font-semibold"><?= htmlspecialchars(fmtDateDoc($row['fecha'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                            <div class="text-xs uppercase text-slate-500">Medio de Cobro</div>
                            <div class="mt-1 font-semibold"><?= htmlspecialchars((string)($row['medio_cobro'] ?? 'EFECTIVO'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                            <div class="text-xs uppercase text-slate-500">Cliente</div>
                            <div class="mt-1 font-semibold"><?= htmlspecialchars((string)($row['cliente_nombre'] ?? 'Sin cliente'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-sm text-slate-500"><?= htmlspecialchars((string)($row['cliente_ruc'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                            <div class="text-xs uppercase text-slate-500">Operador</div>
                            <div class="mt-1 font-semibold"><?= htmlspecialchars((string)($row['operador_nombre'] ?? $row['login'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="px-4 py-3 bg-slate-900 text-white font-semibold">Documento Cobrado</div>
                        <div class="p-4 space-y-2">
                            <div><span class="text-slate-500">Concepto:</span> <?= htmlspecialchars((string)($row['documento_concepto'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><span class="text-slate-500">Cuota:</span> <?= htmlspecialchars((string)($row['cantidad_cuota'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><span class="text-slate-500">Factura Rel.:</span> <?= htmlspecialchars((string)($row['nro_factura'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><span class="text-slate-500">Vencimiento:</span> <?= htmlspecialchars(fmtDateDoc($row['fecha_vencimiento'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><span class="text-slate-500">Referencia:</span> <?= htmlspecialchars((string)($row['comprobante'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4">
                            <div class="text-xs uppercase text-emerald-700">Cobrado</div>
                            <div class="mt-1 text-xl font-bold text-emerald-700">₲ <?= moneyGs($row['credito'] ?? 0) ?></div>
                        </div>
                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                            <div class="text-xs uppercase text-slate-500">Total Doc.</div>
                            <div class="mt-1 text-lg font-bold">₲ <?= moneyGs($row['documento_total'] ?? 0) ?></div>
                        </div>
                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                            <div class="text-xs uppercase text-slate-500">Pagado</div>
                            <div class="mt-1 text-lg font-bold">₲ <?= moneyGs($row['documento_pagado'] ?? 0) ?></div>
                        </div>
                        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4">
                            <div class="text-xs uppercase text-amber-700">Pendiente</div>
                            <div class="mt-1 text-lg font-bold text-amber-700">₲ <?= moneyGs($row['documento_pendiente'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center">
                        <img src="<?= htmlspecialchars($qrImage, ENT_QUOTES, 'UTF-8') ?>" alt="QR" class="w-[220px] h-[220px] mx-auto rounded-lg bg-white p-2">
                        <div class="mt-3 text-xs text-slate-500 break-all"><?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <button onclick="window.print()" class="w-full h-11 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold">Imprimir</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
