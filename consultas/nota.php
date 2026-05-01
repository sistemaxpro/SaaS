<?php
// Página oficial de validación de factura/nota para QR
// URL: https://sistemax.pro/consultas/nota.php?id_factura=XXX&id_empresa=YYY

$id_factura = isset($_GET['id_factura']) ? intval($_GET['id_factura']) : 0;
$id_empresa = isset($_GET['id_empresa']) ? intval($_GET['id_empresa']) : 0;
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$factura = null;
$empresaInfo = [];

if ($id_factura <= 0) {
    http_response_code(400);
    echo "<h2>Factura/Nota no especificada</h2>";
    exit;
}

$projectRootA = dirname(__DIR__);
$projectRootB = dirname(__DIR__, 2);
$projectRoot = is_file($projectRootA . '/config/database.php') ? $projectRootA : $projectRootB;

define('SISTEMAX_V1', true);
require_once $projectRoot . '/config/database.php';

function buscarFacturaEmpresa(PDO $pdoEmp, int $idFactura): ?array
{
    // Esquema legacy
    try {
        $stmt = $pdoEmp->prepare("SELECT id, cliente, fecha, total FROM facturas WHERE id = ? LIMIT 1");
        $stmt->execute([$idFactura]);
        $factura = $stmt->fetch();
        if ($factura) {
            return $factura;
        }
    } catch (Throwable $e) {
        // Si la tabla no existe, probar esquema actual.
    }

    // Esquema actual POS
    try {
        $stmt = $pdoEmp->prepare("
            SELECT
                fv.id_factura AS id,
                COALESCE(c.nombre, fv.cliente, 'CONSUMIDOR FINAL') AS cliente,
                fv.fecha,
                fv.total
            FROM factura_ventas fv
            LEFT JOIN clientes c ON c.id = fv.id_cliente
            WHERE fv.id_factura = ?
            LIMIT 1
        ");
        $stmt->execute([$idFactura]);
        $factura = $stmt->fetch();
    } catch (PDOException $e) {
        // Compatibilidad con esquemas donde factura_ventas no tiene columna `cliente`.
        if (($e->errorInfo[1] ?? null) !== 1054) {
            throw $e;
        }
        $stmt = $pdoEmp->prepare("
            SELECT
                fv.id_factura AS id,
                COALESCE(c.nombre, 'CONSUMIDOR FINAL') AS cliente,
                fv.fecha,
                fv.total
            FROM factura_ventas fv
            LEFT JOIN clientes c ON c.id = fv.id_cliente
            WHERE fv.id_factura = ?
            LIMIT 1
        ");
        $stmt->execute([$idFactura]);
        $factura = $stmt->fetch();
    }

    return $factura ?: null;
}

function formatearCadenaExcepcion(Throwable $e): string
{
    $mensajes = [];
    $i = 1;
    $actual = $e;
    while ($actual !== null) {
        $mensajes[] = sprintf(
            "#%d %s: %s",
            $i,
            get_class($actual),
            $actual->getMessage()
        );
        $actual = $actual->getPrevious();
        $i++;
    }
    return implode("\n", $mensajes);
}

function obtenerEmpresaVisual(int $idEmpresa): array
{
    $master = Database::getMasterConnection();
    $stmt = $master->prepare("
        SELECT *
        FROM empresa
        WHERE id_empresa = ?
        LIMIT 1
    ");
    $stmt->execute([$idEmpresa]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function valorEmpresa(array $empresa, array $campos): string
{
    foreach ($campos as $campo) {
        if (isset($empresa[$campo])) {
            $valor = trim((string)$empresa[$campo]);
            if ($valor !== '') {
                return $valor;
            }
        }
    }
    return '';
}

function resolverLogoEmpresa(?string $logoFile, string $projectRoot): ?string
{
    $logo = basename(trim((string)$logoFile));
    if ($logo === '') {
        return null;
    }
    $diskPath = $projectRoot . '/public/_lib/file/img/empresa/' . $logo;
    if (!is_file($diskPath)) {
        return null;
    }
    return '/public/_lib/file/img/empresa/' . rawurlencode($logo);
}

try {
    if ($id_empresa <= 0) {
        throw new Exception("Parámetro id_empresa requerido");
    }

    $pdoEmp = Database::getEmpresaConnection($id_empresa);
    $factura = buscarFacturaEmpresa($pdoEmp, $id_factura);
    $empresaInfo = obtenerEmpresaVisual($id_empresa);

    if (!$factura) {
        http_response_code(404);
        echo "<h2>No se encontró la factura/nota en la empresa</h2>";
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h2>Error al consultar la nota</h2>";
    if ($debug) {
        echo "<pre style='background:#333;padding:12px;border-radius:8px;white-space:pre-wrap;color:#fff;'>";
        echo htmlspecialchars(formatearCadenaExcepcion($e), ENT_QUOTES, 'UTF-8');
        echo "</pre>";
    } else {
        echo "<p>Use <code>?debug=1</code> para ver detalle técnico.</p>";
    }
    exit;
}

// Mostrar preview en una box con opción a imprimir
?>
<!DOCTYPE html>
<html lang="es">
<head class="h-full">
    <meta charset="UTF-8">
    <title>Consulta de Nota de Venta</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/public/assets/tailwind.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff !important; }
            .ticket-print {
                box-shadow: none !important;
                border: 1px solid #e2e8f0 !important;
            }
        }
    </style>
</head>
<?php
$fechaOriginal = (string)($factura['fecha'] ?? '');
$fechaTs = $fechaOriginal !== '' ? strtotime($fechaOriginal) : false;
$fechaVista = $fechaTs ? date('d/m/Y H:i', $fechaTs) : 'N/D';
$clienteVista = (string)($factura['cliente'] ?? 'CONSUMIDOR FINAL');
$totalNumero = (float)($factura['total'] ?? 0);
$totalVista = number_format($totalNumero, 0, ',', '.');
$empresaNombre = (string)($empresaInfo['empresa'] ?? ('Empresa #' . $id_empresa));
$empresaRuc = trim((string)($empresaInfo['ruc'] ?? ''));
$empresaDv = trim((string)($empresaInfo['dv'] ?? ''));
$empresaTelefono = valorEmpresa($empresaInfo, ['telefono', 'telefono1', 'telefono_1', 'tel', 'celular', 'movil']);
$empresaEmail = valorEmpresa($empresaInfo, ['email', 'correo', 'correo_electronico', 'mail']);
$empresaDireccion = valorEmpresa($empresaInfo, ['direccion', 'direcc', 'direccion_fiscal', 'domicilio']);
if ($empresaRuc !== '' && $empresaDv !== '') {
    $empresaRuc .= '-' . $empresaDv;
}
$logoUrl = resolverLogoEmpresa($empresaInfo['logos'] ?? '', $projectRoot);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'sistemax.pro';
$consultaUrl = sprintf(
    '%s://%s/consultas/nota.php?id_factura=%d&id_empresa=%d',
    $scheme,
    $host,
    $id_factura,
    $id_empresa
);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' . rawurlencode($consultaUrl);
$ticketData = [
    'id' => (string)($factura['id'] ?? ''),
    'empresa' => $empresaNombre,
    'empresaNombre' => $empresaNombre,
    'empresaRuc' => $empresaRuc,
    'empresaTelefono' => $empresaTelefono,
    'empresaEmail' => $empresaEmail,
    'empresaDireccion' => $empresaDireccion,
    'cliente' => $clienteVista,
    'fecha' => $fechaVista,
    'total' => $totalNumero,
    'totalVista' => $totalVista,
    'url' => $consultaUrl,
    'qrUrl' => $qrUrl,
    'logoUrl' => $logoUrl,
];
?>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="ticketLanding(<?= htmlspecialchars(json_encode($ticketData, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">
    <div class="fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-24 -left-24 h-80 w-80 rounded-full bg-emerald-500/20 blur-3xl"></div>
        <div class="absolute right-0 top-24 h-96 w-96 rounded-full bg-cyan-500/20 blur-3xl"></div>
    </div>

    <main class="mx-auto flex w-full max-w-[1800px] flex-col gap-6 p-0 md:p-6 xl:p-8">
        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,3fr)_minmax(280px,1fr)] gap-0 xl:gap-6 items-start">
            <div class="space-y-6 order-2 xl:order-1">
                <section class="no-print rounded-none md:rounded-3xl border-0 md:border border-slate-800 bg-slate-900/88 p-6 shadow-none md:shadow-sm backdrop-blur-xl md:p-10">
                    <p class="mb-3 inline-flex rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-300">
                        Documento verificado
                    </p>
                    <h1 class="text-2xl font-semibold tracking-tight text-white md:text-4xl">
                        Consulta de Nota de Venta
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm text-slate-300 md:text-base">
                        Visualizá rápidamente los datos principales de la transacción y un preview del ticket antes de imprimir.
                    </p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <button
                            type="button"
                            @click="printTicket()"
                            class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-slate-700"
                        >
                            Imprimir ticket
                        </button>
                        <button
                            type="button"
                            @click="copyUrl()"
                            class="rounded-xl border border-slate-700 bg-slate-950 px-5 py-2.5 text-sm font-medium text-slate-200 transition hover:bg-slate-900"
                        >
                            Copiar enlace
                        </button>
                    </div>
                    <p x-cloak x-show="copied" class="mt-3 text-xs font-medium text-emerald-300">Enlace copiado al portapapeles.</p>
                </section>

                <section class="grid gap-6 lg:grid-cols-2">
                    <article class="rounded-none md:rounded-3xl border-0 md:border border-slate-800 bg-slate-900 p-6 shadow-none md:shadow-sm md:p-8">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-400">Resumen</h2>
                        <dl class="mt-5 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <dt class="text-sm text-slate-400">Nro. de documento</dt>
                        <dd class="text-sm font-semibold text-slate-100" x-text="ticket.id"></dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <dt class="text-sm text-slate-400">Empresa</dt>
                        <dd class="text-sm font-semibold text-slate-100" x-text="ticket.empresa"></dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <dt class="text-sm text-slate-400">Cliente</dt>
                        <dd class="text-sm font-semibold text-slate-100" x-text="ticket.cliente"></dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <dt class="text-sm text-slate-400">Fecha</dt>
                        <dd class="text-sm font-semibold text-slate-100" x-text="ticket.fecha"></dd>
                    </div>
                    <div x-show="ticket.empresaTelefono" class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <dt class="text-sm text-slate-400">Teléfono</dt>
                        <dd class="text-sm font-semibold text-slate-100" x-text="ticket.empresaTelefono"></dd>
                    </div>
                    <div x-show="ticket.empresaEmail" class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <dt class="text-sm text-slate-400">Email</dt>
                        <dd class="text-sm font-semibold text-slate-100" x-text="ticket.empresaEmail"></dd>
                    </div>
                    <div x-show="ticket.empresaDireccion" class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <dt class="text-sm text-slate-400">Dirección</dt>
                        <dd class="text-right text-sm font-semibold text-slate-100" x-text="ticket.empresaDireccion"></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-slate-400">Total</dt>
                        <dd class="text-lg font-semibold text-emerald-300" x-text="formatGs(ticket.total)"></dd>
                    </div>
                        </dl>
                    </article>

                    <article class="ticket-print rounded-none md:rounded-3xl border-0 md:border border-slate-800 bg-slate-900 p-6 shadow-none md:shadow-sm md:p-8">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-slate-400">Preview ticket</h2>
                            <span class="rounded-full bg-slate-800 px-3 py-1 text-xs font-medium text-slate-300">Venta</span>
                        </div>

                        <div class="mx-auto max-w-sm rounded-2xl border border-dashed border-slate-700 bg-slate-950 p-5 font-mono text-xs leading-5 text-slate-300">
                            <div class="text-center">
                                <p class="text-sm font-bold tracking-widest text-white">SISTEMAX PRO</p>
                                <p class="text-[11px] text-slate-500">Nota de venta</p>
                            </div>
                            <div class="my-3 border-t border-dashed border-slate-700"></div>

                            <div class="space-y-1">
                                <template x-if="ticket.logoUrl">
                                    <div class="mb-2 flex justify-center">
                                        <img :src="ticket.logoUrl" alt="Logo Empresa" class="max-h-14 w-auto object-contain">
                                    </div>
                                </template>
                                <p class="truncate text-center font-semibold text-slate-100" x-text="ticket.empresaNombre"></p>
                                <p class="text-center text-[11px] text-slate-500" x-text="ticket.empresaRuc ? 'RUC: ' + ticket.empresaRuc : ''"></p>
                                <p x-show="ticket.empresaTelefono" class="text-center text-[11px] text-slate-500" x-text="'Tel: ' + ticket.empresaTelefono"></p>
                                <p x-show="ticket.empresaEmail" class="truncate text-center text-[11px] text-slate-500" x-text="ticket.empresaEmail"></p>
                                <p x-show="ticket.empresaDireccion" class="line-clamp-2 text-center text-[11px] text-slate-500" x-text="ticket.empresaDireccion"></p>
                                <div class="my-2 border-t border-dashed border-slate-700"></div>
                                <p class="flex justify-between"><span>Ticket:</span> <span x-text="ticket.id"></span></p>
                                <p class="flex justify-between"><span>Empresa:</span> <span x-text="ticket.empresa"></span></p>
                                <p class="flex justify-between"><span>Fecha:</span> <span x-text="ticket.fecha"></span></p>
                            </div>

                            <div class="my-3 border-t border-dashed border-slate-700"></div>

                            <p class="truncate">Cliente: <span x-text="ticket.cliente"></span></p>
                            <p class="mt-2 flex justify-between text-sm font-bold text-white">
                                <span>TOTAL GS</span>
                                <span x-text="ticket.totalVista"></span>
                            </p>

                            <div class="my-3 border-t border-dashed border-slate-700"></div>
                            <div class="flex justify-center">
                                <img :src="ticket.qrUrl" alt="QR de consulta" class="h-24 w-24 rounded border border-slate-700 bg-white p-1">
                            </div>
                            <p class="mt-2 text-center text-[10px] text-slate-400">Escaneá para verificar</p>
                            <p class="text-center text-[11px] text-slate-500">Gracias por su compra</p>
                        </div>
                    </article>
                </section>
            </div>

            <aside class="px-4 py-5 md:px-0 xl:py-0 order-1 xl:order-2">
                <div class="xl:sticky xl:top-6 rounded-none md:rounded-3xl overflow-hidden border border-slate-800 bg-[radial-gradient(circle_at_top,#0f766e_0%,#0f172a_44%,#020617_100%)] shadow-2xl">
                    <div class="p-6 md:p-7">
                        <div class="inline-flex items-center gap-2 rounded-full border border-cyan-400/30 bg-cyan-400/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] text-cyan-200">
                            sistemax.pro
                        </div>
                        <h2 class="mt-5 text-2xl font-black leading-tight text-white">Convertí cada ticket y QR en una experiencia profesional.</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-300">
                            Ventas, POS, stock, SIFEN, compras y módulos on demand en una sola plataforma. Si te gusta esta experiencia, podés probar Sistemax.Pro.
                        </p>
                        <div class="mt-6 grid grid-cols-2 gap-3 text-xs">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                <p class="text-slate-400">Comparte</p>
                                <p class="mt-1 text-lg font-bold text-white">QR + WhatsApp</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                <p class="text-slate-400">Activa</p>
                                <p class="mt-1 text-lg font-bold text-white">On demand</p>
                            </div>
                        </div>
                        <div class="mt-6 space-y-3">
                            <a href="/public/suscribete.php?registro=1&v=20260327183227" class="block w-full rounded-2xl border border-cyan-300/30 bg-cyan-500/18 px-4 py-3 text-center text-sm font-bold text-cyan-100 shadow-[0_12px_30px_rgba(6,182,212,0.18)] transition hover:bg-cyan-400/24 hover:text-white">Probar ahora</a>
                            <a href="/public/video_demo_sistemax_stock.html" target="_blank" rel="noopener" class="block w-full rounded-2xl border border-white/15 bg-white/5 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-white/10">Ver video demo</a>
                            <a href="https://cliente.sistemax.pro" target="_blank" rel="noopener" class="block w-full rounded-2xl border border-white/10 px-4 py-3 text-center text-sm font-semibold text-slate-300 transition hover:border-cyan-300/40 hover:text-white">Ingresar al sistema</a>
                        </div>
                        <div class="mt-6 rounded-2xl border border-amber-300/15 bg-amber-300/10 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-200">Landing interactivo</p>
                            <p class="mt-2 text-sm text-amber-50">
                                El mismo esquema del ticket público, QR y branding puede quedar activo para tu negocio con Sistemax.Pro.
                            </p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <script>
        function ticketLanding(ticket) {
            return {
                ticket,
                copied: false,
                formatGs(value) {
                    const n = Number(value || 0);
                    return new Intl.NumberFormat('es-PY').format(n) + ' Gs';
                },
                printTicket() {
                    window.print();
                },
                async copyUrl() {
                    const url = window.location.href;
                    try {
                        await navigator.clipboard.writeText(url);
                        this.copied = true;
                        setTimeout(() => { this.copied = false; }, 2000);
                    } catch (e) {
                        this.copied = false;
                    }
                }
            };
        }
    </script>
</body>
</html>
