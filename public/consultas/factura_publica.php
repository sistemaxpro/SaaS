<?php
/**
 * Verificacion publica por QR con payload completo.
 * Recibe:
 * - d: payload base64url(JSON) con datos relevantes de factura y empresa.
 * Fallback:
 * - id_factura, id_empresa, dbase (consulta directa en DB de empresa)
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

function decodeBase64Url(string $v): string
{
    $v = strtr($v, '-_', '+/');
    $pad = strlen($v) % 4;
    if ($pad > 0) {
        $v .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($v, true);
    return $raw === false ? '' : $raw;
}

function safe(array $arr, string $key, $default = '')
{
    return $arr[$key] ?? $default;
}

function formatGs($n): string
{
    return number_format((float)$n, 0, ',', '.');
}

function formatDatePy(string $raw): string
{
    $v = trim($raw);
    if ($v === '') return '';
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v)) return $v;
    foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $f) {
        $d = DateTime::createFromFormat($f, $v);
        if ($d instanceof DateTime) return $d->format('d/m/Y H:i');
    }
    $ts = strtotime($v);
    return $ts !== false ? date('d/m/Y H:i', $ts) : $v;
}

$payloadRaw = isset($_GET['d']) ? decodeBase64Url((string)$_GET['d']) : '';
$payload = [];
if ($payloadRaw !== '') {
    $decoded = json_decode($payloadRaw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

// Fallback por query clásica si no vino payload completo.
$idFactura = (int)($_GET['id_factura'] ?? $_GET['i'] ?? safe($payload, 'id_factura', 0));
$idEmpresa = (int)($_GET['id_empresa'] ?? $_GET['e'] ?? safe($payload, 'id_empresa', 0));
$dbase = trim((string)($_GET['dbase'] ?? $_GET['db'] ?? safe($payload, 'dbase', '')));

if (empty($payload) && $idFactura > 0 && $idEmpresa > 0) {
    try {
        require_once __DIR__ . '/../pos/config/db_config.php';
        $conn = getEmpresaConnection($idEmpresa);
        $pdo = $conn['pdo'];
        $masterPdo = $conn['masterPdo'];
        $dbName = $conn['dbName'];
        if ($dbase === '') {
            $dbase = $dbName;
        }

        $st = $pdo->prepare("
            SELECT fv.*, c.nombre AS cliente_nombre, c.numero AS cliente_doc
            FROM {$dbName}.factura_ventas fv
            LEFT JOIN {$dbName}.clientes c ON c.id = fv.id_cliente
            WHERE fv.id_factura = :id
            LIMIT 1
        ");
        $st->execute([':id' => $idFactura]);
        $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $se = $masterPdo->prepare("SELECT empresa, ruc, dv, direccion, telefono, email, timbrado, vigencia_fin FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
        $se->execute([':id' => $idEmpresa]);
        $e = $se->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!empty($f)) {
            $payload = [
                'id_factura' => (int)($f['id_factura'] ?? $idFactura),
                'id_empresa' => $idEmpresa,
                'dbase' => $dbase,
                'nro_factura' => (string)($f['nro_factura'] ?? ''),
                'fecha' => (string)($f['fecha'] ?? ''),
                'total' => (float)($f['total'] ?? 0),
                'timbrado' => (string)($f['timbrado'] ?? ($e['timbrado'] ?? '')),
                'vencimiento' => (string)($f['fecha_fin_timbrado'] ?? ($e['vigencia_fin'] ?? '')),
                'cliente' => (string)($f['cliente_nombre'] ?? $f['cliente'] ?? 'CONSUMIDOR FINAL'),
                'cliente_doc' => (string)($f['cliente_doc'] ?? $f['ruc_cliente'] ?? ''),
                'condicion' => (string)($f['forma_pago'] ?? $f['cod_forma_pago'] ?? ''),
                'tipo_documento' => (int)($f['tipo_documento'] ?? 1),
                'empresa' => [
                    'nombre' => (string)($e['empresa'] ?? ''),
                    'ruc' => (string)($e['ruc'] ?? ''),
                    'dv' => (string)($e['dv'] ?? ''),
                    'direccion' => (string)($e['direccion'] ?? ''),
                    'telefono' => (string)($e['telefono'] ?? ''),
                    'email' => (string)($e['email'] ?? ''),
                ],
            ];
        }
    } catch (Throwable $e) {
        // Mantener pantalla funcionando aunque no haya DB disponible.
    }
}

$empresa = is_array(safe($payload, 'empresa', [])) ? safe($payload, 'empresa', []) : [];
$empresaNombre = trim((string)safe($empresa, 'nombre', 'SISTEMAX.PRO'));
$empresaRuc = trim((string)safe($empresa, 'ruc', ''));
$empresaDv = trim((string)safe($empresa, 'dv', ''));
if ($empresaRuc !== '' && $empresaDv !== '') {
    $empresaRuc .= '-' . $empresaDv;
}

$consultaOk = !empty($payload) && $idFactura > 0 && $idEmpresa > 0;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificación Pública | SistemaX</title>
    <link rel="stylesheet" href="/public/assets/tailwind.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="w-full px-0 md:px-4 xl:px-6 py-0 md:py-6">
        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,3fr)_minmax(280px,1fr)] gap-0 xl:gap-6 items-start">
            <section class="rounded-none md:rounded-2xl border-0 md:border border-slate-800 bg-slate-900/90 shadow-none md:shadow-2xl min-h-screen md:min-h-0">
                <header class="border-b border-slate-800 p-5 md:p-7">
                    <p class="text-xs uppercase tracking-[0.18em] text-sky-300">Verificación pública</p>
                    <h1 class="mt-2 text-2xl font-bold md:text-3xl">Factura / Nota</h1>
                    <p class="mt-2 text-sm text-slate-400">Este comprobante fue generado en SistemaX Pro.</p>
                </header>

                <?php if (!$consultaOk): ?>
                    <div class="p-6 md:p-8">
                        <div class="rounded-xl border border-rose-600/40 bg-rose-950/30 p-4 text-rose-200">
                            No se recibieron datos válidos para verificación.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid gap-4 p-5 md:grid-cols-2 md:gap-5 md:p-7">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400">Empresa</p>
                            <p class="mt-1 text-lg font-semibold text-white"><?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm text-slate-300">RUC: <?= htmlspecialchars($empresaRuc !== '' ? $empresaRuc : 'N/D', ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm text-slate-300">DB: <?= htmlspecialchars((string)safe($payload, 'dbase', 'N/D'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>

                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400">Documento</p>
                            <p class="mt-1 text-lg font-semibold text-white">#<?= htmlspecialchars((string)safe($payload, 'nro_factura', safe($payload, 'id_factura', '')), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm text-slate-300">Fecha: <?= htmlspecialchars(formatDatePy((string)safe($payload, 'fecha', '')), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm text-slate-300">Tipo: <?= ((int)safe($payload, 'tipo_documento', 0) === 1) ? 'FACTURA AUTOGRAFIADA' : 'NOTA / OTRO' ?></p>
                        </div>

                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400">Cliente</p>
                            <p class="mt-1 text-base font-semibold text-white"><?= htmlspecialchars((string)safe($payload, 'cliente', 'CONSUMIDOR FINAL'), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm text-slate-300">Doc: <?= htmlspecialchars((string)safe($payload, 'cliente_doc', 'N/D'), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm text-slate-300">Condición: <?= htmlspecialchars((string)safe($payload, 'condicion', 'N/D'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>

                        <div class="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                            <p class="text-xs text-slate-400">Fiscal</p>
                            <p class="mt-1 text-base font-semibold text-white">Timbrado: <?= htmlspecialchars((string)safe($payload, 'timbrado', 'N/D'), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm text-slate-300">Venc.: <?= htmlspecialchars(formatDatePy((string)safe($payload, 'vencimiento', '')), ENT_QUOTES, 'UTF-8') ?: 'N/D' ?></p>
                            <p class="mt-2 text-xl font-black text-emerald-300">Gs. <?= formatGs((float)safe($payload, 'total', 0)) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="px-4 py-5 md:px-0 xl:py-0">
                <div class="xl:sticky xl:top-6 rounded-none md:rounded-3xl overflow-hidden border border-slate-800 bg-[radial-gradient(circle_at_top,#0f766e_0%,#0f172a_44%,#020617_100%)] shadow-2xl">
                    <div class="p-6 md:p-7">
                        <div class="inline-flex items-center gap-2 rounded-full border border-cyan-400/30 bg-cyan-400/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] text-cyan-200">
                            sistemax.pro
                        </div>
                        <h2 class="mt-5 text-2xl font-black leading-tight text-white">¿Querés emitir comprobantes y compartirlos así desde tu empresa?</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-300">
                            Facturación, ventas, stock, POS, SIFEN y módulos on demand en una sola plataforma. Si te gusta esta experiencia, podés probar Sistemax.Pro.
                        </p>
                        <div class="mt-6 grid grid-cols-2 gap-3 text-xs">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                <p class="text-slate-400">Comprobantes</p>
                                <p class="mt-1 text-lg font-bold text-white">QR + WhatsApp</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                <p class="text-slate-400">Activación</p>
                                <p class="mt-1 text-lg font-bold text-white">On demand</p>
                            </div>
                        </div>
                        <div class="mt-6 space-y-3">
                            <a href="/public/suscribete.php?registro=1&v=20260327183227" class="block w-full rounded-2xl bg-cyan-400 px-4 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Probar ahora</a>
                            <a href="/public/video_demo_sistemax_stock.html" target="_blank" rel="noopener" class="block w-full rounded-2xl border border-white/15 bg-white/5 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-white/10">Ver video demo</a>
                            <a href="https://cliente.sistemax.pro" target="_blank" rel="noopener" class="block w-full rounded-2xl border border-white/10 px-4 py-3 text-center text-sm font-semibold text-slate-300 transition hover:border-cyan-300/40 hover:text-white">Ingresar al sistema</a>
                        </div>
                        <div class="mt-6 rounded-2xl border border-amber-300/15 bg-amber-300/10 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-200">Experiencia pública</p>
                            <p class="mt-2 text-sm text-amber-50">
                                Este comprobante fue compartido con verificación pública. El mismo esquema puede quedar activo para tu negocio.
                            </p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </main>
</body>
</html>
