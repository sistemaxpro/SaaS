<?php
/**
 * Ticket de CALIBRACIÓN — Página HTML autocontenida
 * Conecta a Sistemax Agent, detecta impresoras, e imprime test de ancho.
 */

// Si piden JSON (API), devolver datos raw
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['success' => true, 'data' => base64_encode(generarTestCalib()), 'format' => 'escpos', 'tipo' => 'calibracion']);
    exit;
}

function generarTestCalib() {
    $ESC = "\x1B"; $GS = "\x1D"; $LF = "\x0A"; $o = '';
    $o .= $ESC . "@";
    $o .= $ESC . "t\x10";
    $o .= $ESC . "a\x01";
    $o .= $ESC . "!\x18";
    $o .= "TEST DE CALIBRACION" . $LF;
    $o .= $ESC . "!\x00";
    $o .= "Impresora 80mm" . $LF;
    $o .= $ESC . "a\x00";
    $o .= $LF;
    $o .= $ESC . "E\x01";
    $o .= "La linea de ==== que llene" . $LF;
    $o .= "EXACTO el ancho del papel" . $LF;
    $o .= "es tu WIDTH correcto." . $LF;
    $o .= $ESC . "E\x00";
    $o .= $LF;
    foreach ([32, 36, 40, 42, 44, 46, 48, 52, 56] as $w) {
        $o .= $ESC . "E\x01";
        $o .= "--- WIDTH = {$w} ---" . $LF;
        $o .= $ESC . "E\x00";
        $nums = '';
        for ($i = 1; $i <= $w; $i++) $nums .= ($i % 10);
        $o .= $nums . $LF;
        $o .= str_repeat('=', $w) . $LF;
        $o .= $LF;
    }
    $o .= $ESC . "a\x01";
    $o .= $ESC . "E\x01";
    $o .= "Usa el width cuya linea de" . $LF;
    $o .= "==== llene TODO el papel." . $LF;
    $o .= $ESC . "E\x00";
    $o .= $ESC . "a\x00";
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00";
    return $o;
}

$ticketBase64 = base64_encode(generarTestCalib());
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calibración de Impresora</title>
    <script src="/public/pos/js/smx-printer.js?v=1"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #1e293b; border-radius: 16px; padding: 32px; max-width: 480px; width: 90%; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        h1 { font-size: 24px; margin-bottom: 8px; text-align: center; }
        .subtitle { text-align: center; color: #94a3b8; font-size: 14px; margin-bottom: 24px; }
        .status { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; text-align: center; }
        .status.info { background: #1e3a5f; color: #93c5fd; }
        .status.success { background: #14532d; color: #86efac; }
        .status.error { background: #7f1d1d; color: #fca5a5; }
        .status.warning { background: #713f12; color: #fde68a; }
        select, button { width: 100%; padding: 14px; border-radius: 12px; font-size: 15px; font-weight: 600; border: none; cursor: pointer; margin-bottom: 12px; }
        select { background: #334155; color: #e2e8f0; appearance: none; }
        .btn-print { background: #059669; color: white; }
        .btn-print:hover { background: #047857; }
        .btn-print:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-connect { background: #2563eb; color: white; }
        .btn-connect:hover { background: #1d4ed8; }
        .instructions { background: #0f172a; border-radius: 10px; padding: 16px; margin-top: 16px; font-size: 13px; color: #94a3b8; line-height: 1.6; }
        .instructions strong { color: #fbbf24; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin .6s linear infinite; margin-right: 8px; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="card">
    <h1>📏 Calibración de Impresora</h1>
    <p class="subtitle">Determina el ancho real (columnas) de tu impresora térmica</p>

    <div id="status" class="status info">Conectando a Sistemax Agent...</div>

    <select id="printerSelect" disabled>
        <option value="">-- Seleccionar impresora --</option>
    </select>

    <button id="btnPrint" class="btn-print" disabled onclick="imprimir()">
        🖨️ Imprimir Test de Calibración
    </button>

    <button id="btnConnect" class="btn-connect" style="display:none" onclick="conectar()">
        🔄 Reintentar Conexión
    </button>

    <div class="instructions">
        <strong>¿Cómo funciona?</strong><br>
        Se imprimirán líneas de diferentes anchos (32 a 56 columnas).<br>
        La línea de <strong>====</strong> que llene <strong>EXACTO</strong> el ancho del papel (sin cortar, sin sobrar) es tu <strong>width</strong> correcto.<br><br>
        <strong>Requisitos:</strong> Sistemax Agent activo + impresora encendida.
    </div>
</div>

<script>
const TICKET_DATA = '<?= $ticketBase64 ?>';
let smxPrinter = null;

function setStatus(msg, type) {
    const el = document.getElementById('status');
    el.textContent = msg;
    el.className = 'status ' + type;
}

async function conectar() {
    const btnC = document.getElementById('btnConnect');
    btnC.style.display = 'none';
    setStatus('Conectando a impresión local...', 'info');

    try {
        if (typeof SmxPrinter === 'undefined') throw new Error('Bridge de impresión no cargado');
        smxPrinter = new SmxPrinter({
            strategy: 'agent-only',
            agentBaseUrl: 'http://127.0.0.1:17890'
        });
        await smxPrinter.connect();
        setStatus('✅ Conectado (' + smxPrinter.getProviderLabel() + ')', 'success');

        // Listar impresoras
        const printers = await smxPrinter.findPrinters();
        const sel = document.getElementById('printerSelect');
        sel.innerHTML = '<option value="">-- Seleccionar impresora --</option>';
        printers.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p; opt.textContent = p;
            sel.appendChild(opt);
        });
        sel.disabled = false;
        sel.onchange = () => { document.getElementById('btnPrint').disabled = !sel.value; };

        // Auto-seleccionar si solo hay una
        if (printers.length === 1) {
            sel.value = printers[0];
            document.getElementById('btnPrint').disabled = false;
        }
    } catch(e) {
        setStatus('❌ ' + e.message, 'error');
        btnC.style.display = 'block';
    }
}

async function imprimir() {
    const printer = document.getElementById('printerSelect').value;
    if (!printer) { setStatus('Seleccioná una impresora', 'warning'); return; }
    if (!smxPrinter) { setStatus('Bridge no inicializado', 'error'); return; }

    const btn = document.getElementById('btnPrint');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Enviando...';
    setStatus('Enviando test a ' + printer + '...', 'info');

    try {
        await smxPrinter.printRaw(printer, TICKET_DATA);
        setStatus('✅ Test enviado a ' + printer + ' (' + smxPrinter.getProviderLabel() + ')', 'success');
    } catch(e) {
        setStatus('❌ Error: ' + e.message, 'error');
    }
    btn.disabled = false;
    btn.innerHTML = '🖨️ Imprimir Test de Calibración';
}

// Auto-conectar al cargar
conectar();
</script>
</body>
</html>
