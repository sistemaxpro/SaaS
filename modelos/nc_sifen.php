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
        $clienteTipoDoc = 'ci';

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
                // tipo_doc: 'ruc' si tiene numero (RUC), 'ci' si solo tiene documento (CI)
                $clienteTipoDoc = $cli['numero'] ? 'ruc' : 'ci';
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
                // Determinar tasa IVA basado en los montos
                $tasa = 0;
                $iva10Val = (float)($d['iva10'] ?? 0);
                $iva5Val = (float)($d['iva5'] ?? 0);
                $exentaVal = (float)($d['exenta'] ?? 0);
                $precioVal = (float)($d['precio'] ?? 0);

                if ($iva10Val > 0) {
                    $tasa = 10;
                } elseif ($iva5Val > 0) {
                    $tasa = 5;
                } elseif ($exentaVal > 0) {
                    $tasa = 0; // Explícitamente exento
                } elseif ($precioVal > 0) {
                    // Si hay precio pero no hay desglose de IVA ni exenta, asumir IVA 10%
                    $tasa = 10;
                }

                $conceptos[] = [
                    'codigo' => $d['codigo'] ?? '0',
                    'descripcion' => $d['descripcion'] ?? 'Item',
                    'cantidad' => (float) ($d['cantidad'] ?? 0),
                    'precio' => $precioVal,
                    'tasa_iva' => $tasa,
                    'descuento' => 0,
                    'proporcion_iva' => ($tasa > 0) ? 100 : 0,
                    'unidad_medida' => 77
                ];
            }
        } catch (Exception $ex) {
            error_log("NC get_factura error: " . $ex->getMessage());
        }

        if (empty($conceptos)) {
            $conceptos[] = [
                'codigo' => 'GEN',
                'descripcion' => 'Total Factura (Detalle no disponible)',
                'cantidad' => 1,
                'precio' => (float) $row['total'],
                'tasa_iva' => 10,
                'descuento' => 0,
                'proporcion_iva' => 100,
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

// Constante para tipo de documento NC (Nota de Crédito Electrónica)
define('TIPO_DOC_NC', 5);

// Variables de configuración
$ruc = '';
$dv = '';
$razonSocial = '';
$nombreEmpresa = '';
$modo = 'test';
$cert_pass = '';
$cert_path = '';
$cert_path_dir = '';
$cert_nombre = '';
$idc = '';
$csc = '';
$dbName = '';
$timbrado = '';
$timbradoFecha = '';
$establecimiento = '001';
$punto_expedicion = '001';

// Query para obtener TODA la configuración desde habilitacion_sifen
$sqlHabilitacion = "
SELECT 
    h.ruc,
    h.dv,
    h.razon_social,
    h.empresa,
    h.numero_timbrado,
    h.fecha_inicio_vigencia,
    h.csc,
    h.id_csc,
    h.cert_nombre,
    h.cert_pass,
    h.cert_path,
    h.ambiente,
    h.cod_depto,
    h.cod_ciudad,
    h.direccion,
    h.numero_casa,
    h.telefono,
    h.email,
    d.codigo_establecimiento,
    d.punto_expedicion,
    e.dbase
FROM $masterDb.habilitacion_sifen h
JOIN $masterDb.habilitacion_sifen_documentos d ON d.id_habilitacion = h.id
LEFT JOIN $masterDb.empresa e ON e.id_empresa = h.id_empresa
WHERE h.id_empresa = :id_empresa 
  AND d.tipo_documento = :tipo_documento
  AND h.activo = 1
  AND d.activo = 1
ORDER BY h.id DESC
LIMIT 1
";

if ($pdo) {
    try {
        $stmtHab = $pdo->prepare($sqlHabilitacion);
        $stmtHab->execute([':id_empresa' => $id_empresa, ':tipo_documento' => TIPO_DOC_NC]);

        if ($rowHab = $stmtHab->fetch(PDO::FETCH_ASSOC)) {
            // Datos del emisor
            $ruc = trim((string) $rowHab['ruc']);
            $dv = trim((string) $rowHab['dv']);
            $razonSocial = trim((string) $rowHab['razon_social']);
            $nombreEmpresa = trim((string) $rowHab['empresa']) ?: $razonSocial;
            $dbName = trim((string) $rowHab['dbase']);

            // Datos del timbrado
            $timbrado = trim((string) $rowHab['numero_timbrado']);
            $timbradoFecha = trim((string) $rowHab['fecha_inicio_vigencia']);

            // Establecimiento y punto de expedición (ya vienen formateados a 3 dígitos)
            $establecimiento = trim((string) $rowHab['codigo_establecimiento']) ?: '001';
            $punto_expedicion = trim((string) $rowHab['punto_expedicion']) ?: '001';

            // Credenciales SIFEN
            $csc = trim((string) $rowHab['csc']);
            $idc = trim((string) $rowHab['id_csc']) ?: '0001'; // Default 0001 si está vacío
            $cert_pass = trim((string) $rowHab['cert_pass']);
            $cert_nombre = trim((string) $rowHab['cert_nombre']);
            $cert_path_dir = trim((string) $rowHab['cert_path']);

            // Construir ruta completa del certificado
            if ($cert_path_dir && $cert_nombre) {
                $cert_path = rtrim($cert_path_dir, '/') . '/' . $cert_nombre;
            } elseif ($cert_nombre) {
                $cert_path = __DIR__ . '/_lib/php-sifen3/certificados/' . $cert_nombre;
            } else {
                $cert_path = __DIR__ . '/_lib/php-sifen3/certificados/' . $ruc . '.p12';
            }

            // Ambiente
            $amb_hab = strtolower(trim((string) $rowHab['ambiente']));
            if ($amb_hab === 'prod' || $amb_hab === '2') {
                $modo = 'prod';
            } else {
                $modo = 'test';
            }
            // Usar siempre el timbrado de la empresa (asignado por SIFEN durante habilitación)

            // Datos adicionales para el emisor
            $emisorData = [
                'cod_depto' => (int) $rowHab['cod_depto'],
                'cod_ciudad' => (int) $rowHab['cod_ciudad'],
                'direccion' => trim((string) $rowHab['direccion']),
                'numero_casa' => trim((string) $rowHab['numero_casa']),
                'telefono' => trim((string) $rowHab['telefono']),
                'email' => trim((string) $rowHab['email']),
            ];
        } else {
            // Fallback: no hay habilitación configurada
            error_log("NC_SIFEN: No se encontró habilitación para empresa $id_empresa y tipo_documento " . TIPO_DOC_NC);
            $timbrado = '0';
            $timbradoFecha = date('Y-m-d');
        }
    } catch (Exception $e) {
        error_log("NC_SIFEN Error: " . $e->getMessage());
    }
}

// Resolver ruta del certificado
$resolvedCertPath = '';
// Debug: log las variables de certificado
error_log("NC_SIFEN Debug cert - cert_path_dir: " . ($cert_path_dir ?? 'NULL') . ", cert_nombre: " . ($cert_nombre ?? 'NULL') . ", cert_path construido: $cert_path");

if ($cert_path && is_file($cert_path)) {
    $resolvedCertPath = realpath($cert_path);
    error_log("NC_SIFEN Debug - cert_path verificado como archivo: $resolvedCertPath");
} elseif ($cert_path && file_exists($cert_path)) {
    // Es un directorio, intentar construir la ruta completa
    error_log("NC_SIFEN Warn - cert_path es un directorio: $cert_path");
    $resolvedCertPath = '';
} elseif ($ruc) {
    $fallbackPath = __DIR__ . '/_lib/php-sifen3/certificados/' . preg_replace('/\D+/', '', $ruc) . '.p12';
    if (file_exists($fallbackPath)) {
        $resolvedCertPath = realpath($fallbackPath);
        error_log("NC_SIFEN Debug - Usando fallback cert: $resolvedCertPath");
    }
}

$configFe = [
    'ruc' => $ruc,
    'dv' => $dv,
    'razon_social' => $razonSocial,
    'modo' => $modo,
    'cert_pass' => $cert_pass,
    'cert_path' => $resolvedCertPath,
    'idc' => $idc,
    'csc' => $csc,
    'timbrado' => $timbrado,
    'establecimiento' => $establecimiento,
    'punto_expedicion' => $punto_expedicion,
    'timbradoFecha' => $timbradoFecha,
];

// Query for Credit Notes
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbName = $dbName ?: ($_SESSION['db'] ?? 'serproc1');

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
    <script src="_lib/ag-grid/license.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        // Apply theme from LocalStorage
        // Logic: Add 'dark' class ONLY if theme is 'dark'. Otherwise remove it (Light is default).
        (function() {
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

        /* Alpine Modal/Toast Styles */
        .alpine-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .alpine-modal {
            background: var(--panel);
            border-radius: 12px;
            padding: 24px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border);
        }

        .alpine-modal-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
        }

        .alpine-modal-icon.success {
            background: #dcfce7;
            color: #16a34a;
        }

        .alpine-modal-icon.error {
            background: #fee2e2;
            color: #dc2626;
        }

        .alpine-modal-icon.warning {
            background: #fef3c7;
            color: #d97706;
        }

        .alpine-modal-icon.info {
            background: #dbeafe;
            color: #2563eb;
        }

        .alpine-modal-icon.loading {
            background: #e0e7ff;
            color: #4f46e5;
        }

        html.dark .alpine-modal-icon.success {
            background: #166534;
            color: #86efac;
        }

        html.dark .alpine-modal-icon.error {
            background: #991b1b;
            color: #fca5a5;
        }

        html.dark .alpine-modal-icon.warning {
            background: #92400e;
            color: #fcd34d;
        }

        html.dark .alpine-modal-icon.info {
            background: #1e40af;
            color: #93c5fd;
        }

        html.dark .alpine-modal-icon.loading {
            background: #3730a3;
            color: #a5b4fc;
        }

        .alpine-modal h3 {
            margin: 0 0 8px;
            font-size: 1.25rem;
            text-align: center;
            color: var(--text);
        }

        .alpine-modal p {
            margin: 0 0 20px;
            text-align: center;
            color: var(--muted);
            font-size: 0.95rem;
            max-height: 200px;
            overflow-y: auto;
            word-break: break-word;
        }

        .alpine-modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .alpine-modal-btn {
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 0.9rem;
        }

        .alpine-modal-btn.primary {
            background: #2563eb;
            color: white;
        }

        .alpine-modal-btn.primary:hover {
            background: #1d4ed8;
        }

        .alpine-modal-btn.danger {
            background: #dc2626;
            color: white;
        }

        .alpine-modal-btn.danger:hover {
            background: #b91c1c;
        }

        .alpine-modal-btn.cancel {
            background: var(--border);
            color: var(--text);
        }

        .alpine-modal-btn.cancel:hover {
            opacity: 0.8;
        }

        .alpine-spinner {
            width: 24px;
            height: 24px;
            border: 3px solid var(--border);
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .alpine-select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--input-bg);
            color: var(--input-text);
            font-size: 0.95rem;
            margin-bottom: 16px;
        }

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

        /* Tom Select Overrides - Light Mode */
        .ts-wrapper {
            width: 100%;
        }

        .ts-wrapper .ts-control {
            border-radius: 6px !important;
            border: 1px solid #cbd5e1 !important;
            background: #fff !important;
            color: #1e293b !important;
            padding: 8px 12px !important;
            min-height: 42px !important;
            font-size: 0.95rem !important;
        }

        .ts-wrapper .ts-control input {
            color: #1e293b !important;
            font-size: 0.95rem !important;
        }

        .ts-wrapper .ts-control input::placeholder {
            color: #94a3b8 !important;
            opacity: 1 !important;
        }

        .ts-wrapper .ts-dropdown {
            background: #fff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
            margin-top: 4px !important;
        }

        .ts-wrapper .ts-dropdown .option {
            padding: 10px 12px !important;
            color: #1e293b !important;
        }

        .ts-wrapper .ts-dropdown .option.active,
        .ts-wrapper .ts-dropdown .option:hover {
            background: #e0f2fe !important;
            color: #0284c7 !important;
        }

        .ts-wrapper .ts-dropdown .no-results {
            padding: 10px 12px !important;
            color: #64748b !important;
        }

        /* Tom Select - Dark Mode */
        html.dark .ts-wrapper .ts-control {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            border-color: #475569 !important;
        }

        html.dark .ts-wrapper .ts-control input {
            color: #e2e8f0 !important;
        }

        html.dark .ts-wrapper .ts-control input::placeholder {
            color: #94a3b8 !important;
        }

        html.dark .ts-wrapper .ts-dropdown {
            background: #1e293b !important;
            border-color: #475569 !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
        }

        html.dark .ts-wrapper .ts-dropdown .option {
            color: #e2e8f0 !important;
        }

        html.dark .ts-wrapper .ts-dropdown .option.active,
        html.dark .ts-wrapper .ts-dropdown .option:hover {
            background: #334155 !important;
            color: #38bdf8 !important;
        }

        html.dark .ts-wrapper .ts-dropdown .no-results {
            color: #94a3b8 !important;
        }

        /* Fix focus states */
        .ts-wrapper.focus .ts-control {
            border-color: #38bdf8 !important;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.25) !important;
        }

        .ts-control,
        .ts-control input,
        .ts-dropdown {
            font-family: inherit;
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
            <!-- Dropdown Más -->
            <div class="dropdown-container" style="position: relative; display: inline-block;">
                <button type="button" class="btn-chip btn-chip--alt" id="btnMasMenu">
                    <i class="fa-solid fa-bars"></i> Más <i class="fa-solid fa-chevron-down" style="font-size: 0.7rem;"></i>
                </button>
                <div id="dropdownMas" class="dropdown-menu" style="display: none; position: absolute; right: 0; top: 100%; margin-top: 8px; min-width: 200px; background: var(--panel); border: 1px solid #334155; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); z-index: 100;">
                    <a href="facturas_sifen.php" style="display: block; padding: 10px 16px; color: var(--text); text-decoration: none; font-size: 0.9rem;">
                        <i class="fa-solid fa-file-invoice w-5 text-center" style="width: 20px; margin-right: 8px;"></i> Facturas
                    </a>
                    <a href="nr_sifen.php" style="display: block; padding: 10px 16px; color: var(--text); text-decoration: none; font-size: 0.9rem;">
                        <i class="fa-solid fa-truck w-5 text-center" style="width: 20px; margin-right: 8px;"></i> Nota Remisión
                    </a>
                    <a href="nd_sifen.php" style="display: block; padding: 10px 16px; color: var(--text); text-decoration: none; font-size: 0.9rem;">
                        <i class="fa-solid fa-file-circle-plus w-5 text-center" style="width: 20px; margin-right: 8px;"></i> Nota Débito
                    </a>
                </div>
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
                    <div class="nc-row" style="margin-top:12px; display: none;"> <!-- Oculto: Ambiente y Certificado -->
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

        // -- BUTTON HANDLERS (Init specific buttons early) --
        const btnSalir = document.getElementById('btnSalir');
        if (btnSalir) {
            btnSalir.addEventListener('click', (e) => {
                e.preventDefault();
                // Avoid reloading if referrer is self
                if (document.referrer && document.referrer !== window.location.href) {
                    window.location.href = document.referrer;
                } else {
                    window.history.back();
                }
            });
        }

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
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class']
        });

        window.addEventListener('storage', (e) => {
            if (e.key === 'theme') {
                if (e.newValue === 'dark') document.documentElement.classList.add('dark');
                else document.documentElement.classList.remove('dark');
                // Observer will catch this
            }
        });

        const columnDefs = [{
                headerName: 'ID',
                field: 'id_factura',
                width: 80,
                sortable: true
            },
            {
                headerName: 'Fecha',
                field: 'fecha',
                width: 140,
                sortable: true
            },
            {
                headerName: 'Nro Nota Crédito',
                field: 'nro_factura',
                width: 150
            },
            {
                headerName: 'Cliente',
                field: 'razon_social',
                width: 200
            },
            {
                headerName: 'RUC',
                field: 'ruc_cliente',
                width: 120
            },
            {
                headerName: 'CDC',
                field: 'cdc',
                width: 280
            },
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
                cellStyle: {
                    'text-align': 'right'
                },
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
                                     onclick="showErrorDetail('Detalle del Error', '${safeMsg}')"
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
                statusPanels: [{
                    statusPanel: 'agAggregationComponent',
                    align: 'right'
                }]
            },
            sideBar: {
                toolPanels: [{
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
                const result = [{
                        name: 'Editar Nota de Crédito',
                        icon: '<i class="fa-solid fa-pen-to-square"></i>',
                        action: () => {
                            openEditModal(params.node.data);
                        }
                    },
                    (params.node.data.estado_electronico === 'Rechazado' || params.node.data.estado_electronico === 'Error') ? {
                        name: 'Eliminar Registro (Local)',
                        icon: '<i class="fa-solid fa-trash" style="color:#ef4444;"></i>',
                        action: () => {
                            eliminarNC(params.node.data.id_factura);
                        }
                    } : {
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

        // -- DROPDOWN MÁS MENU --
        const btnMasMenu = document.getElementById('btnMasMenu');
        const dropdownMas = document.getElementById('dropdownMas');
        if (btnMasMenu && dropdownMas) {
            btnMasMenu.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdownMas.style.display = dropdownMas.style.display === 'none' ? 'block' : 'none';
            });
            document.addEventListener('click', () => {
                dropdownMas.style.display = 'none';
            });
            dropdownMas.querySelectorAll('a').forEach(a => {
                a.addEventListener('mouseenter', () => a.style.background = '#334155');
                a.addEventListener('mouseleave', () => a.style.background = 'transparent');
            });
        }

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



        btnNew.addEventListener('click', async () => {
            resetModal();
            modal.classList.add('open');

            // Fetch next sequence
            try {
                const formData = new FormData();
                formData.append('action', 'get_next_nc');
                const resp = await fetch(location.href, {
                    method: 'POST',
                    body: formData
                });
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

            showLoading('Cargando datos...');

            try {
                const formData = new FormData();
                formData.append('action', 'get_nc');
                formData.append('id', data.id_factura);

                const resp = await fetch(location.href, {
                    method: 'POST',
                    body: formData
                });
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
                                const orig = facturaActual.conceptos[originalIdx];
                                const concepto = {
                                    ...orig,
                                    cantidad: parseFloat(ni.cantidad),
                                    precio: parseFloat(ni.precio),
                                    maxCant: orig.cantidad,
                                    descuento: orig.descuento || 0,
                                    proporcion_iva: orig.proporcion_iva ?? (orig.tasa_iva > 0 ? 100 : 0)
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

                    closeModal();
                } else {
                    showError('Error', res.message);
                }
            } catch (err) {
                showError('Error', err.message);
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
                    ev.dataTransfer.setData('text/plain', JSON.stringify({
                        ...c,
                        idx
                    }));
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

        dropZone.addEventListener('dragover', e => {
            e.preventDefault();
            dropZone.classList.add('over');
        });
        dropZone.addEventListener('dragleave', e => {
            dropZone.classList.remove('over');
        });
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
                    tasa_iva: data.tasa_iva || 0,
                    descuento: data.descuento || 0,
                    proporcion_iva: data.proporcion_iva ?? (data.tasa_iva > 0 ? 100 : 0),
                    unidad_medida: data.unidad_medida || 77
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
                const resp = await fetch('', {
                    method: 'POST',
                    body: formData
                });
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
                load: function(query, callback) {
                    if (!query.length) return callback();
                    console.log("TomSelect query:", query);

                    const formData = new FormData();
                    formData.append('action', 'search_facturas');
                    formData.append('q', query);
                    formData.append('id_empresa', ID_EMPRESA);

                    fetch('', {
                            method: 'POST',
                            body: formData
                        })
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
                    option: function(data, escape) {
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
                    item: function(data, escape) {
                        const parts = data.text.split(' - ');
                        return '<div>' + escape(parts[0] + ' - ' + parts[1]) + '</div>';
                    }
                },
                onChange: function(value) {
                    if (value) loadFacturaByCDC(value);
                }
            });
        }

        async function eliminarNC(id) {
            const result = await showConfirm({
                title: '¿Eliminar Registro Local?',
                text: "Esto borrará la Nota de Crédito de su base de datos local. NO se enviará nada a SIFEN. Esta acción no se puede deshacer.",
                confirmText: 'Sí, Eliminar',
                cancelText: 'Cancelar',
                confirmClass: 'danger'
            });

            if (result.confirmed) {
                showLoading('Eliminando...');
                const formData = new FormData();
                formData.append('action', 'delete_nc');
                formData.append('id', id);

                try {
                    const res = await fetch(location.href, {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        await showSuccess('Eliminado', 'El registro ha sido eliminado.');
                        location.reload();
                    } else {
                        showError('Error', data.message || 'No se pudo eliminar');
                    }
                } catch (err) {
                    showError('Error', err.message);
                }
            }
        }

        async function anularNC(data) {
            if (!data.cdc) return showError('Error', 'Esta Nota de Crédito no tiene un CDC válido.');

            const result = await showPrompt({
                title: 'Anular Nota de Crédito',
                text: `¿Está seguro que desea anular la NC ${data.nro_factura}?`,
                inputType: 'select',
                inputOptions: {
                    'Error de digitación': 'Error de digitación',
                    'Devolución total': 'Devolución total',
                    'Factura mal emitida': 'Factura mal emitida',
                    'Otros': 'Otros'
                },
                inputPlaceholder: 'Seleccione un motivo',
                confirmText: 'Sí, Anular',
                cancelText: 'Cancelar'
            });

            if (result.confirmed && result.value) {
                showLoading('Anulando...');

                try {
                    const payload = {
                        action: 'anular',
                        cdc: data.cdc,
                        motivo_anulacion: result.value,
                        cert_path: CONFIG.cert_path,
                        cert_pass: CONFIG.cert_pass,
                        modo: CONFIG.modo,
                        ruc: CONFIG.ruc
                    };

                    const r = await fetch('nc_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });
                    const d = await r.json();

                    if (d.success) {
                        showSuccess('Éxito', JSON.stringify(d));
                    } else {
                        showError('Error', d.message);
                    }
                } catch (e) {
                    showError('Error', 'Error de red: ' + e.message);
                }
            }
        }


        document.getElementById('btnEnviarNC').addEventListener('click', async () => {
            if (!facturaActual) return showError('Error', 'Cargue factura primero');
            if (devueltos.size === 0) return showError('Error', 'Agregue items a devolver');

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
                csc: CONFIG.csc,
                idc: CONFIG.idc,
                id_empresa: ID_EMPRESA,
                emisor: facturaActual.emisor,
                receptor: facturaActual.receptor,
                conceptos: conceptos
            };

            console.log('Payload NC:', JSON.stringify(payload, null, 2));
            console.log('CONFIG:', CONFIG);

            resultBox.innerText = 'Enviando...';
            try {
                const r = await fetch(API_NC_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
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

    <!-- Alpine.js Modal Component -->
    <div x-data="modalManager()" x-cloak>
        <!-- Modal Overlay -->
        <template x-if="modal.show">
            <div class="alpine-modal-overlay" @click.self="modal.allowClose && closeModal()">
                <div class="alpine-modal" @click.stop>
                    <!-- Icon -->
                    <div class="alpine-modal-icon" :class="modal.type">
                        <template x-if="modal.type === 'success'"><i class="fa-solid fa-check"></i></template>
                        <template x-if="modal.type === 'error'"><i class="fa-solid fa-xmark"></i></template>
                        <template x-if="modal.type === 'warning'"><i class="fa-solid fa-exclamation"></i></template>
                        <template x-if="modal.type === 'info'"><i class="fa-solid fa-info"></i></template>
                        <template x-if="modal.type === 'loading'">
                            <div class="alpine-spinner"></div>
                        </template>
                    </div>

                    <!-- Title -->
                    <h3 x-text="modal.title"></h3>

                    <!-- Message -->
                    <p x-html="modal.message"></p>

                    <!-- Select Input (for confirm with select) -->
                    <template x-if="modal.inputType === 'select' && modal.inputOptions">
                        <select class="alpine-select" x-model="modal.inputValue">
                            <option value="" x-text="modal.inputPlaceholder || 'Seleccione...'"></option>
                            <template x-for="(label, value) in modal.inputOptions" :key="value">
                                <option :value="value" x-text="label"></option>
                            </template>
                        </select>
                    </template>

                    <!-- Buttons -->
                    <template x-if="modal.type !== 'loading'">
                        <div class="alpine-modal-buttons">
                            <template x-if="modal.showCancel">
                                <button class="alpine-modal-btn cancel" @click="handleCancel()" x-text="modal.cancelText || 'Cancelar'"></button>
                            </template>
                            <button class="alpine-modal-btn"
                                :class="modal.confirmClass || 'primary'"
                                @click="handleConfirm()"
                                x-text="modal.confirmText || 'Aceptar'"></button>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <script>
        // Alpine.js Modal Manager - Replaces SweetAlert
        function modalManager() {
            return {
                modal: {
                    show: false,
                    type: 'info',
                    title: '',
                    message: '',
                    showCancel: false,
                    confirmText: 'Aceptar',
                    cancelText: 'Cancelar',
                    confirmClass: 'primary',
                    allowClose: true,
                    inputType: null,
                    inputOptions: null,
                    inputValue: '',
                    inputPlaceholder: '',
                    onConfirm: null,
                    onCancel: null,
                    resolvePromise: null
                },

                showModal(options) {
                    return new Promise((resolve) => {
                        this.modal = {
                            show: true,
                            type: options.type || 'info',
                            title: options.title || '',
                            message: options.message || options.text || '',
                            showCancel: options.showCancel || false,
                            confirmText: options.confirmText || 'Aceptar',
                            cancelText: options.cancelText || 'Cancelar',
                            confirmClass: options.confirmClass || 'primary',
                            allowClose: options.allowClose !== false,
                            inputType: options.inputType || null,
                            inputOptions: options.inputOptions || null,
                            inputValue: '',
                            inputPlaceholder: options.inputPlaceholder || '',
                            onConfirm: options.onConfirm || null,
                            onCancel: options.onCancel || null,
                            resolvePromise: resolve
                        };
                    });
                },

                closeModal() {
                    this.modal.show = false;
                },

                handleConfirm() {
                    const value = this.modal.inputValue || true;
                    if (this.modal.onConfirm) this.modal.onConfirm(value);
                    if (this.modal.resolvePromise) this.modal.resolvePromise({
                        confirmed: true,
                        value
                    });
                    this.closeModal();
                },

                handleCancel() {
                    if (this.modal.onCancel) this.modal.onCancel();
                    if (this.modal.resolvePromise) this.modal.resolvePromise({
                        confirmed: false,
                        value: null
                    });
                    this.closeModal();
                }
            };
        }

        // Global modal functions to replace Swal
        let alpineModalInstance = null;

        document.addEventListener('alpine:init', () => {
            Alpine.data('modalManager', modalManager);
        });

        // Wait for Alpine to be ready
        document.addEventListener('alpine:initialized', () => {
            alpineModalInstance = document.querySelector('[x-data="modalManager()"]')?.__x?.$data;
        });

        // Fallback: Get Alpine instance after DOM ready
        setTimeout(() => {
            if (!alpineModalInstance) {
                const el = document.querySelector('[x-data="modalManager()"]');
                if (el && el._x_dataStack) {
                    alpineModalInstance = el._x_dataStack[0];
                }
            }
        }, 100);

        // Modal helper functions (global, to replace Swal calls)
        window.showModal = function(options) {
            if (alpineModalInstance) {
                return alpineModalInstance.showModal(options);
            }
            // Fallback to alert if Alpine not ready
            alert(options.title + '\n' + (options.message || options.text || ''));
            return Promise.resolve({
                confirmed: true
            });
        };

        window.showLoading = function(title = 'Cargando...') {
            return showModal({
                type: 'loading',
                title,
                message: '',
                allowClose: false
            });
        };

        window.closeModal = function() {
            if (alpineModalInstance) alpineModalInstance.closeModal();
        };

        window.showSuccess = function(title, message = '') {
            return showModal({
                type: 'success',
                title,
                message
            });
        };

        window.showError = function(title, message = '') {
            return showModal({
                type: 'error',
                title,
                message
            });
        };

        window.showConfirm = function(options) {
            return showModal({
                type: options.type || 'warning',
                title: options.title,
                message: options.text || options.message,
                showCancel: true,
                confirmText: options.confirmText || 'Confirmar',
                cancelText: options.cancelText || 'Cancelar',
                confirmClass: options.confirmClass || 'danger'
            });
        };

        window.showPrompt = function(options) {
            return showModal({
                type: 'info',
                title: options.title,
                message: options.text || '',
                showCancel: true,
                confirmText: options.confirmText || 'Confirmar',
                cancelText: options.cancelText || 'Cancelar',
                inputType: options.inputType || 'select',
                inputOptions: options.inputOptions,
                inputPlaceholder: options.inputPlaceholder
            });
        };

        // Show error detail (for grid click)
        window.showErrorDetail = function(title, message) {
            showModal({
                type: 'error',
                title,
                message: decodeURIComponent(message)
            });
        };
    </script>

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</body>

</html>