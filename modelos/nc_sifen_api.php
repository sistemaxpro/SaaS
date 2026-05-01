<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

// Include global autoload if needed (adjust path if necessary)
include_once __DIR__ . '/../../_lib/php-sifen3/autoload.php';

$action = $_REQUEST['action'] ?? '';
$id_empresa = $_SESSION['id_empresa'] ?? $_REQUEST['id_empresa'] ?? 0;

// Common DB Config from Session
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error Conexión DB Master: ' . $e->getMessage()]);
    exit;
}

// Route Handling
switch ($action) {
    case 'get_initial_data':
        handleGetInitialData($pdo, $masterDb, $id_empresa);
        break;
    case 'get_factura':
        handleGetFactura($pdo, $masterDb, $id_empresa);
        break;
    case 'get_nc':
        handleGetNc($pdo, $masterDb, $id_empresa);
        break;
    case 'get_next_nc':
        handleGetNextNc($pdo, $masterDb, $id_empresa);
        break;
    case 'search_facturas':
        handleSearchFacturas($pdo, $masterDb, $id_empresa);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida o no especificada']);
        break;
}

function getDbName($pdo, $masterDb, $id_empresa)
{
    $stmt = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    return $stmt->fetchColumn();
}

function handleGetInitialData($pdo, $masterDb, $id_empresa)
{
    global $APP_ROOT; // If needed

    // 1. Get Company Config
    $sql = "
    SELECT
        ruc, ambiente_sifen, cert_pass, cert_path, id_csc, csc, dbase,
        timbrado, establecimiento, punto_expedicion, timbradoFecha,
        vigencia_ini, empresa
    FROM $masterDb.empresa
    WHERE id_empresa = :id
    LIMIT 1
    ";

    $configFe = [];
    $nombreEmpresa = 'Empresa';
    $dbName = '';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id_empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $ruc = trim((string) $row['ruc']);
        // Ambiente: 1 = prod, 2 = test
        $modo = ($row['ambiente_sifen'] == '1') ? 'prod' : 'test';
        $appRoot = __DIR__;

        // Resolve Cert Path
        $rawPath = trim((string) $row['cert_path']);
        if ($rawPath === '') {
            $rawPath = $appRoot . '/_lib/php-sifen3/certificados/' . preg_replace('/\D+/', '', $ruc) . '.p12';
        }
        $certPath = realpath($rawPath) ?: $rawPath;
        // Fix for windows paths if needed or mixed slashes
        $certPath = str_replace('\\', '/', $certPath);

        $dbName = trim((string) $row['dbase']);
        $nombreEmpresa = trim((string) $row['empresa']);

        $configFe = [
            'ruc' => $ruc,
            'modo' => $modo,
            'cert_pass' => trim((string) $row['cert_pass']),
            'cert_path' => $certPath,
            'idc' => trim((string) $row['id_csc']),
            'csc' => trim((string) $row['csc']),
            'timbrado' => trim((string) $row['timbrado']),
            'establecimiento' => trim((string) $row['establecimiento']),
            'punto_expedicion' => trim((string) $row['punto_expedicion']),
            'timbradoFecha' => $row['vigencia_ini'] ?: $row['timbradoFecha'],
        ];
    } else {
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        exit;
    }

    // 2. Get Initial Grid Data (NCs)
    $ncRows = [];
    if ($dbName) {
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
        try {
            $stmtNC = $pdo->query($sqlNC);
            $rows = $stmtNC->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $ncRows[] = [
                    'id_factura' => $r['id_factura'],
                    'fecha' => $r['fecha'],
                    'nro_factura' => $r['nro_factura'] ?? '',
                    'razon_social' => $r['razon_social'] ?? '',
                    'ruc_cliente' => $r['ruc_cliente'] ?? '',
                    'total' => (float) ($r['total'] ?? 0),
                    'estado_electronico' => $r['estado_electronico'] ?? '',
                    'msg_res' => $r['msg_res'] ?? '',
                    'cdc' => $r['cdc'],
                    'tipo_documento' => 'NC',
                ];
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
    }

    echo json_encode([
        'success' => true,
        'config' => $configFe,
        'ncRows' => $ncRows,
        'nombreEmpresa' => $nombreEmpresa,
        'id_empresa' => (int) $id_empresa
    ]);
    exit;
}

function handleGetFactura($pdo, $masterDb, $id_empresa)
{
    $cdc = $_POST['cdc'] ?? '';
    if (!$cdc) {
        echo json_encode(['success' => false, 'message' => 'CDC requerido']);
        exit;
    }

    $dbName = getDbName($pdo, $masterDb, $id_empresa);
    if (!$dbName) {
        echo json_encode(['success' => false, 'message' => 'DB no encontrada']);
        exit;
    }

    $stmtFunc = $pdo->prepare("SELECT * FROM $dbName.factura_ventas WHERE cdc = :cdc LIMIT 1");
    $stmtFunc->execute([':cdc' => $cdc]);
    $row = $stmtFunc->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Factura no encontrada']);
        exit;
    }

    // Emisor
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

    $cleanTel = ($clienteTel && trim($clienteTel) !== '-' && trim($clienteTel) !== '--') ? $clienteTel : '0981000000';
    $cleanEmail = ($clienteEmail && trim($clienteEmail) !== '-' && trim($clienteEmail) !== '--') ? $clienteEmail : 'sinemail@sifen.test';

    $receptor = [
        'razon_social' => $clienteName ?: 'Sin Nombre',
        'documento' => $clienteRuc ?: '0',
        'tipo_doc' => $clienteTipoDoc,
        'direccion' => $clienteDir,
        'telefono' => $cleanTel,
        'email' => $cleanEmail,
        'ciudad' => $clienteCiudad,
    ];

    // Items
    $conceptos = [];
    try {
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
    }

    if (empty($conceptos)) {
        $conceptos[] = [
            'codigo' => 'GEN',
            'descripcion' => 'Total Factura',
            'cantidad' => 1,
            'precio' => (float) $row['total'],
            'tasa_iva' => 10,
            'unidad_medida' => 77
        ];
    }

    echo json_encode([
        'success' => true,
        'factura' => [
            'id_factura' => $row['id_factura'],
            'cdc' => $row['cdc'],
            'nro_factura' => $row['nro_factura'],
            'timbrado' => $row['timbrado'],
            'fec_timbrado' => $row['vencimiento_timbrado'],
            'emisor' => $emisor,
            'receptor' => $receptor,
            'conceptos' => $conceptos
        ]
    ]);
    exit;
}

function handleGetNc($pdo, $masterDb, $id_empresa)
{
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID NC requerido']);
        exit;
    }

    $dbName = getDbName($pdo, $masterDb, $id_empresa);
    $stmtNC = $pdo->prepare("SELECT * FROM $dbName.de_nc WHERE id = :id");
    $stmtNC->execute([':id' => $id]);
    $ncMaster = $stmtNC->fetch(PDO::FETCH_ASSOC);

    if (!$ncMaster) {
        echo json_encode(['success' => false, 'message' => 'Nota de Crédito no encontrada']);
        exit;
    }

    $stmtItems = $pdo->prepare("SELECT * FROM $dbName.de_nc_items WHERE nota_credito_id = :id");
    $stmtItems->execute([':id' => $id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'nc' => $ncMaster, 'items' => $items]);
    exit;
}

function handleGetNextNc($pdo, $masterDb, $id_empresa)
{
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

    $sqlMax = "SELECT MAX(CAST(SUBSTRING_INDEX(ndoc, '-', -1) AS UNSIGNED)) FROM $dbName.de_nc WHERE timbrado = :timbrado";
    $stmtMax = $pdo->prepare($sqlMax);
    $stmtMax->execute([':timbrado' => $timbrado]);
    $maxSeq = (int) $stmtMax->fetchColumn();

    $nextSeq = $maxSeq + 1;
    $ndoc = "$est-$pex-" . str_pad($nextSeq, 7, '0', STR_PAD_LEFT);

    echo json_encode(['success' => true, 'ndoc' => $ndoc, 'timbrado' => $timbrado]);
    exit;
}

function handleSearchFacturas($pdo, $masterDb, $id_empresa)
{
    $q = trim($_POST['q'] ?? '');
    $logs = [];
    $items = [];

    $dbName = getDbName($pdo, $masterDb, $id_empresa);
    if (!$dbName) {
        echo json_encode(['items' => [], 'logs' => ['DB not found']]);
        exit;
    }

    $sqlSearch = "
    SELECT fv.id_factura, fv.nro_factura, fv.cdc, fv.fecha, fv.total, c.nombre AS cliente_nombre, c.numero AS cliente_ruc
    FROM $dbName.factura_ventas fv
    LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
    WHERE fv.cdc > '' AND (fv.nro_factura LIKE :q OR c.nombre LIKE :q OR fv.cdc LIKE :q)
    ORDER BY fv.id_factura DESC LIMIT 20";

    $term = "%{$q}%";
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

    echo json_encode(['items' => $items, 'logs' => $logs]);
    exit;
}
