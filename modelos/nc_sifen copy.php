<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// -- AJAX HANDLER FOR delete_nc --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_nc') {
    header('Content-Type: application/json; charset=utf-8');
    $id = $_POST['id'] ?? 0;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID no válido']);
        exit;
    }

    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get DB Name
        $id_empresa = $_SESSION['id_empresa'] ?? 0;
        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if ($dbName) {
            // Delete Items first (manual cascade)
            $stmtDelItems = $pdo->prepare("DELETE FROM $dbName.de_nc_items WHERE nota_credito_id = :id");
            $stmtDelItems->execute([':id' => $id]);

            // Delete Header
            $stmtDelHead = $pdo->prepare("DELETE FROM $dbName.de_nc WHERE id = :id");
            $stmtDelHead->execute([':id' => $id]);

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'DB no encontrada']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// -- AJAX HANDLER FOR get_factura --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_factura') {
    header('Content-Type: application/json; charset=utf-8');

    $cdc = $_POST['cdc'] ?? '';
    if (!$cdc) {
        echo json_encode(['success' => false, 'message' => 'CDC requerido']);
        exit;
    }

    $pdo = null;
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error DB Connection: ' . $e->getMessage()]);
        exit;
    }

    // Get Dynamic DB
    $id_empresa = $_SESSION['id_empresa'] ?? 0;
    $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();
    if (!$dbName) {
        echo json_encode(['success' => false, 'message' => 'Configuración de empresa (DB) no encontrada']);
        exit;
    }

    try {
        $stmtFunc = $pdo->prepare("SELECT * FROM $dbName.factura_ventas WHERE cdc = :cdc LIMIT 1");
        $stmtFunc->execute([':cdc' => $cdc]);
        $row = $stmtFunc->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Factura no encontrada']);
            exit;
        }

        // Emisor (Fetch from empresa)
        $stmtEm = $pdo->prepare("SELECT empresa AS razon_social, ruc, dv, tipoContribuyente AS tipo_contribuyente, ciudad_id AS ciudad, direccion, telefono, email, cod_act as act_eco, des_act as act_eco_desc FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtEm->execute([':id' => $id_empresa]);
        $emData = $stmtEm->fetch(PDO::FETCH_ASSOC);
        $emisor = [
            'ruc' => $emData['ruc'] ?? '',
            'dv' => $emData['dv'] ?? '0',
            'razon_social' => $emData['razon_social'] ?? '',
            'tipo_contribuyente' => $emData['tipo_contribuyente'] ?? '2',
            'ciudad' => $emData['ciudad'] ?? 1,
            'direccion' => $emData['direccion'] ?? '-',
            'telefono' => $emData['telefono'] ?? '-',
            'email' => $emData['email'] ?? '-',
            'act_eco' => $emData['act_eco'] ?? '0',
            'act_eco_desc' => $emData['act_eco_desc'] ?? '-',
        ];

        // Receptor
        $clienteName = '';
        $clienteRuc = '';
        $clienteDir = '-';
        $clienteTel = '-';
        $clienteEmail = '-';
        $clienteCiudad = 1;
        $clienteTipoDoc = '1';

        if (!empty($row['id_cliente'])) {
            $stmtCli = $pdo->prepare("SELECT nombre, numero, documento, direccion, ciudad, telefono, email FROM $dbName.clientes WHERE id = :id");
            $stmtCli->execute([':id' => $row['id_cliente']]);
            $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);
            if ($cli) {
                $clienteName = $cli['nombre'];
                $clienteRuc = $cli['numero'] ?: $cli['documento'];
                $clienteDir = $cli['direccion'] ?: '-';
                $clienteTel = $cli['telefono'] ?: '-';
                $clienteEmail = $cli['email'] ?: '-';
                $clienteCiudad = $cli['ciudad'] ?: 1;
                $clienteTipoDoc = $cli['numero'] ? '2' : '1';
            }
        }

        $receptor = [
            'razon_social' => $clienteName ?: 'Sin Nombre',
            'documento' => $clienteRuc ?: '0',
            'tipo_doc' => $clienteTipoDoc,
            'direccion' => $clienteDir,
            'telefono' => $clienteTel,
            'email' => $clienteEmail,
            'ciudad' => $clienteCiudad,
        ];

        // Conceptos (Items from extracto_productos)
        $conceptos = [];
        try {
            // referencia = 200 is typically sales invoice items in this system
            $stmtDet = $pdo->prepare("SELECT codigo, descripcion, salida AS cantidad, precio, iva10, iva5, exenta FROM $dbName.extracto_productos WHERE idfactura = :id AND estado = 1");
            $stmtDet->execute([':id' => $row['id_factura']]);
            while ($d = $stmtDet->fetch(PDO::FETCH_ASSOC)) {
                $tasa = 0;
                if ($d['iva10'] > 0)
                    $tasa = 10;
                elseif ($d['iva5'] > 0)
                    $tasa = 5;

                $conceptos[] = [
                    'codigo' => $d['codigo'] ?? '0',
                    'descripcion' => $d['descripcion'] ?? 'Item',
                    'cantidad' => (float) ($d['cantidad'] ?? 0),
                    'precio' => (float) ($d['precio'] ?? 0),
                    'tasa_iva' => $tasa,
                    'unidad_medida' => 77
                ];
            }
        } catch (Exception $ex) {
            // Log or fallback
        }

        if (empty($conceptos)) {
            $conceptos[] = [
                'codigo' => 'GEN',
                'descripcion' => 'Total Factura (Detalle no disponible)',
                'cantidad' => 1,
                'precio' => (float) $row['total'],
                'tasa_iva' => 10,
                'unidad_medida' => 77
            ];
        }

        $factura = [
            'id_factura' => $row['id_factura'],
            'cdc' => $row['cdc'],
            'nro_factura' => $row['nro_factura'],
            'timbrado' => $row['timbrado'],
            'fec_timbrado' => $row['vencimiento_timbrado'],
            'emisor' => $emisor,
            'receptor' => $receptor,
            'conceptos' => $conceptos
        ];

        echo json_encode(['success' => true, 'factura' => $factura]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
}

// -- AJAX HANDLER FOR get_nc --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_nc') {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID de NC requerido']);
        exit;
    }

    $pdo = null;
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");

        $id_empresa = $_SESSION['id_empresa'] ?? 0;
        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if (!$dbName) {
            echo json_encode(['success' => false, 'message' => 'DB no encontrada']);
            exit;
        }

        // Get NC Header
        $stmtNC = $pdo->prepare("SELECT * FROM $dbName.de_nc WHERE id = :id");
        $stmtNC->execute([':id' => $id]);
        $ncMaster = $stmtNC->fetch(PDO::FETCH_ASSOC);

        if (!$ncMaster) {
            echo json_encode(['success' => false, 'message' => 'Nota de Crédito no encontrada']);
            exit;
        }

        // Get NC Items
        $stmtItems = $pdo->prepare("SELECT * FROM $dbName.de_nc_items WHERE nota_credito_id = :id");
        $stmtItems->execute([':id' => $id]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'nc' => $ncMaster, 'items' => $items]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// -- AJAX HANDLER FOR get_next_nc --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_next_nc') {
    header('Content-Type: application/json; charset=utf-8');

    $id_empresa = $_SESSION['id_empresa'] ?? 0;

    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");

        $stmtEmp = $pdo->prepare("SELECT dbase, timbrado, establecimiento, punto_expedicion FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtEmp->execute([':id' => $id_empresa]);
        $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

        if (!$emp) {
            echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
            exit;
        }

        $dbName = $emp['dbase'];
        $timbrado = (string) $emp['timbrado'];
        $est = str_pad($emp['establecimiento'] ?: '001', 3, '0', STR_PAD_LEFT);
        $pex = str_pad($emp['punto_expedicion'] ?: '001', 3, '0', STR_PAD_LEFT);

        // Get max sequence for current timbrado
        $sqlMax = "SELECT MAX(CAST(SUBSTRING_INDEX(ndoc, '-', -1) AS UNSIGNED)) FROM $dbName.de_nc WHERE timbrado = :timbrado";
        $stmtMax = $pdo->prepare($sqlMax);
        $stmtMax->execute([':timbrado' => $timbrado]);
        $maxSeq = (int) $stmtMax->fetchColumn();

        $nextSeq = $maxSeq + 1;
        $formattedSeq = str_pad($nextSeq, 7, '0', STR_PAD_LEFT);
        $ndoc = "$est-$pex-$formattedSeq";

        echo json_encode(['success' => true, 'ndoc' => $ndoc, 'timbrado' => $timbrado]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// -- AJAX HANDLER FOR search_facturas --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_facturas') {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim($_POST['q'] ?? '');
    $logs = [];
    $items = [];

    // Debug Session Full Dump
    $logs[] = "--- FULL SESSION DUMP ---";
    foreach ($_SESSION as $key => $val) {
        $valStr = (is_array($val) || is_object($val)) ? json_encode($val) : $val;
        $logs[] = "SESSION['$key']: $valStr";
    }
    $logs[] = "--- END SESSION DUMP ---";

    $pdo = null;
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    try {
        $logs[] = "SQL 1: Connection to $masterDb on $dbHost...";
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");
        $logs[] = "Connection OK";
    } catch (Exception $e) {
        $logs[] = "Connection Error: " . $e->getMessage();
        echo json_encode(['success' => false, 'items' => [], 'logs' => $logs]);
        exit;
    }

    $id_empresa = $_SESSION['id_empresa'] ?? $_POST['id_empresa'] ?? 0;

    // empresa belongs to [dbu]
    $sqlDb = "SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id";
    $logs[] = "SQL 2 (Get DB): $sqlDb [Params: id=$id_empresa]";

    $stmtDB = $pdo->prepare($sqlDb);
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();
    $logs[] = "Variable dbName: " . ($dbName ? $dbName : 'NULL');

    if (!$dbName) {
        $logs[] = "Error: dbName not found for id_empresa $id_empresa";
        echo json_encode(['items' => [], 'logs' => $logs]);
        exit;
    }

    // Search by Number or Client Name
    $sqlSearch = "
    SELECT 
        fv.id_factura,
        fv.nro_factura,
        fv.cdc,
        fv.fecha,
        fv.total,
        c.nombre AS cliente_nombre,
        c.numero AS cliente_ruc
    FROM $dbName.factura_ventas fv
    LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
    WHERE fv.cdc > ''
    AND (
        fv.nro_factura LIKE :q 
        OR c.nombre LIKE :q
        OR fv.cdc LIKE :q
    )
    ORDER BY fv.id_factura DESC
    LIMIT 20
    ";

    $term = "%{$q}%";
    $logs[] = "SQL 3 (Search): " . str_replace(["\t", "\n", "\r"], " ", $sqlSearch) . " [Params: q=$term]";

    try {
        $stmt = $pdo->prepare($sqlSearch);
        $stmt->execute([':q' => $term]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nro = $row['nro_factura'] ?: 'S/N';
            $cli = $row['cliente_nombre'] ?: 'Sin Nombre';
            $cdc = $row['cdc'];

            $items[] = [
                'value' => $cdc,
                'text' => "$nro - $cli - $cdc",
                'data' => $row
            ];
        }
    } catch (Exception $e) {
        $logs[] = "Search Error: " . $e->getMessage();
    }

    echo json_encode([
        'items' => $items,
        'logs' => $logs
    ]);
    exit;
}

// -- MAIN PAGE LOGIC --

$columnStateSessionKey = 'nc_sifen_column_state';
$columnState = $_SESSION[$columnStateSessionKey] ?? null;
$id_empresa = isset($_SESSION['id_empresa']) ? (int) $_SESSION['id_empresa'] : 0;

// Configuración FE (cert, modo, csc)
$ruc = '';
$modo = 'test';
$cert_pass = '';
$appRoot = __DIR__; // We are in /app/smx
$cert_path = '';
$idc = '';
$csc = '';

$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$staticDb = $_SESSION['db'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

$pdo = null;
try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");
} catch (Exception $e) {
    $pdo = null;
}

$sql = "
SELECT
    ruc,
    ambiente_sifen,
    cert_pass,
    cert_path,
    id_csc,
    csc,
    dbase,
    timbrado,
    establecimiento,
    punto_expedicion,
    timbradoFecha,
    vigencia_ini,
    empresa
FROM $masterDb.empresa
WHERE id_empresa = :id_empresa
LIMIT 1
";

if ($pdo) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_empresa' => $id_empresa]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ruc = trim((string) $row['ruc']);
            $modo_valor = trim((string) $row['ambiente_sifen']);
            $modo = ($modo_valor == '1') ? 'test' : 'prod';
            $cert_pass = trim((string) $row['cert_pass']);
            $cert_path = trim((string) $row['cert_path']);
            $idc = trim((string) $row['id_csc']);
            $csc = trim((string) $row['csc']);
            $dbName = trim((string) $row['dbase']);
            $timbrado = trim((string) $row['timbrado']);
            $establecimiento = trim((string) $row['establecimiento']);
            $punto_expedicion = trim((string) $row['punto_expedicion']);
            $timbradoFecha = trim((string) $row['timbradoFecha']);
            $vigencia_ini = trim((string) $row['vigencia_ini']);
            $nombreEmpresa = trim((string) $row['empresa']);
        }
    } catch (Exception $e) { /* ignore */
    }
}

if ($cert_path === '') {
    $cert_path = $appRoot . '/_lib/php-sifen3/certificados/' . preg_replace('/\D+/', '', $ruc) . '.p12';
}

$resolvedCertPath = (function ($rawPath, $rucValue) {
    $normalize = static function ($path) {
        return str_replace(['\\'], '/', $path); };
    $candidates = [];
    $trimmed = trim((string) $rawPath);
    $rucDigits = preg_replace('/\D+/', '', (string) $rucValue);
    $baseDir = __DIR__;

    if ($trimmed !== '')
        $candidates[] = $trimmed;
    if ($rucDigits !== '')
        $candidates[] = $baseDir . '/_lib/php-sifen3/certificados/' . $rucDigits . '.p12';

    foreach ($candidates as $candidate) {
        if ($candidate && file_exists($candidate))
            return $normalize(realpath($candidate));
    }
    return $trimmed;
})($cert_path, $ruc);

$configFe = [
    'ruc' => $ruc,
    'modo' => $modo,
    'cert_pass' => $cert_pass,
    'cert_path' => $resolvedCertPath,
    'idc' => $idc,
    'csc' => $csc,
    'timbrado' => $timbrado,
    'establecimiento' => $establecimiento,
    'punto_expedicion' => $punto_expedicion,
    'timbradoFecha' => $vigencia_ini ? $vigencia_ini : $timbradoFecha,
];

// Query for Credit Notes
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbName = $dbName ?? $_SESSION['db'] ?? 'serproc1';

$sqlNC = "
SELECT 
    nc.id AS id_factura,
    nc.fec_proc AS fecha,
    nc.ndoc AS nro_factura,
    nc.receptor_razon_social AS razon_social,
    nc.receptor_documento AS ruc_cliente,
    nc.id_sifen AS cdc,
    (SELECT COALESCE(SUM(precio * cantidad), 0) FROM $dbName.de_nc_items WHERE nota_credito_id = nc.id) AS total, 
    nc.est_res AS estado_electronico,
    nc.msg_res,
    'NC' AS tipo_documento
FROM $dbName.de_nc nc
ORDER BY id DESC 
LIMIT 1000
";

$rows = [];
if ($pdo) {
    try {
        $stmt = $pdo->query($sqlNC);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* Missing table or col? */
    }
}

$ncRows = array_map(function ($row) {
    $r = array_change_key_case($row, CASE_LOWER);
    return [
        'id_factura' => $r['id_factura'],
        'fecha' => $r['fecha'],
        'nro_factura' => $r['nro_factura'] ?? '',
        'razon_social' => $r['razon_social'] ?? '',
        'ruc_cliente' => $r['ruc_cliente'] ?? '',
        'total' => (float) ($r['total'] ?? 0),
        'estado_electronico' => $r['estado_electronico'] ?? '',
        'msg_res' => $r['msg_res'] ?? '',
        'cdc' => $r['cdc'],
        'tipo_documento' => $r['tipo_documento'] ?? 'NC',
    ];
}, $rows);

// Initial Pre-load only small set if needed, but we rely on AJAX search now.
$recentInvoices = [];


$apiNcUrl = 'nc_api.php'; // Using the new local writeable endpoint
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Notas de Crédito Electrónicas (SIFEN)</title>
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.default.min.css" rel="stylesheet">
    <script src="_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Apply theme from LocalStorage
        // Logic: Add 'dark' class ONLY if theme is 'dark'. Otherwise remove it (Light is default).
        (function () {
            const savedTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            // Prefer saved theme, otherwise system preference
            if (savedTheme === 'dark' || (!savedTheme && systemDark)) {
                document.documentElement.classList.add('dark');
                document.documentElement.classList.remove('light');
            } else {
                document.documentElement.classList.remove('dark');
                document.documentElement.classList.add('light');
            }
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <style>
        /* --- CUSTOM AG GRID THEME VARIABLES --- */
        /* Common Base Styles */
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-family: "Segoe UI", "Roboto", sans-serif;
            --ag-font-size: 13px;
            --ag-grid-size: 8px;
            /* Less Compact */
            --ag-header-height: 52px;
            --ag-row-height: 52px;
        }

        /* LIGHT THEME (Default) */
        .ag-theme-quartz {
            --ag-background-color: #ffffff;
            --ag-foreground-color: #1f2933;
            --ag-header-background-color: #f8fafc;
            --ag-header-foreground-color: #475569;
            --ag-border-color: #e2e8f0;
            --ag-row-border-color: #e2e8f0;
            --ag-odd-row-background-color: #f8fafc;
            --ag-selected-row-background-color: #eff6ff;
        }

        /* DARK THEME (Customized Override) */
        /* We override the built-in .ag-theme-quartz-dark variables */
        /* Also override .ag-theme-quartz in case the class swap hasn't happened yet */
        html.dark .ag-theme-quartz-dark,
        html.dark .ag-theme-quartz {
            /* Colores Principales */
            --ag-background-color: #0f172a !important;
            --ag-foreground-color: #f1f5f9 !important;
            --ag-header-background-color: #1e293b !important;
            --ag-header-foreground-color: #94a3b8 !important;
            --ag-border-color: #334155 !important;
            --ag-row-border-color: #1e293b !important;
            --ag-odd-row-background-color: #162032 !important;
            --ag-selected-row-background-color: #1e293b !important;
            --ag-input-focus-border-color: #3b82f6 !important;
        }

        :root {
            color-scheme: light;
            --bg: #f3f6fb;
            --panel: #ffffff;
            --text: #1f2933;
            --border: #e2e8f0;
            --input-bg: #ffffff;
            --input-text: #1f2933;
            --muted: #64748b;
            --success: #16a34a;
        }

        /* Dark Mode Theme */
        html.dark {
            color-scheme: dark;
            --bg: #0f172a;
            --panel: #1e293b;
            --text: #f1f5f9;
            --border: #334155;
            --input-bg: #0f172a;
            --input-text: #e2e8f0;
            --muted: #94a3b8;
            --success: #4ade80;
        }

        html.dark .modal-content,
        html.dark .nc-card {
            background: var(--panel);
            color: var(--text);
            border-color: var(--border);
        }

        html.dark .nc-label {
            color: var(--muted);
        }

        html.dark .nc-input,
        html.dark .ts-control {
            background: var(--input-bg);
            color: var(--input-text);
            border-color: var(--border);
        }

        html.dark .ag-theme-quartz {
            /* This will be handled by switching class to ag-theme-quartz-dark, but just in case */
        }

        html.dark body {
            background-color: var(--bg);
            color: var(--text);
        }

        /* Media query removed to enforce strict class-based theming */

        body {
            font-family: "Segoe UI", "Roboto", sans-serif;
            margin: 0;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            box-sizing: border-box;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }

        h1 {
            margin: 0;
            font-size: 1.6rem;
        }

        .toolbar {
            display: flex;
            gap: 12px;
        }

        .btn-chip {
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #2563eb;
            color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-chip:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-chip--alt {
            background: #64748b;
        }

        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            height: calc(100vh - 120px);
            border-radius: 8px;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-content {
            background: var(--panel);
            width: 95%;
            max-width: 1200px;
            height: 90vh;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .modal-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--panel);
            color: var(--text);
        }

        .modal-body {
            padding: 16px;
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: var(--bg);
            color: var(--text);
        }

        .modal-footer {
            padding: 16px;
            border-top: 1px solid var(--border);
            background: var(--panel);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text);
        }

        /* Styles from nc_form.html adapted */
        .nc-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .nc-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .nc-col {
            flex: 1;
            min-width: 200px;
        }

        .nc-label {
            display: block;
            font-size: 0.75rem;
            color: var(--muted);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .nc-input,
        .nc-select {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--border);
            color: var(--input-text);
            padding: 8px;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .nc-panes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            flex: 1;
            min-height: 300px;
        }

        .nc-pane {
            background: var(--input-bg);
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 12px;
            display: flex;
            flex-direction: column;
        }

        .item-card {
            background: var(--panel);
            border: 1px solid var(--border);
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 8px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
        }

        .item-card.draggable {
            cursor: grab;
        }

        .dropzone {
            flex: 1;
            border: 2px dashed var(--border);
            border-radius: 8px;
            padding: 8px;
            min-height: 200px;
        }

        .dropzone.over {
            border-color: #38bdf8;
            background: rgba(56, 189, 248, 0.1);
        }

        .edit-row input {
            width: 70px;
            padding: 4px;
            font-size: 0.9rem;
        }

        .badge-info {
            background: var(--input-bg);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            color: var(--muted);
            border: 1px solid var(--border);
        }

        .result-box {
            background: var(--input-bg);
            border: 1px solid var(--border);
            padding: 10px;
            font-family: monospace;
            color: var(--success);
            min-height: 60px;
            white-space: pre-wrap;
            border-radius: 6px;
        }

        .btn-action {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-success {
            background: linear-gradient(135deg, #22c55e, #16a34a);
        }

        .btn-danger {
            background: #ef4444;
        }

        /* Tom Select Overrides for Dark Mode if needed */
        .ts-control {
            border-radius: 6px;
            border-color: #334155;
        }

        .ts-control,
        .ts-control input,
        .ts-dropdown {
            font-family: inherit;
        }

        @media (prefers-color-scheme: dark) {

            .ts-control,
            .ts-wrapper.single .ts-control {
                background-color: #0f172a;
                color: #e2e8f0;
                border-color: #334155;
            }

            .ts-dropdown,
            .ts-dropdown.single {
                background-color: #1e293b;
                color: #e2e8f0;
                border-color: #334155;
            }

            .ts-dropdown .option {
                color: #e2e8f0;
            }

            .ts-dropdown .active {
                background-color: #334155;
                color: #fff;
            }

            .ts-control input {
                color: #e2e8f0;
            }
        }
    </style>
</head>

<body>

    <div class="page-header">
        <div>
            <h1>Notas de Crédito (SIFEN)</h1>
            <p style="margin:4px 0 0; color:#38bdf8; font-weight: 600; font-size: 1.1rem;">
                <?php echo htmlspecialchars($nombreEmpresa ?? 'Gestión de Documentos'); ?>
            </p>
        </div>
        <div class="toolbar">
            <!-- Global Search Input -->
            <div style="position: relative;">
                <input type="text" id="globalFilter" placeholder="Buscar..." class="nc-input"
                    style="margin-bottom: 0; padding-left: 30px; width: 200px;">
                <i class="fa-solid fa-search"
                    style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
            </div>
            <div class="btn-chip" id="btnNewNC">
                <i class="fa-solid fa-plus"></i> Nueva NC
            </div>
            <button id="reloadGrid" class="btn-chip btn-chip--alt" type="button">
                <i class="fa-solid fa-sync"></i> Actualizar
            </button>
            <button id="btnSalir" class="btn-chip" type="button" style="background:#ef4444;">
                <i class="fa-solid fa-arrow-left"></i> Salir
            </button>
        </div>
    </div>

    <div id="ncGrid" class="ag-theme-quartz"></div>

    <!-- Modal Create NC -->
    <div id="modalNC" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 style="margin:0; font-size:1.2rem;">Emitir Nota de Crédito</h2>
                <button class="close-modal" id="btnCloseModal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="nc-card">
                    <div class="nc-row">
                        <div class="nc-col">
                            <label class="nc-label">Buscar Factura (Origen)</label>
                            <select id="invoiceSelect" placeholder="Busque por Nro o Cliente..."></select>
                            <!-- Hidden CDC Input for Logic -->
                            <input id="cdcInput" type="hidden">
                        </div>
                        <div class="nc-col">
                            <label class="nc-label">N° Interno NC</label>
                            <input id="ndocInput" class="nc-input" placeholder="Ej: 001-001-0000001">
                        </div>
                        <div class="nc-col">
                            <label class="nc-label">Motivo</label>
                            <select id="motivoInput" class="nc-select">
                                <option value="1">Descuento global</option>
                                <option value="2" selected>Devolución</option>
                                <option value="3">Bonificación</option>
                                <option value="4">Anulación total</option>
                                <option value="5">Diferencia</option>
                                <option value="6">Intereses</option>
                            </select>
                        </div>
                    </div>
                    <div class="nc-row" style="margin-top:12px;">
                        <div class="nc-col">
                            <label class="nc-label">Ambiente</label>
                            <select id="modoInput" class="nc-select">
                                <option value="test">Testing</option>
                                <option value="prod">Producción</option>
                            </select>
                        </div>
                        <div class="nc-col">
                            <label class="nc-label">Certificado</label>
                            <input id="certPathInput" class="nc-input" readonly>
                        </div>
                        <div class="nc-col" style="display: none;"> <!-- Hidden pass -->
                            <input type="password" id="certPassInput">
                        </div>
                        <div class="nc-col" style="align-self: flex-end;">
                            <label>
                                <input type="checkbox" id="previewInput"> Solo Preview XML
                            </label>
                        </div>
                    </div>
                </div>

                <div class="nc-card">
                    <div style="display:flex; justify-content:space-between;">
                        <div id="clienteInfo" style="color:#38bdf8; font-weight:600;">Sin Cliente seleccionado</div>
                        <div id="facturaInfo" class="badge-info">Factura no cargada</div>
                    </div>
                </div>

                <div class="nc-panes">
                    <div class="nc-pane">
                        <h3 style="margin-top:0;">1. Mercaderías devueltas (Arrastre aquí)</h3>
                        <div id="dropZone" class="dropzone"></div>
                    </div>
                    <div class="nc-pane">
                        <h3 style="margin-top:0;">2. Ítems de la Factura Origen</h3>
                        <div id="facturaItemsList" style="display:grid; gap:8px;"></div>
                    </div>
                </div>

                <div class="nc-card">
                    <label class="nc-label">Respuesta SIFEN</label>
                    <div id="resultBox" class="result-box">Esperando envío...</div>
                </div>
            </div>
            <div class="modal-footer">
                <div style="font-size:1.2rem; color:var(--success); font-weight:bold;">
                    Total a Devolver: <span id="totalDev">0 Gs</span>
                </div>
                <div>
                    <button id="btnEnviarNC" class="btn-action btn-success"
                        style="padding: 12px 24px; font-size: 1rem;">
                        <i class="fa-solid fa-paper-plane"></i> Generar y Enviar NC
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let api; // Declare early to avoid TDZ errors
        const CONFIG = <?php echo json_encode($configFe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const ROW_DATA = <?php echo json_encode($ncRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const INVOICE_DATA = <?php echo json_encode($recentInvoices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const API_NC_URL = '<?php echo htmlspecialchars($apiNcUrl, ENT_QUOTES, 'UTF-8'); ?>';
        const ID_EMPRESA = <?php echo (int) $id_empresa; ?>;

        // -- AG GRID SETUP --
        const gridDiv = document.querySelector('#ncGrid');

        // Apply Dark Theme to Grid dynamically
        // Apply Dark Theme to Grid dynamically
        function updateGridTheme() {
            const isDark = document.documentElement.classList.contains('dark');
            const targetTheme = isDark ? 'ag-theme-quartz-dark' : 'ag-theme-quartz';
            const unwantedTheme = isDark ? 'ag-theme-quartz' : 'ag-theme-quartz-dark';
            
            // console.log(`[Theme] Check. isDark=${isDark} Target=${targetTheme}`);

            if (gridDiv.classList.contains(unwantedTheme)) {
                gridDiv.classList.remove(unwantedTheme);
            }
            
            if (!gridDiv.classList.contains(targetTheme)) {
                gridDiv.classList.add(targetTheme);
                // console.log(`[Theme] Added ${targetTheme}`);
            }
            
            // Force grid refresh if api exists
            if (typeof api !== 'undefined' && api) {
                 api.refreshHeader();
            }
        }
        
        // Initial Check
        updateGridTheme();

        // Observe changes to <html> class to toggle grid theme
        // Also listen to localStorage changes if coming from parent
        const observer = new MutationObserver((mutations) => {
            updateGridTheme();
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        window.addEventListener('storage', (e) => {
             if (e.key === 'theme') {
                 if (e.newValue === 'dark') document.documentElement.classList.add('dark');
                 else document.documentElement.classList.remove('dark');
                 // Observer will catch this
             }
        });

        const columnDefs = [
            { headerName: 'ID', field: 'id_factura', width: 80, sortable: true },
            { headerName: 'Fecha', field: 'fecha', width: 140, sortable: true },
            { headerName: 'Nro Nota Crédito', field: 'nro_factura', width: 150 },
            { headerName: 'Cliente', field: 'razon_social', width: 200 },
            { headerName: 'RUC', field: 'ruc_cliente', width: 120 },
            { headerName: 'CDC', field: 'cdc', width: 280 },
            {
                headerName: 'Total',
                field: 'total',
                width: 130,
                aggFunc: 'sum', // Permitir suma automática
                cellRenderer: p => {
                    // Si es nodo de grupo o total, p.value es el número directo
                    const val = (p.node.group || p.node.footer) ? p.value : parseFloat(p.data.total);
                    return Number(val || 0).toLocaleString('es-PY') + ' Gs';
                },
                cellStyle: { 'text-align': 'right' },
                headerClass: 'ag-right-aligned-header'
            },
            {
                headerName: 'Estado',
                field: 'estado_electronico',
                width: 250,
                autoHeight: true,
                wrapText: true,
                cellRenderer: p => {
                    const val = p.value || '';
                    if ((val === 'Rechazado' || val === 'Error') && p.data.msg_res) {
                        const safeMsg = encodeURIComponent(p.data.msg_res);
                        return `<div style="font-weight:bold; cursor:pointer; color:#ef4444; display:flex; align-items:center; gap:6px;" 
                                     onclick="Swal.fire('Detalle del Error', decodeURIComponent('${safeMsg}'), 'error')"
                                     title="Clic para ver motivo">
                                    ${val} <i class="fa-solid fa-circle-question"></i>
                                </div>`;
                    }
                    const color = (val === 'Aprobado') ? '#16a34a' : 'inherit';
                    return `<div style="font-weight:bold; color:${color}">${val}</div>`;
                }
            }
        ];

        const gridOptions = {
            theme: 'legacy',
            columnDefs: columnDefs,
            rowData: ROW_DATA,
            defaultColDef: {
                flex: 1,
                minWidth: 100,
                resizable: true,
                sortable: true,
                filter: true,
                enableRowGroup: true,
                enableValue: true,
                enablePivot: true
            },
            grandTotalRow: 'bottom', // Total Row at the bottom
            rowGroupPanelShow: 'always', // Mostrar panel de arrastrar columnas arriba siempre
            autoGroupColumnDef: {
                minWidth: 200,
                headerName: 'Grupo'
            },
            localeText: {
                // Filter
                page: 'Página',
                more: 'Más',
                to: 'a',
                of: 'de',
                next: 'Siguiente',
                last: 'Último',
                first: 'Primero',
                previous: 'Anterior',
                loadingOoo: 'Cargando...',
                selectAll: 'Seleccionar Todo',
                searchOoo: 'Buscar...',
                blanks: 'Vacío',
                filterOoo: 'Filtrar...',
                applyFilter: 'Aplicar Filtro...',
                equals: 'Igual',
                notEqual: 'No Igual',
                lessThan: 'Menor que',
                greaterThan: 'Mayor que',
                lessThanOrEqual: 'Menor o igual que',
                greaterThanOrEqual: 'Mayor o igual que',
                inRange: 'En rango',
                contains: 'Contiene',
                notContains: 'No contiene',
                startsWith: 'Empieza con',
                endsWith: 'Termina con',
                
                // Grouping & Columns
                group: 'Grupo',
                columns: 'Columnas',
                filters: 'Filtros',
                rowGroupColumnsEmptyMessage: 'Arrastre columnas aquí para agrupar',
                valueColumnsEmptyMessage: 'Arrastre columnas aquí para agregar valores',
                pivotColumnsEmptyMessage: 'Arrastre aquí para pivote',
                toolPanelButton: 'Panel de Herramientas',
                
                // Other
                noRowsToShow: 'No hay datos para mostrar',
                pinColumn: 'Fijar Columna',
                valueAggregation: 'Agregación de Valor',
                autosizeThiscolumn: 'Autoajustar esta columna',
                autosizeAllColumns: 'Autoajustar todas las columnas',
                resetColumns: 'Restablecer columnas',
                expandAll: 'Expandir Todo',
                collapseAll: 'Contraer Todo',
                copy: 'Copiar',
                ctrlC: 'Ctrl+C',
                copyWithHeaders: 'Copiar con encabezados',
                paste: 'Pegar',
                ctrlV: 'Ctrl+V',
                export: 'Exportar',
                csvExport: 'Exportar CSV',
                excelExport: 'Exportar Excel',

                // Enterprise Menu
                pinLeft: 'Fijar a la Izquierda',
                pinRight: 'Fijar a la Derecha',
                noPin: 'No Fijar',
                sum: 'Suma',
                min: 'Mínimo',
                max: 'Máximo',
                none: 'Ninguno',
                count: 'Contar',
                average: 'Promedio',
                filteredRows: 'Filtradas',
                selectedRows: 'Seleccionadas',
                totalRows: 'Total Filas',
                totalAndFilteredRows: 'Filas',

                // Date
                thousandSeparator: '.',
                decimalSeparator: ','
            },
            statusBar: {
                statusPanels: [
                    { statusPanel: 'agAggregationComponent', align: 'right' }
                ]
            },
            sideBar: {
                toolPanels: [
                    {
                        id: 'columns',
                        labelDefault: 'Columnas',
                        labelKey: 'columns',
                        iconKey: 'columns',
                        toolPanel: 'agColumnsToolPanel',
                    },
                    {
                        id: 'filters',
                        labelDefault: 'Filtros',
                        labelKey: 'filters',
                        iconKey: 'filter',
                        toolPanel: 'agFiltersToolPanel',
                    }
                ],
                defaultToolPanel: ''
            },
            pagination: true,
            paginationPageSize: 20,
            getContextMenuItems: (params) => {
                if (!params.node) return [];
                const id = params.node.data.id_factura;
                const result = [
                    {
                        name: 'Editar Nota de Crédito',
                        icon: '<i class="fa-solid fa-pen-to-square"></i>',
                        action: () => {
                            openEditModal(params.node.data);
                        }
                    },
                    (params.node.data.estado_electronico === 'Rechazado' || params.node.data.estado_electronico === 'Error') ?
                        {
                            name: 'Eliminar Registro (Local)',
                            icon: '<i class="fa-solid fa-trash" style="color:#ef4444;"></i>',
                            action: () => {
                                eliminarNC(params.node.data.id_factura);
                            }
                        } :
                        {
                            name: 'Anular Nota de Crédito',
                            icon: '<i class="fa-solid fa-ban"></i>',
                            action: () => {
                                anularNC(params.node.data);
                            }
                        },
                    'separator',
                    'copy',
                    'export'
                ];
                return result;
            }
        };

        try {
            api = agGrid.createGrid(gridDiv, gridOptions);
            // Verify theme after grid creation
            updateGridTheme(); 
        } catch (err) {
            console.error('AG Grid Init Error:', err);
            // Show error in a clean way
            gridDiv.innerHTML = `<div style="padding:20px; color:red">Error loading grid: ${err.message}</div>`;
        }

        document.getElementById('reloadGrid').addEventListener('click', () => {
            location.reload();
        });

        // -- GLOBAL FILTER LOGIC (Now correctly placed after api init) --
        const filterInput = document.getElementById('globalFilter');
        filterInput.addEventListener('input', (e) => {
            const text = e.target.value;
            if (api) {
                if (api.setGridOption) {
                    api.setGridOption('quickFilterText', text);
                } else if (api.setQuickFilter) {
                    api.setQuickFilter(text);
                }
            }
        });

        // -- MODAL LOGIC --
        const modal = document.getElementById('modalNC'); // Corrected to match HTML ID
        const btnNew = document.getElementById('btnNewNC');
        const btnClose = document.getElementById('btnCloseModal');
        const modalTitle = modal.querySelector('.modal-header h2');

        // Pre-fill config
        if (CONFIG) {
            if (document.getElementById('certPathInput')) document.getElementById('certPathInput').value = CONFIG.cert_path || '';
            if (document.getElementById('certPassInput')) document.getElementById('certPassInput').value = CONFIG.cert_pass || '';
            if (document.getElementById('modoInput')) document.getElementById('modoInput').value = CONFIG.modo || 'test';
        }

        function resetModal() {
            modalTitle.textContent = "Emitir Nota de Crédito";
            document.getElementById('ndocInput').value = '';
            document.getElementById('motivoInput').value = '2';
            document.getElementById('resultBox').textContent = 'Esperando envío...';
            document.getElementById('resultBox').style.color = ''; // Reset inline style to use class
            document.getElementById('totalDev').textContent = '0 Gs';
            document.getElementById('dropZone').innerHTML = '';
            document.getElementById('facturaItemsList').innerHTML = '';
            document.getElementById('clienteInfo').textContent = 'Sin Cliente seleccionado';
            document.getElementById('facturaInfo').textContent = 'Factura no cargada';
            document.getElementById('facturaInfo').className = 'badge-info';
            facturaActual = null;
            devueltos.clear();

            // Re-enable inputs
            document.getElementById('invoiceSelect').disabled = false;
            if (tomSelectInstance) tomSelectInstance.enable();
            if (tomSelectInstance) tomSelectInstance.clear();

            document.getElementById('btnEnviarNC').style.display = 'block';
        }

        document.getElementById('btnSalir').addEventListener('click', () => {
            if (document.referrer) { window.location.href = document.referrer; return; }
            if (window.history.length > 1) { window.history.back(); return; }
            window.close();
        });

        btnNew.addEventListener('click', async () => {
            resetModal();
            modal.classList.add('open');

            // Fetch next sequence
            try {
                const formData = new FormData();
                formData.append('action', 'get_next_nc');
                const resp = await fetch(location.href, { method: 'POST', body: formData });
                const res = await resp.json();
                if (res.success) {
                    document.getElementById('ndocInput').value = res.ndoc;
                }
            } catch (e) {
                console.error("Error fetching sequence:", e);
            }
        });

        btnClose.addEventListener('click', () => {
            modal.classList.remove('open');
        });

        // Edit Modal logic (Integrated)
        async function openEditModal(data) {
            resetModal();
            modalTitle.textContent = "Consultar/Editar Nota de Crédito";
            modal.classList.add('open');

            Swal.fire({ title: 'Cargando datos...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            try {
                const formData = new FormData();
                formData.append('action', 'get_nc');
                formData.append('id', data.id_factura);

                const resp = await fetch(location.href, { method: 'POST', body: formData });
                const res = await resp.json();

                if (res.success) {
                    const nc = res.nc;
                    const ncItems = res.items;

                    document.getElementById('ndocInput').value = nc.ndoc;
                    document.getElementById('motivoInput').value = nc.motivo;

                    if (nc.est_res === 'Aprobado') {
                        document.getElementById('btnEnviarNC').style.display = 'none';
                    }

                    // Load the original Invoice to populate items
                    if (nc.cdc_asociado) {
                        await loadFacturaByCDC(nc.cdc_asociado);

                        // After loading factura, move the items that are in ncItems to devueltos
                        ncItems.forEach(ni => {
                            const originalIdx = facturaActual.conceptos.findIndex(c => c.codigo === ni.codigo || c.descripcion === ni.descripcion);
                            if (originalIdx !== -1) {
                                const concepto = {
                                    ...facturaActual.conceptos[originalIdx],
                                    cantidad: parseFloat(ni.cantidad),
                                    precio: parseFloat(ni.precio),
                                    maxCant: facturaActual.conceptos[originalIdx].cantidad
                                };
                                devueltos.set(originalIdx, concepto);
                            }
                        });
                        renderDevueltos();
                        updateTotal();
                    }

                    // Show NC status last so it's not overwritten by loadFacturaByCDC
                    document.getElementById('resultBox').textContent = `Estado: ${nc.est_res}\nRespuesta: ${nc.msg_res}\nCDC NC: ${nc.id_sifen}`;

                    // Disable changing invoice
                    if (tomSelectInstance) tomSelectInstance.disable();

                    Swal.close();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error', err.message, 'error');
            }
        }

        // -- NC CREATION LOGIC --
        let facturaActual = null;
        const devueltos = new Map();
        const facturaItemsList = document.getElementById('facturaItemsList');
        const dropZone = document.getElementById('dropZone');
        const resultBox = document.getElementById('resultBox');

        // UI Helpers
        function renderFacturaItems() {
            facturaItemsList.innerHTML = '';
            if (!facturaActual || !facturaActual.conceptos) return;

            facturaActual.conceptos.forEach((c, idx) => {
                const div = document.createElement('div');
                div.className = 'item-card draggable';
                div.draggable = true;
                div.innerHTML = `<strong>${c.descripcion}</strong><div style="font-size:0.8rem;">Cant: ${c.cantidad} | ${Number(c.precio).toLocaleString()} Gs</div>`;
                div.addEventListener('dragstart', (ev) => {
                    ev.dataTransfer.setData('text/plain', JSON.stringify({ ...c, idx }));
                });
                facturaItemsList.appendChild(div);
            });
        }

        function renderDevueltos() {
            dropZone.innerHTML = '';
            devueltos.forEach((row, key) => {
                const div = document.createElement('div');
                div.className = 'item-card';
                div.innerHTML = `
                <div style="font-weight:bold;">${row.descripcion}</div>
                <div class="edit-row" style="display:flex; gap:4px; align-items:center;">
                    Cant: <input type="number" value="${row.cantidad}" id="qty_${key}" style="width:60px;">
                    Precio: <input type="number" value="${row.precio}" id="prcV_${key}" style="width:80px;">
                    <button class="btn-action btn-danger" style="padding:4px 8px; font-size:0.8rem;" id="del_${key}">X</button>
                </div>
                <div style="font-size:0.8rem; margin-top:4px;">Max: ${row.maxCant}</div>
            `;
                dropZone.appendChild(div);

                div.querySelector(`#qty_${key}`).addEventListener('change', (e) => {
                    let v = parseFloat(e.target.value);
                    if (v > row.maxCant) v = row.maxCant;
                    if (v < 0) v = 0;
                    row.cantidad = v;
                    e.target.value = v;
                    updateTotal();
                });
                div.querySelector(`#prcV_${key}`).addEventListener('change', (e) => {
                    row.precio = parseFloat(e.target.value);
                    updateTotal();
                });
                div.querySelector(`#del_${key}`).addEventListener('click', () => {
                    devueltos.delete(key);
                    renderDevueltos();
                    updateTotal();
                });
            });
            updateTotal();
        }

        function updateTotal() {
            let t = 0;
            devueltos.forEach(r => t += (r.cantidad * r.precio));
            document.getElementById('totalDev').innerText = t.toLocaleString('es-PY') + ' Gs';
        }

        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('over'); });
        dropZone.addEventListener('dragleave', e => { dropZone.classList.remove('over'); });
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            dropZone.classList.remove('over');
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            const key = data.idx;

            if (!devueltos.has(key)) {
                devueltos.set(key, {
                    codigo: data.codigo,
                    descripcion: data.descripcion,
                    cantidad: data.cantidad,
                    precio: data.precio,
                    maxCant: data.cantidad,
                    tasa_iva: data.tasa_iva,
                    unidad_medida: data.unidad_medida
                });
                renderDevueltos();
            }
        });

        // -- TOM SELECT SETUP --
        let tomSelectInstance;

        // Function to trigger search (adapted from previous btnBuscarCDC)
        async function loadFacturaByCDC(cdc) {
            if (!cdc) return;
            document.getElementById('cdcInput').value = cdc; // Sync hidden

            resultBox.innerText = 'Buscando datos de factura...';

            const formData = new FormData();
            formData.append('action', 'get_factura');
            formData.append('cdc', cdc);
            formData.append('id_empresa', ID_EMPRESA);

            try {
                const resp = await fetch('', { method: 'POST', body: formData });
                const json = await resp.json();
                if (json.success && json.factura) {
                    facturaActual = json.factura;
                    document.getElementById('clienteInfo').innerText = (facturaActual.receptor.razon_social || 'Cliente') + ' - ' + (facturaActual.receptor.documento || '');
                    document.getElementById('facturaInfo').innerText = 'Factura: ' + (facturaActual.nro_factura || 'N/A');
                    renderFacturaItems();
                    resultBox.innerText = 'Factura cargada correctamente.';

                    // Auto fill client info or helpful logs?
                } else {
                    resultBox.innerText = 'No encontrada o error: ' + (json.message || 'Desconocido');
                    facturaActual = null;
                    renderFacturaItems();
                }
            } catch (e) {
                resultBox.innerText = 'Error de red al buscar factura: ' + e.message;
            }
        }

        if (document.getElementById('invoiceSelect')) {
            tomSelectInstance = new TomSelect('#invoiceSelect', {
                valueField: 'value',
                labelField: 'text',
                searchField: 'text',
                maxItems: 1,
                placeholder: 'Escriba Nro Factura o Cliente...',
                load: function (query, callback) {
                    if (!query.length) return callback();
                    console.log("TomSelect query:", query);

                    const formData = new FormData();
                    formData.append('action', 'search_facturas');
                    formData.append('q', query);
                    formData.append('id_empresa', ID_EMPRESA);

                    fetch('', { method: 'POST', body: formData })
                        .then(response => response.text()) // Get text first to debug
                        .then(text => {
                            console.log("Raw Response length:", text.length);
                            try {
                                const json = JSON.parse(text);

                                // Log all server-side traces
                                if (json.logs && Array.isArray(json.logs)) {
                                    console.group("SERVER LOGS");
                                    json.logs.forEach(l => console.log(l));
                                    console.groupEnd();
                                }

                                const results = Array.isArray(json) ? json : (json.items || []);
                                callback(results);
                            } catch (e) {
                                console.error("JSON Parse Error:", e, "Content:", text);
                                callback();
                            }
                        }).catch((err) => {
                            console.error("Fetch Network Error:", err);
                            callback();
                        });
                },
                render: {
                    option: function (data, escape) {
                        const parts = data.text.split(' - ');
                        const nro = parts[0] || '';
                        const cli = parts[1] || '';
                        const cdc = parts[2] || '';
                        return '<div style="padding: 4px 8px;">' +
                            '<div style="font-weight: bold; color: #38bdf8;">' + escape(nro) + '</div>' +
                            '<div style="font-size: 0.9em; margin: 2px 0;">' + escape(cli) + '</div>' +
                            '<div style="font-size: 0.75em; color: #94a3b8; font-family: monospace;">' + escape(cdc) + '</div>' +
                            '</div>';
                    },
                    item: function (data, escape) {
                        const parts = data.text.split(' - ');
                        return '<div>' + escape(parts[0] + ' - ' + parts[1]) + '</div>';
                    }
                },
                onChange: function (value) {
                    if (value) loadFacturaByCDC(value);
                }
            });
        }

        function eliminarNC(id) {
            Swal.fire({
                title: '¿Eliminar Registro Local?',
                text: "Esto borrará la Nota de Crédito de su base de datos local. NO se enviará nada a SIFEN. Esta acción no se puede deshacer.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Sí, Eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'Eliminando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    const formData = new FormData();
                    formData.append('action', 'delete_nc');
                    formData.append('id', id);

                    fetch(location.href, { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire('Eliminado', 'El registro ha sido eliminado.', 'success')
                                    .then(() => location.reload());
                            } else {
                                Swal.fire('Error', data.message || 'No se pudo eliminar', 'error');
                            }
                        })
                        .catch(err => Swal.fire('Error', err.message, 'error'));
                }
            });
        }

        async function anularNC(data) {
            if (!data.cdc) return Swal.fire('Error', 'Esta Nota de Crédito no tiene un CDC válido.', 'error');

            const { value: motivo } = await Swal.fire({
                title: 'Anular Nota de Crédito',
                text: `¿Está seguro que desea anular la NC ${data.nro_factura}?`,
                input: 'select',
                inputOptions: {
                    'Error de digitación': 'Error de digitación',
                    'Devolución total': 'Devolución total',
                    'Factura mal emitida': 'Factura mal emitida',
                    'Otros': 'Otros'
                },
                inputPlaceholder: 'Seleccione un motivo',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Sí, Anular',
                cancelButtonText: 'Cancelar'
            });

            if (motivo) {
                Swal.fire({ title: 'Anulando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                try {
                    const payload = {
                        action: 'anular',
                        cdc: data.cdc,
                        motivo_anulacion: motivo,
                        cert_path: CONFIG.cert_path,
                        cert_pass: CONFIG.cert_pass,
                        modo: CONFIG.modo,
                        ruc: CONFIG.ruc
                    };

                    const r = await fetch('nc_debug.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const d = await r.json();

                    if (d.success) {
                        Swal.fire('Debug Success', JSON.stringify(d), 'success');
                    } else {
                        Swal.fire('Error', d.message, 'error');
                    }
                } catch (e) {
                    Swal.fire('Error', 'Error de red: ' + e.message, 'error');
                }
            }
        }


        document.getElementById('btnEnviarNC').addEventListener('click', async () => {
            if (!facturaActual) return alert('Cargue factura primero');
            if (devueltos.size === 0) return alert('Agregue items a devolver');

            const conceptos = [];
            devueltos.forEach(r => {
                if (r.cantidad > 0) conceptos.push(r);
            });

            const payload = {
                cert_path: document.getElementById('certPathInput').value,
                cert_pass: document.getElementById('certPassInput').value,
                modo: document.getElementById('modoInput').value,
                cdc: document.getElementById('cdcInput').value,
                ndoc: document.getElementById('ndocInput').value,
                motivo: document.getElementById('motivoInput').value,
                preview: document.getElementById('previewInput').checked,
                timbrado: CONFIG.timbrado,
                fec_timbrado: CONFIG.timbradoFecha,
                cod_establecimiento: CONFIG.establecimiento,
                cod_expedicion: CONFIG.punto_expedicion,
                id_empresa: ID_EMPRESA,
                emisor: facturaActual.emisor,
                receptor: facturaActual.receptor,
                conceptos: conceptos
            };

            resultBox.innerText = 'Enviando...';
            try {
                const r = await fetch(API_NC_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const d = await r.json();
                resultBox.innerText = JSON.stringify(d, null, 2);
                if (d.success) {
                    setTimeout(() => location.reload(), 2000);
                }
            } catch (e) {
                resultBox.innerText = 'Error envío: ' + e.message;
            }
        });
    </script>
</body>

</html>