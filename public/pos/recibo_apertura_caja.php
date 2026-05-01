<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';

$id = (int)($_GET['id'] ?? 0);
$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
$idCajaSesion = (int)($_SESSION['id_caja_def'] ?? 0);
$autoprint = (int)($_GET['autoprint'] ?? 0) === 1;

if ($id <= 0 || $idEmpresa <= 0) {
    http_response_code(400);
    echo 'Parámetros inválidos.';
    exit;
}

try {
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.codigo AS id_caja,
            e.fecha,
            e.concepto,
            e.credito,
            e.debito,
            e.estado,
            e.login,
            e.id_login,
            e.comprobante,
            e.medio_cobro,
            e.referencia,
            o.operacion AS operacion_nombre,
            r.referencia AS referencia_nombre,
            c.caja AS caja_nombre
        FROM {$db}.extracto_caja e
        LEFT JOIN " . MASTER_DB . ".operaciones o ON o.id = e.operacion
        LEFT JOIN " . MASTER_DB . ".referencia r ON r.id = e.referencia
        LEFT JOIN {$db}.cajas c ON c.id_caja = e.codigo
        WHERE e.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$op) {
        http_response_code(404);
        echo 'Registro no encontrado.';
        exit;
    }

    // Seguridad mínima: permitir ver solo recibos de la caja activa en sesión (si aplica)
    if ($idCajaSesion > 0 && (int)$op['id_caja'] !== $idCajaSesion) {
        http_response_code(403);
        echo 'No autorizado para este registro.';
        exit;
    }

    $refNombre = trim((string)($op['referencia_nombre'] ?? ''));
    $concepto = trim((string)($op['concepto'] ?? ''));
    $isApertura = ((int)($op['referencia'] ?? 0) === 16)
        || preg_match('/^ap(e)?rtura de c?a?ja$/i', $refNombre)
        || preg_match('/apertura de c?a?ja/i', $concepto);

    if (!$isApertura) {
        http_response_code(400);
        echo 'El registro no corresponde a referencia Apertura de caja.';
        exit;
    }

    $usuarioNombre = 'Usuario #' . (int)$op['id_login'];
    $empresaNombre = 'Empresa';
    $empresaRuc = '';
    try {
        $master = getMasterConnection();
        $stmtU = $master->prepare("SELECT COALESCE(name, login) as nombre FROM sec_users WHERE id_login = :id LIMIT 1");
        $stmtU->execute([':id' => (int)$op['id_login']]);
        $usuarioNombre = $stmtU->fetchColumn() ?: $usuarioNombre;

        $stmtE = $master->prepare("SELECT empresa, ruc, dv FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
        $stmtE->execute([':id' => $idEmpresa]);
        $emp = $stmtE->fetch(PDO::FETCH_ASSOC);
        if ($emp) {
            $empresaNombre = (string)($emp['empresa'] ?? $empresaNombre);
            $ruc = trim((string)($emp['ruc'] ?? ''));
            $dv = trim((string)($emp['dv'] ?? ''));
            $empresaRuc = $ruc !== '' ? ($ruc . ($dv !== '' ? '-' . $dv : '')) : '';
        }
    } catch (Exception $e) {
        // fallback silencioso
    }

    $monto = (float)($op['credito'] > 0 ? $op['credito'] : $op['debito']);
    $tipoMonto = $op['credito'] > 0 ? 'Ingreso' : 'Salida';
    $estadoTxt = ((int)$op['estado'] === 1) ? 'Activo' : 'Anulado';
    $referenciaTxt = 'Apertura de caja';
    $operacionTxt = trim((string)($op['operacion_nombre'] ?? 'Entrada de caja'));
    $medioTxt = strtoupper(trim((string)($op['medio_cobro'] ?? 'EFECTIVO')));
    if ($medioTxt === '') $medioTxt = 'EFECTIVO';
    $comprobanteTxt = trim((string)($op['comprobante'] ?? ''));
    if ($comprobanteTxt === '') $comprobanteTxt = 'N/A';
    $cajaTxt = trim((string)($op['caja_nombre'] ?? ''));
    if ($cajaTxt === '') $cajaTxt = 'Caja #' . (int)$op['id_caja'];

    $fmt = function ($n) {
        return number_format((float)$n, 0, ',', '.');
    };

    $fecha = (string)$op['fecha'];
    $fechaTxt = $fecha !== '' ? date('d/m/Y H:i:s', strtotime($fecha)) : '';
    $reciboNro = 'AP-' . str_pad((string)$op['id'], 8, '0', STR_PAD_LEFT);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verifyUrl = $scheme . '://' . $host . '/public/pos/recibo_apertura_caja.php?id=' . (int)$op['id'] . '&id_empresa=' . $idEmpresa;
    $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=130x130&chl=' . urlencode($verifyUrl);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error generando recibo: ' . htmlspecialchars($e->getMessage());
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo Apertura Caja #<?php echo (int)$op['id']; ?></title>
    <style>
        body { font-family: monospace; font-size: 12px; margin: 0; padding: 0; background: #fff; color: #000; }
        .ticket { width: 80mm; max-width: 80mm; margin: 0 auto; padding: 6px; box-sizing: border-box; }
        .center { text-align: center; }
        .right { text-align: right; }
        .line { border-top: 1px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 6px; }
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
    <div class="center bold"><?php echo htmlspecialchars($empresaNombre); ?></div>
    <?php if ($empresaRuc !== ''): ?>
        <div class="center small">RUC: <?php echo htmlspecialchars($empresaRuc); ?></div>
    <?php endif; ?>
    <div class="line"></div>

    <div class="center bold">RECIBO DE DINERO</div>
    <div class="center bold"><?php echo htmlspecialchars($referenciaTxt); ?></div>
    <div class="center small">Nro: <?php echo htmlspecialchars($reciboNro); ?></div>
    <div class="center small">Fecha: <?php echo htmlspecialchars($fechaTxt); ?></div>

    <div class="line"></div>
    <div class="row"><span>Documento ID</span><span>#<?php echo (int)$op['id']; ?></span></div>
    <div class="row"><span>Caja</span><span><?php echo htmlspecialchars($cajaTxt); ?></span></div>
    <div class="row"><span>Cajero</span><span><?php echo htmlspecialchars($usuarioNombre); ?></span></div>
    <div class="row"><span>Operación</span><span><?php echo htmlspecialchars($operacionTxt); ?></span></div>
    <div class="row"><span>Referencia</span><span><?php echo htmlspecialchars($referenciaTxt); ?></span></div>
    <div class="row"><span>Medio</span><span><?php echo htmlspecialchars($medioTxt); ?></span></div>
    <div class="row"><span>Comprobante</span><span><?php echo htmlspecialchars($comprobanteTxt); ?></span></div>
    <div class="row"><span>Estado</span><span><?php echo htmlspecialchars($estadoTxt); ?></span></div>

    <div class="line"></div>
    <div class="small">Concepto</div>
    <div class="bold"><?php echo htmlspecialchars($concepto !== '' ? $concepto : 'APERTURA DE CAJA'); ?></div>

    <div class="line"></div>
    <div class="row"><span><?php echo htmlspecialchars($tipoMonto); ?></span><span class="bold">Gs. <?php echo $fmt($monto); ?></span></div>
    <div class="row"><span class="bold">Monto recibido</span><span class="bold">Gs. <?php echo $fmt($monto); ?></span></div>

    <div class="line"></div>
    <div class="center"><img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR verificación" width="120" height="120"></div>
    <div class="center small">Verificación del documento</div>

    <div class="line"></div>
    <div class="center small">Firma Cajero: ____________________</div>
    <div class="center small" style="margin-top:10px;">Firma Responsable: ________________</div>

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
