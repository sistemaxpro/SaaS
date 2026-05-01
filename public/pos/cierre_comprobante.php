<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';

$idCierre = (int)($_GET['id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));
$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);

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

    $usuarioNombre = 'Usuario #' . (int)$cierre['id_login'];
    try {
        $master = getMasterConnection();
        $stmtU = $master->prepare("SELECT COALESCE(name, login) as nombre FROM sec_users WHERE id_login = :id LIMIT 1");
        $stmtU->execute([':id' => (int)$cierre['id_login']]);
        $usuarioNombre = $stmtU->fetchColumn() ?: $usuarioNombre;
    } catch (Exception $e) {
        // Mantener fallback
    }

    $resumen = json_decode((string)($cierre['resumen_json'] ?? '{}'), true);
    if (!is_array($resumen)) {
        $resumen = [];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verifyUrl = $scheme . '://' . $host . '/public/pos/cierre_comprobante.php?id=' . (int)$cierre['id'] . '&token=' . urlencode($token) . '&id_empresa=' . $idEmpresa;
    $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=220x220&chl=' . urlencode($verifyUrl);

    $fmt = function ($n) {
        return number_format((float)$n, 0, ',', '.');
    };

    $tipoDif = (string)($cierre['tipo_diferencia'] ?? 'CUADRE');
    $dif = (float)($cierre['diferencia'] ?? 0);
    $difTxt = $tipoDif . ' Gs. ' . $fmt(abs($dif));

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error generando comprobante: ' . htmlspecialchars($e->getMessage());
    exit;
}
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante de Cierre de Caja #<?php echo (int)$cierre['id']; ?></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 20px; }
        .card { border: 1px solid #222; border-radius: 10px; padding: 16px; }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 2px; }
        .sub { font-size: 12px; color: #444; margin-bottom: 10px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #555; padding: 6px 8px; font-size: 12px; }
        th { background: #f2f2f2; text-align: left; }
        .right { text-align: right; }
        .muted { color: #555; font-size: 11px; }
        .row { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; margin: 4px 0; }
        .bold { font-weight: 700; }
        .sign { margin-top: 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
        .sign-box { border-top: 1px solid #111; text-align: center; padding-top: 6px; font-size: 12px; min-height: 72px; }
        .qr { text-align: center; }
        .print-actions { margin-top: 12px; }
        @media print {
            .print-actions { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="title">Comprobante de Cierre de Caja</div>
    <div class="sub">Nro cierre: #<?php echo (int)$cierre['id']; ?> | Fecha: <?php echo htmlspecialchars($cierre['fecha_cierre']); ?></div>

    <div class="grid">
        <div>
            <div class="row"><span class="muted">Caja</span><span class="bold"><?php echo htmlspecialchars((string)($cierre['caja'] ?? ('Caja #' . $cierre['id_caja']))); ?></span></div>
            <div class="row"><span class="muted">Cajero</span><span class="bold"><?php echo htmlspecialchars((string)$usuarioNombre); ?></span></div>
            <div class="row"><span class="muted">Supervisor</span><span class="bold"><?php echo htmlspecialchars((string)$cierre['supervisor_nombre']); ?></span></div>
            <div class="row"><span class="muted">Estado cuadre</span><span class="bold"><?php echo htmlspecialchars($difTxt); ?></span></div>
        </div>
        <div class="qr">
            <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR verificación" width="160" height="160">
            <div class="muted">QR de verificación</div>
        </div>
    </div>

    <h4>Resumen Operativo</h4>
    <table>
        <tr><th>Concepto</th><th class="right">Monto (Gs.)</th></tr>
        <tr><td>Total Entradas</td><td class="right"><?php echo $fmt($resumen['total_entradas'] ?? 0); ?></td></tr>
        <tr><td>Total Salidas</td><td class="right"><?php echo $fmt($resumen['total_salidas'] ?? 0); ?></td></tr>
        <tr><td>Saldo Sistema</td><td class="right"><?php echo $fmt($cierre['saldo_sistema'] ?? 0); ?></td></tr>
        <tr><td>Efectivo Contado</td><td class="right"><?php echo $fmt($cierre['efectivo_contado'] ?? 0); ?></td></tr>
        <tr><td>Ajuste (faltante/sobrante)</td><td class="right"><?php echo $fmt($cierre['monto_ajuste'] ?? 0); ?></td></tr>
        <tr><td class="bold">Entregado a Supervisor</td><td class="right bold"><?php echo $fmt($cierre['monto_entregado_supervisor'] ?? 0); ?></td></tr>
    </table>

    <h4>Valores Entregados</h4>
    <table>
        <tr><th>Denominación</th><th class="right">Cantidad</th><th class="right">Subtotal</th></tr>
        <?php if (count($valores) === 0): ?>
            <tr><td colspan="3" class="right">Sin detalle de valores</td></tr>
        <?php else: ?>
            <?php foreach ($valores as $v): ?>
                <tr>
                    <td>Gs. <?php echo $fmt($v['denominacion']); ?></td>
                    <td class="right"><?php echo (int)$v['cantidad']; ?></td>
                    <td class="right"><?php echo $fmt($v['subtotal']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <div class="row" style="margin-top:10px;"><span class="muted">Observación</span><span><?php echo htmlspecialchars((string)($cierre['observacion'] ?? '')); ?></span></div>
    <div class="row"><span class="muted">URL Verificación</span><span class="muted"><?php echo htmlspecialchars($verifyUrl); ?></span></div>

    <div class="sign">
        <div class="sign-box">
            Firma Cajero<br>
            <span class="muted"><?php echo htmlspecialchars((string)$usuarioNombre); ?></span>
        </div>
        <div class="sign-box">
            Firma Supervisor<br>
            <span class="muted"><?php echo htmlspecialchars((string)$cierre['supervisor_nombre']); ?></span>
        </div>
    </div>

    <div class="print-actions">
        <button onclick="window.print()">Imprimir</button>
    </div>
</div>
</body>
</html>
