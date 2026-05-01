<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Impresora Golink</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #1a1a2e; color: white; }
        .box { background: #16213e; padding: 15px; margin: 10px 0; border-radius: 10px; }
        .ok { color: #4ade80; }
        .no { color: #f87171; }
        button { width: 100%; padding: 15px; margin: 5px 0; border: none; border-radius: 10px; font-size: 16px; font-weight: bold; }
        .btn-blue { background: #3b82f6; color: white; }
        .btn-green { background: #22c55e; color: white; }
        .btn-orange { background: #f97316; color: white; }
        h2 { margin-top: 20px; }
        pre { background: #0f0f23; padding: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
    <h1>🖨️ Test Impresora Golink</h1>
    
    <div class="box">
        <h2>Bridges Detectados:</h2>
        <div id="bridges"></div>
    </div>
    
    <div class="box">
        <h2>Probar URL Schemes:</h2>
        <p style="font-size:12px; color:#aaa;">Prueba cada botón. Si abre la app de impresora, ese es el correcto.</p>
        
        <button class="btn-orange" onclick="testURL('possteward://print?data=' + testData)">possteward://print?data=</button>
        <button class="btn-orange" onclick="testURL('possteward://base64/' + testData)">possteward://base64/</button>
        <button class="btn-orange" onclick="testURL('possteward://' + testData)">possteward://[data]</button>
        
        <button class="btn-blue" onclick="testURL('golink://print?data=' + testData)">golink://print?data=</button>
        <button class="btn-blue" onclick="testURL('golink://base64/' + testData)">golink://base64/</button>
        
        <button class="btn-blue" onclick="testURL('printer://print/' + testData)">printer://print/</button>
        <button class="btn-blue" onclick="testURL('print://' + testData)">print://</button>
        
        <button class="btn-blue" onclick="testURL('pos://print/' + testData)">pos://print/</button>
        <button class="btn-blue" onclick="testURL('escpos://' + testData)">escpos://</button>
        
        <button class="btn-blue" onclick="testURL('intent://print#Intent;scheme=possteward;S.data=' + testData + ';end')">Intent possteward</button>
        <button class="btn-blue" onclick="testURL('intent://print#Intent;scheme=golink;S.data=' + testData + ';end')">Intent golink</button>
        <button class="btn-blue" onclick="testURL('intent:#Intent;action=android.intent.action.SEND;type=text/plain;S.android.intent.extra.TEXT=' + testData + ';end')">Intent SEND</button>
        
        <button class="btn-green" onclick="testURL('rawbt:base64,' + testData)">✓ RawBT (referencia)</button>
    </div>
    
    <div class="box">
        <h2>Log:</h2>
        <pre id="log"></pre>
    </div>

    <script>
        // Texto de prueba ESC/POS en base64
        const testData = btoa('\x1B@\x1Ba\x01\x1BE\x01PRUEBA GOLINK\n\x1BE\x00----------------\nImpresora OK!\nSistemaX POS\n\n\n\n\x1DVA');
        
        function log(msg) {
            document.getElementById('log').innerHTML += msg + '\n';
        }
        
        // Detectar bridges disponibles
        function detectBridges() {
            const bridges = {
                'window.android': typeof window.android,
                'window.android.print': typeof window.android !== 'undefined' ? typeof window.android.print : 'N/A',
                'window.PrinterPlugin': typeof window.PrinterPlugin,
                'window.Printer': typeof window.Printer,
                'window.JSBridge': typeof window.JSBridge,
                'window.possteward': typeof window.possteward,
                'window.golink': typeof window.golink,
                'window.WebViewJavascriptBridge': typeof window.WebViewJavascriptBridge,
                'window.jsBridge': typeof window.jsBridge,
                'window.NativeApp': typeof window.NativeApp,
                'window.Native': typeof window.Native,
                'window.App': typeof window.App,
            };
            
            let html = '';
            for (let [name, type] of Object.entries(bridges)) {
                const available = type !== 'undefined' && type !== 'N/A';
                html += `<div class="${available ? 'ok' : 'no'}">${available ? '✅' : '❌'} ${name}: ${type}</div>`;
            }
            
            // Buscar cualquier objeto que tenga método print
            const allKeys = Object.keys(window).filter(k => {
                try {
                    return typeof window[k] === 'object' && window[k] !== null && typeof window[k].print === 'function';
                } catch(e) { return false; }
            });
            
            if (allKeys.length > 0) {
                html += '<div class="ok" style="font-size:18px; padding:10px; background:#065f46;">✅ ENCONTRADO: ' + allKeys.join(', ') + '</div>';
                allKeys.forEach(k => {
                    html += '<button class="btn-green" onclick="tryPrintObject(\'' + k + '\')" style="margin:5px 0;">PROBAR: ' + k + '.print()</button>';
                });
            }
            
            // Buscar TODOS los objetos del window para debug
            html += '<div style="margin-top:15px;"><strong>Todos los objetos window:</strong></div>';
            const interesting = Object.keys(window).filter(k => {
                try {
                    const t = typeof window[k];
                    return t === 'object' && window[k] !== null && !k.startsWith('webkit');
                } catch(e) { return false; }
            }).slice(0, 30);
            html += '<div style="font-size:11px; color:#888;">' + interesting.join(', ') + '</div>';
            
            document.getElementById('bridges').innerHTML = html;
            log('Bridges detectados');
        }
        
        function tryPrintObject(objName) {
            log('Probando ' + objName + '.print()...');
            try {
                window[objName].print(testData);
                log('✅ Enviado con ' + objName);
            } catch(e) {
                log('❌ Error: ' + e.message);
            }
        }
        
        function testURL(url) {
            log('Probando: ' + url.substring(0, 50) + '...');
            window.location.href = url;
        }
        
        // Iniciar
        detectBridges();
    </script>
</body>
</html>
