<?php
/**
 * QZ Tray - Previsualizador de tickets ESC/POS
 * Muestra el ticket tal como saldría impreso, en el navegador.
 * 
 * URL: https://sistemax.pro/public/pos/ticket_preview.php?id=123&tipo=comun
 * 
 * Parámetros:
 *   id    - ID de factura (requerido)
 *   tipo  - comun | electro | auto (default: comun)
 *   width - ancho columnas: 32 | 42 | 48 (default: 32)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$id = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? 'comun';
$width = (int)($_GET['width'] ?? 32);
$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

// Determinar endpoint
$endpoints = [
    'comun'   => 'ticket_nota_comun.php',
    'electro' => 'ticket_factura_electronica.php',
    'auto'    => 'ticket_factura_autoimpresa.php',
];
$endpoint = $endpoints[$tipo] ?? $endpoints['comun'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Ticket #<?= $id ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            background: #1e293b; 
            color: #e2e8f0; 
            font-family: -apple-system, sans-serif; 
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        h1 { color: #60a5fa; margin-bottom: 16px; font-size: 18px; }
        .controls {
            display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; justify-content: center;
        }
        .controls select, .controls input, .controls button {
            padding: 8px 12px; border-radius: 6px; border: 1px solid #475569;
            background: #334155; color: #e2e8f0; font-size: 14px;
        }
        .controls button { background: #3b82f6; cursor: pointer; border: none; }
        .controls button:hover { background: #2563eb; }
        
        /* Ticket paper simulation */
        .ticket-paper {
            background: #fff;
            color: #000;
            font-family: 'Courier New', 'Courier', 'Lucida Console', monospace;
            font-size: 13px;
            line-height: 1.3;
            padding: 20px 16px;
            width: 320px;
            min-height: 200px;
            border-radius: 4px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
            white-space: pre;
            overflow-x: hidden;
            word-wrap: break-word;
            /* Paper edges effect */
            border-bottom: 3px dashed #ccc;
            position: relative;
        }
        .ticket-paper::before {
            content: '';
            position: absolute;
            top: -8px; left: 10%; right: 10%;
            height: 8px;
            background: repeating-linear-gradient(90deg, transparent, transparent 4px, #ddd 4px, #ddd 5px);
        }
        .ticket-paper .bold { font-weight: bold; }
        .ticket-paper .center { text-align: center; }
        .ticket-paper .right { text-align: right; }
        .ticket-paper .big { font-size: 18px; font-weight: bold; }
        .ticket-paper .double-h { font-size: 16px; font-weight: bold; }
        
        .error { color: #ef4444; background: #1e293b; padding: 16px; border-radius: 8px; border: 1px solid #ef4444; max-width: 400px; }
        .loading { color: #f59e0b; }
        .meta { color: #94a3b8; font-size: 12px; margin-top: 12px; text-align: center; }
    </style>
</head>
<body>
    <h1>🖨️ Preview Ticket ESC/POS</h1>
    
    <div class="controls">
        <label style="color:#94a3b8;line-height:36px;">ID:</label>
        <input type="number" id="inputId" value="<?= $id ?>" style="width:80px;">
        <select id="selectTipo">
            <option value="comun" <?= $tipo==='comun'?'selected':'' ?>>Nota Común</option>
            <option value="electro" <?= $tipo==='electro'?'selected':'' ?>>Factura Electrónica</option>
            <option value="auto" <?= $tipo==='auto'?'selected':'' ?>>Autoimpresa</option>
        </select>
        <select id="selectWidth">
            <option value="32" <?= $width==32?'selected':'' ?>>32 col</option>
            <option value="42" <?= $width==42?'selected':'' ?>>42 col</option>
            <option value="48" <?= $width==48?'selected':'' ?>>48 col</option>
        </select>
        <button onclick="loadTicket()">🔄 Cargar</button>
    </div>

    <div class="ticket-paper" id="ticketArea">
        <span class="loading">⏳ Cargando ticket...</span>
    </div>
    
    <div class="meta" id="metaInfo"></div>

    <script>
        const endpoints = {
            'comun':   'ticket_nota_comun.php',
            'electro': 'ticket_factura_electronica.php',
            'auto':    'ticket_factura_autoimpresa.php'
        };

        async function loadTicket() {
            const id = document.getElementById('inputId').value;
            const tipo = document.getElementById('selectTipo').value;
            const width = document.getElementById('selectWidth').value;
            const area = document.getElementById('ticketArea');
            const meta = document.getElementById('metaInfo');

            if (!id || id === '0') {
                area.innerHTML = '<span style="color:red">⚠️ Ingresá un ID de factura válido</span>';
                return;
            }

            area.innerHTML = '<span class="loading">⏳ Cargando...</span>';

            try {
                const endpoint = endpoints[tipo] || endpoints['comun'];
                const url = `${endpoint}?id=${id}&id_empresa=<?= $id_empresa ?>&width=${width}`;
                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) {
                    area.innerHTML = `<span style="color:red">❌ ${data.message || 'Error'}</span>`;
                    return;
                }

                // Decodificar base64 a bytes
                const raw = atob(data.data);
                const html = escposToHtml(raw, parseInt(width));
                area.innerHTML = html;
                
                // Update paper width based on columns
                const charWidth = 7.8; // px per char in monospace
                area.style.width = (parseInt(width) * charWidth + 40) + 'px';

                meta.textContent = `Tipo: ${data.tipo || tipo} | ${data.data.length} chars base64 | ${raw.length} bytes raw | ${width} columnas`;

                // Update URL without reload
                const newUrl = `?id=${id}&tipo=${tipo}&width=${width}&id_empresa=<?= $id_empresa ?>`;
                history.replaceState(null, '', newUrl);
            } catch (e) {
                area.innerHTML = `<span style="color:red">❌ Error: ${e.message}</span>`;
            }
        }

        /**
         * Parsea ESC/POS raw bytes y genera HTML visual
         */
        function escposToHtml(raw, width) {
            let html = '';
            let align = 'left'; // current alignment: left, center, right
            let bold = false;
            let bigMode = false;   // double width+height
            let doubleH = false;   // double height only
            let i = 0;

            function openSpan() {
                let classes = [];
                if (bold) classes.push('bold');
                if (align === 'center') classes.push('center');
                if (align === 'right') classes.push('right');
                if (bigMode) classes.push('big');
                else if (doubleH) classes.push('double-h');
                
                if (classes.length) {
                    return `<div class="${classes.join(' ')}">`;
                }
                return '<div>';
            }

            let currentLine = '';
            
            function flushLine() {
                if (currentLine.length > 0) {
                    html += openSpan() + escapeHtml(currentLine) + '</div>';
                    currentLine = '';
                }
            }

            while (i < raw.length) {
                const b = raw.charCodeAt(i);

                // ESC commands (\x1B)
                if (b === 0x1B && i + 1 < raw.length) {
                    const cmd = raw.charCodeAt(i + 1);
                    
                    // ESC @ - Initialize printer
                    if (cmd === 0x40) { 
                        i += 2; align = 'left'; bold = false; bigMode = false; doubleH = false;
                        continue; 
                    }
                    // ESC a n - Alignment (0=left, 1=center, 2=right)
                    if (cmd === 0x61 && i + 2 < raw.length) {
                        flushLine();
                        const n = raw.charCodeAt(i + 2);
                        align = n === 1 ? 'center' : n === 2 ? 'right' : 'left';
                        i += 3; continue;
                    }
                    // ESC ! n - Print mode
                    if (cmd === 0x21 && i + 2 < raw.length) {
                        flushLine();
                        const n = raw.charCodeAt(i + 2);
                        bold = !!(n & 0x08);
                        const dblW = !!(n & 0x20);
                        const dblH = !!(n & 0x10);
                        bigMode = dblW && dblH;
                        doubleH = !bigMode && dblH;
                        i += 3; continue;
                    }
                    // ESC E n - Bold on/off
                    if (cmd === 0x45 && i + 2 < raw.length) {
                        flushLine();
                        bold = raw.charCodeAt(i + 2) !== 0;
                        i += 3; continue;
                    }
                    // ESC p - Cash drawer (skip 4 bytes total)
                    if (cmd === 0x70 && i + 4 < raw.length) { i += 5; continue; }
                    // ESC d n - Print and feed n lines
                    if (cmd === 0x64 && i + 2 < raw.length) {
                        flushLine();
                        const n = raw.charCodeAt(i + 2);
                        for (let j = 0; j < n; j++) html += '<div>&nbsp;</div>';
                        i += 3; continue;
                    }
                    // Other ESC commands - skip 2 bytes
                    i += 2; continue;
                }

                // GS commands (\x1D)
                if (b === 0x1D && i + 1 < raw.length) {
                    const cmd = raw.charCodeAt(i + 1);
                    // GS V - Paper cut (skip)
                    if (cmd === 0x56) { i += 3; continue; }
                    // GS k - Barcode (skip variable, simplified)
                    if (cmd === 0x6B) { i += 2; continue; }
                    // GS ( k - QR code (skip variable length)
                    if (cmd === 0x28 && i + 5 < raw.length) {
                        const pL = raw.charCodeAt(i + 3);
                        const pH = raw.charCodeAt(i + 4);
                        const len = pL + pH * 256;
                        i += 4 + len; continue;
                    }
                    // GS ! - Character size
                    if (cmd === 0x21 && i + 2 < raw.length) {
                        flushLine();
                        const n = raw.charCodeAt(i + 2);
                        bigMode = n > 0;
                        i += 3; continue;
                    }
                    // Other GS - skip
                    i += 2; continue;
                }

                // Line Feed
                if (b === 0x0A) {
                    flushLine();
                    if (currentLine === '') html += openSpan() + '&nbsp;</div>';
                    i++; continue;
                }

                // Carriage Return - ignore
                if (b === 0x0D) { i++; continue; }

                // Skip other control chars
                if (b < 0x20 && b !== 0x0A && b !== 0x0D) { i++; continue; }

                // Regular character
                currentLine += raw[i];
                i++;
            }
            
            flushLine();
            return html || '<span style="color:#999">Ticket vacío</span>';
        }

        function escapeHtml(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // Auto-load if ID provided
        <?php if ($id > 0): ?>
        window.addEventListener('load', () => setTimeout(loadTicket, 300));
        <?php endif; ?>
    </script>
</body>
</html>
