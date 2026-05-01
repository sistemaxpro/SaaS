<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';

$idCierre = (int)($_GET['id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));
$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
$autoprint = (int)($_GET['autoprint'] ?? 0) === 1;

if ($idCierre <= 0 || $token === '' || $idEmpresa <= 0) {
    http_response_code(400);
    echo 'Parámetros inválidos.';
    exit;
}

try {
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $stmt = $pdo->prepare("SELECT c.*, cj.caja
        FROM {$db}.caja_cierres c
        LEFT JOIN {$db}.cajas cj ON cj.id_caja = c.id_caja
        WHERE c.id = :id AND c.token_verificacion = :token
        LIMIT 1");
    $stmt->execute([':id' => $idCierre, ':token' => $token]);
    $cierre = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cierre) {
        http_response_code(404);
        echo 'Comprobante no encontrado o token inválido.';
        exit;
    }

    $stmtV = $pdo->prepare("SELECT denominacion, cantidad, subtotal FROM {$db}.caja_cierres_valores WHERE id_cierre = :id ORDER BY orden_item ASC, id ASC");
    $stmtV->execute([':id' => $idCierre]);
    $valores = $stmtV->fetchAll(PDO::FETCH_ASSOC);

    $resumen = json_decode((string)($cierre['resumen_json'] ?? '{}'), true);
    if (!is_array($resumen)) {
        $resumen = [];
    }

    $usuarioNombre = 'Usuario #' . (int)$cierre['id_login'];
    try {
        $master = getMasterConnection();
        $stmtU = $master->prepare("SELECT COALESCE(name, login) as nombre FROM sec_users WHERE id_login = :id LIMIT 1");
        $stmtU->execute([':id' => (int)$cierre['id_login']]);
        $usuarioNombre = $stmtU->fetchColumn() ?: $usuarioNombre;
    } catch (Exception $e) {
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verifyUrl = $scheme . '://' . $host . '/public/pos/cierre_comprobante.php?id=' . (int)$cierre['id'] . '&token=' . urlencode($token) . '&id_empresa=' . $idEmpresa;
    $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=130x130&chl=' . urlencode($verifyUrl);

    $fmt = function ($n) {
        return number_format((float)$n, 0, ',', '.');
    };

    $tipoDif = (string)($cierre['tipo_diferencia'] ?? 'CUADRE');
    $dif = (float)($cierre['diferencia'] ?? 0);

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error generando ticket: ' . htmlspecialchars($e->getMessage());
    exit;
}
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket Cierre Caja #<?php echo (int)$cierre['id']; ?></title>
    <style>
        body { font-family: monospace; font-size: 12px; margin: 0; padding: 0; }
        .ticket { width: 80mm; max-width: 80mm; margin: 0 auto; padding: 6px; box-sizing: border-box; }
        .center { text-align: center; }
        .right { text-align: right; }
        .line { border-top: 1px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 6px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .small { font-size: 10px; }
        .bold { font-weight: 700; }
        .print-actions { margin: 8px 0; text-align: center; }
        @media print {
            .print-actions { display: none; }
            body { margin: 0; }
            .ticket { width: 72mm; max-width: 72mm; }
        }
    </style>
</head>
<body>
<div class="ticket">
    <div class="center bold">CIERRE DE CAJA</div>
    <div class="center">Ticket #<?php echo (int)$cierre['id']; ?></div>
    <div class="center small"><?php echo htmlspecialchars((string)$cierre['fecha_cierre']); ?></div>

    <div class="line"></div>
    <div class="row"><span>Caja</span><span><?php echo htmlspecialchars((string)($cierre['caja'] ?? ('#' . $cierre['id_caja']))); ?></span></div>
    <div class="row"><span>Cajero</span><span><?php echo htmlspecialchars((string)$usuarioNombre); ?></span></div>
    <div class="row"><span>Supervisor</span><span><?php echo htmlspecialchars((string)$cierre['supervisor_nombre']); ?></span></div>

    <div class="line"></div>
    <table>
        <tr><td>Entradas</td><td class="right"><?php echo $fmt($resumen['total_entradas'] ?? 0); ?></td></tr>
        <tr><td>Salidas</td><td class="right"><?php echo $fmt($resumen['total_salidas'] ?? 0); ?></td></tr>
        <tr><td>Saldo Sistema</td><td class="right"><?php echo $fmt($cierre['saldo_sistema'] ?? 0); ?></td></tr>
        <tr><td>Contado</td><td class="right"><?php echo $fmt($cierre['efectivo_contado'] ?? 0); ?></td></tr>
        <tr><td><?php echo htmlspecialchars($tipoDif); ?></td><td class="right"><?php echo $fmt(abs($dif)); ?></td></tr>
        <tr><td class="bold">Entregado</td><td class="right bold"><?php echo $fmt($cierre['monto_entregado_supervisor'] ?? 0); ?></td></tr>
    </table>

    <div class="line"></div>
    <div class="bold">Valores entregados</div>
    <?php if (count($valores) === 0): ?>
        <div class="small">Sin detalle</div>
    <?php else: ?>
        <?php foreach ($valores as $v): ?>
            <?php if ((int)$v['cantidad'] <= 0) continue; ?>
            <div class="row small">
                <span><?php echo $fmt($v['denominacion']); ?> x <?php echo (int)$v['cantidad']; ?></span>
                <span><?php echo $fmt($v['subtotal']); ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($cierre['observacion'])): ?>
        <div class="line"></div>
        <div class="small">Obs: <?php echo htmlspecialchars((string)$cierre['observacion']); ?></div>
    <?php endif; ?>

    <div class="line"></div>
    <div class="center"><img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR" width="120" height="120"></div>
    <div class="center small">Verificación</div>

    <div class="line"></div>
    <div class="center small">Firma Cajero: ____________________</div>
    <div class="center small" style="margin-top:10px;">Firma Supervisor: __________________</div>

    <div class="print-actions">
        <button onclick="window.print()">Imprimir Ticket</button>
    </div>
</div>
<?php if ($autoprint): ?>
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
</script>
<?php endif; ?>
</body>
</html>
