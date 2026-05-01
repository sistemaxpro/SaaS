<?php
http_response_code(410);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QZ Deprecado</title>
    <style>
        body { font-family: sans-serif; background:#0f172a; color:#e2e8f0; margin:0; display:grid; place-items:center; min-height:100vh; }
        .card { max-width:680px; background:#1e293b; border:1px solid #334155; border-radius:12px; padding:24px; }
        a { color:#60a5fa; }
    </style>
</head>
<body>
    <div class="card">
        <h1>QZ Tray deprecado</h1>
        <p>Este proyecto ya no usa QZ Tray. La impresión nativa se realiza con <strong>Sistemax Agent</strong>.</p>
        <p>Continuar en <a href="/public/pos/index.php">POS</a> y abrir configuración de impresión para instalar/conectar el agente.</p>
    </div>
</body>
</html>
