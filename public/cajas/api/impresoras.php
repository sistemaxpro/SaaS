<?php
/**
 * API: Obtener impresoras del sistema
 * Detecta impresoras instaladas en el servidor vía CUPS (Linux) o wmic (Windows)
 * GET → retorna lista de impresoras
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $impresoras = [];

    // ── 1. Linux / macOS: CUPS (lpstat) ──
    if (PHP_OS_FAMILY !== 'Windows') {
        // Intentar con lpstat -a (lista impresoras aceptando trabajos)
        $lpstat = trim(shell_exec('lpstat -a 2>/dev/null') ?? '');
        if ($lpstat !== '') {
            foreach (explode("\n", $lpstat) as $linea) {
                $linea = trim($linea);
                if ($linea === '') continue;
                // Formato: "PRINTER_NAME accepting requests since ..."
                $partes = preg_split('/\s+/', $linea, 2);
                $nombre = $partes[0] ?? '';
                if ($nombre !== '') {
                    $estado = 'disponible';
                    if (stripos($linea, 'not accepting') !== false) {
                        $estado = 'no aceptando';
                    }
                    $impresoras[] = [
                        'nombre' => $nombre,
                        'estado' => $estado,
                        'fuente' => 'cups'
                    ];
                }
            }
        }

        // Si lpstat no devuelve nada, intentar con lpinfo
        if (empty($impresoras)) {
            $lpinfo = trim(shell_exec('lpinfo -v 2>/dev/null') ?? '');
            if ($lpinfo !== '') {
                foreach (explode("\n", $lpinfo) as $linea) {
                    $linea = trim($linea);
                    if ($linea === '') continue;
                    // Formato: "direct usb://EPSON/TM-T88V?serial=..."
                    if (preg_match('/^\w+\s+(.+)$/', $linea, $m)) {
                        $uri = $m[1];
                        // Extraer nombre legible del URI
                        $nombreUri = $uri;
                        if (preg_match('#://([^/\?]+)#', $uri, $mn)) {
                            $nombreUri = urldecode($mn[1]);
                        }
                        $impresoras[] = [
                            'nombre' => $nombreUri,
                            'uri'    => $uri,
                            'estado' => 'detectada',
                            'fuente' => 'lpinfo'
                        ];
                    }
                }
            }
        }

        // También intentar listar impresoras por red (avahi/mdns)
        $avahi = trim(shell_exec('avahi-browse -tpr _ipp._tcp 2>/dev/null | grep "^="') ?? '');
        if ($avahi !== '') {
            foreach (explode("\n", $avahi) as $linea) {
                $campos = explode(';', $linea);
                if (count($campos) >= 7) {
                    $nombreRed = $campos[3] ?? '';
                    $host = $campos[6] ?? '';
                    if ($nombreRed !== '' && !in_array($nombreRed, array_column($impresoras, 'nombre'))) {
                        $impresoras[] = [
                            'nombre' => $nombreRed,
                            'host'   => $host,
                            'estado' => 'red',
                            'fuente' => 'avahi'
                        ];
                    }
                }
            }
        }
    }

    // ── 2. Windows: wmic ──
    if (PHP_OS_FAMILY === 'Windows') {
        // PowerShell: obtener impresoras con estado
        $ps = shell_exec('powershell -Command "Get-Printer | Select-Object Name, PrinterStatus, PortName | ConvertTo-Json" 2>NUL');
        if ($ps) {
            $lista = json_decode(trim($ps), true);
            if ($lista) {
                // Si es un solo objeto, envolver en array
                if (isset($lista['Name'])) $lista = [$lista];
                foreach ($lista as $p) {
                    $impresoras[] = [
                        'nombre' => $p['Name'] ?? '',
                        'estado' => $p['PrinterStatus'] ?? 'desconocido',
                        'puerto' => $p['PortName'] ?? '',
                        'fuente' => 'windows'
                    ];
                }
            }
        }

        // Fallback: wmic
        if (empty($impresoras)) {
            $wmic = shell_exec('wmic printer get Name,PortName,Status /format:csv 2>NUL');
            if ($wmic) {
                $lineas = array_filter(explode("\n", trim($wmic)), 'trim');
                $cabecera = true;
                foreach ($lineas as $linea) {
                    if ($cabecera) { $cabecera = false; continue; }
                    $cols = str_getcsv($linea);
                    // CSV: Node, Name, PortName, Status
                    if (count($cols) >= 4) {
                        $impresoras[] = [
                            'nombre' => trim($cols[1]),
                            'puerto' => trim($cols[2]),
                            'estado' => trim($cols[3]) ?: 'disponible',
                            'fuente' => 'wmic'
                        ];
                    }
                }
            }
        }
    }

    // ── 3. Impresoras de red comunes (ESC/POS puerto 9100) ──
    // Escanear red local para impresoras en puerto 9100 (timeout corto)
    // Solo si no se encontraron impresoras por otros medios
    // NOTA: Deshabilitado por defecto por lentitud - descomentar si se necesita
    /*
    if (empty($impresoras)) {
        $subnet = '192.168.1.';
        for ($i = 1; $i <= 254; $i++) {
            $ip = $subnet . $i;
            $fp = @fsockopen($ip, 9100, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);
                $impresoras[] = [
                    'nombre' => "Impresora en $ip",
                    'host'   => "$ip:9100",
                    'estado' => 'red',
                    'fuente' => 'scan_9100'
                ];
            }
        }
    }
    */

    echo json_encode([
        'ok'         => true,
        'impresoras' => $impresoras,
        'total'      => count($impresoras),
        'os'         => PHP_OS_FAMILY,
        'metodo'     => !empty($impresoras) ? $impresoras[0]['fuente'] : 'ninguno'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'ok'         => false,
        'msg'        => 'Error al detectar impresoras: ' . $e->getMessage(),
        'impresoras' => [],
        'total'      => 0
    ]);
}
