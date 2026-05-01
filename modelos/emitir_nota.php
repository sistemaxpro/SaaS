<?php

/**
 * Emitir Nota Electrónica (NC/ND) - Frontend Controller
 * Recibe solicitud desde el grid y envía al endpoint SIFEN correspondiente
 * 
 * Método: POST JSON
 * Parámetros:
 *   - tipo_nota: 'NC' o 'ND'
 *   - id_factura: ID de la factura original
 *   - cdc: CDC de la factura original
 *   - motivo: Código de motivo (1-10)
 *   - conceptos: Array de items [{codigo, descripcion, precio, cantidad, tasa_iva}]
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Recibir datos
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (empty($input)) $input = $_POST;
if (empty($input)) $input = $_GET;

// Obtener parámetros
$tipoNota = strtoupper(trim($input['tipo_nota'] ?? 'NC'));
$id_factura = (int)($input['id_factura'] ?? 0);
$cdc = trim($input['cdc'] ?? '');
$motivo = (int)($input['motivo'] ?? 1);
$conceptos = $input['conceptos'] ?? [];
$preview = filter_var($input['preview'] ?? false, FILTER_VALIDATE_BOOL);

// Validar
if (!in_array($tipoNota, ['NC', 'ND'])) {
    echo json_encode(['success' => false, 'message' => 'Tipo de nota inválido. Usar NC o ND']);
    exit;
}

if (empty($cdc) || strlen($cdc) !== 44) {
    echo json_encode(['success' => false, 'message' => 'CDC inválido (debe tener 44 dígitos)']);
    exit;
}

if (empty($conceptos) || !is_array($conceptos)) {
    echo json_encode(['success' => false, 'message' => 'Debe incluir al menos un concepto/item']);
    exit;
}

// Conexión a BD
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';
$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Obtener datos de la empresa
    $stmtEmpresa = $pdo->prepare("
        SELECT e.*, dbase, cert_path, cert_pass, cert_nombre,
               ruc, dv, empresa, direccion, telefono, email,
               actividad_economica, ciudad, tipoContribuyente
        FROM empresa e
        WHERE e.id_empresa = :id
    ");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception("Empresa no encontrada: $id_empresa");
    }

    $dbName = $empresa['dbase'];

    // Obtener datos de la factura original
    $stmtFactura = $pdo->prepare("
        SELECT 
            fv.*,
            c.nombre AS cliente_nombre,
            c.numero AS cliente_ruc,
            c.direccion AS cliente_direccion,
            c.telefono AS cliente_telefono,
            c.email AS cliente_email,
            c.ciudad AS cliente_ciudad
        FROM $dbName.factura_ventas fv
        LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
        WHERE fv.id_factura = :id OR fv.cdc = :cdc
        LIMIT 1
    ");
    $stmtFactura->execute([':id' => $id_factura, ':cdc' => $cdc]);
    $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        throw new Exception("Factura no encontrada: $id_factura");
    }

    // Obtener ruta del certificado
    $certPath = $empresa['cert_path'] ?? '';
    $certPass = $empresa['cert_pass'] ?? '';

    if (empty($certPath) || !file_exists($certPath)) {
        $posiblesCerts = [
            __DIR__ . "/_lib/php-sifen3/certificados/{$empresa['ruc']}.p12",
            __DIR__ . "/_lib/php-sifen3/noenviar/{$empresa['ruc']}.p12",
            __DIR__ . "/_lib/certificados/{$empresa['ruc']}.p12",
        ];
        foreach ($posiblesCerts as $path) {
            if (file_exists($path)) {
                $certPath = $path;
                break;
            }
        }
    }

    if (empty($certPath) || !file_exists($certPath)) {
        throw new Exception("Certificado no encontrado para la empresa");
    }

    if (empty($certPass)) {
        throw new Exception("Contraseña del certificado no configurada");
    }

    // Determinar tipo de documento del receptor
    // Parsing robusto del RUC/DV del cliente
    $fullRucRec = $factura['cliente_ruc'] ?? $factura['ruc'] ?? '';
    $rec_parts = explode('-', $fullRucRec);
    $rec_doc = preg_replace('/\D/', '', $rec_parts[0]);
    $rec_dv = isset($rec_parts[1]) ? preg_replace('/\D/', '', $rec_parts[1]) : '';

    $tipoDoc = (strpos($fullRucRec, '-') !== false || strlen($rec_doc) > 7) ? 'ruc' : 'ci';

    // Obtener siguiente número correlativo para la nota (en la base de datos de la empresa)
    $stmtSeq = $pdo->prepare("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM $dbName.de_nc");
    $stmtSeq->execute();
    $nextId = $stmtSeq->fetch(PDO::FETCH_ASSOC)['next_id'] ?? 1;
    $ndoc_seq = str_pad($nextId, 7, '0', STR_PAD_LEFT);

    // Limpiar establecimiento y punto de expedición (deben ser 3 dígitos)
    $cod_est = str_pad(preg_replace('/\D/', '', $factura['establecimiento'] ?? '001'), 3, '0', STR_PAD_LEFT);
    $cod_exp = str_pad(preg_replace('/\D/', '', $factura['punto_expedicion'] ?? '001'), 3, '0', STR_PAD_LEFT);

    // Parsing robusto del RUC/DV del emisor
    $fullRucEmi = $empresa['ruc'] ?? '';
    $emi_parts = explode('-', $fullRucEmi);
    $emi_ruc = preg_replace('/\D/', '', $emi_parts[0]);
    $emi_dv = $empresa['dv'] ?? (isset($emi_parts[1]) ? preg_replace('/\D/', '', $emi_parts[1]) : '');

    // Construir payload para el endpoint SIFEN
    $payload = [
        'action' => $preview ? 'preview' : 'send',
        'cdc' => $cdc,
        'cert_path' => $certPath,
        'cert_pass' => $certPass,
        'modo' => ($empresa['ambiente_sifen'] == '1') ? 'prod' : 'test',
        'ruc' => $emi_ruc,
        'dv' => $emi_dv,
        'id_empresa' => $id_empresa,
        'csc' => $empresa['csc'] ?? 'a911929881f35219c7610A4f8F9F97D5',
        'id_csc' => $empresa['id_csc'] ?? '1',

        // Emisor
        'emisor' => [
            'ruc' => $emi_ruc,
            'dv' => $emi_dv,
            'razon_social' => $empresa['empresa'] ?? '',
            'tipo_contribuyente' => $empresa['tipoContribuyente'] ?? '2',
            'ciudad' => (int)($empresa['ciudad_id'] ?? $empresa['ciudad'] ?? 1),
            'direccion' => $empresa['direccion'] ?? '-',
            'telefono' => $empresa['telefono'] ?? '-',
            'email' => $empresa['email'] ?? '-',
            'act_eco' => $empresa['cod_act'] ?? $empresa['actividad_economica'] ?? '0',
            'act_eco_desc' => $empresa['des_act'] ?? 'Comercio al por menor',
        ],

        // Receptor (cliente de la factura original)
        'receptor' => [
            'documento' => $rec_doc,
            'dv' => $rec_dv,
            'tipo_doc' => $tipoDoc,
            'razon_social' => $factura['cliente_nombre'] ?? 'Sin Nombre',
            'ciudad' => (int)($factura['cliente_ciudad'] ?? 1),
            'direccion' => $factura['cliente_direccion'] ?? '-',
            'telefono' => $factura['cliente_telefono'] ?? '-',
            'email' => $factura['cliente_email'] ?? '-',
        ],

        // Datos del documento (La Nota de Crédito tiene su propia numeración)
        'ndoc' => $ndoc_seq,
        'timbrado' => $factura['timbrado'] ?? $empresa['timbrado'] ?? '',
        'fec_timbrado' => $factura['fecha_timbrado'] ?? $empresa['vigencia_ini'] ?? date('Y-m-d'),
        'cod_establecimiento' => $cod_est,
        'cod_expedicion' => $cod_exp,
        'moneda' => 'PYG',
        'cambio' => 1,
        'motivo' => $motivo,

        // Conceptos
        'conceptos' => $conceptos
    ];

    // Determinar endpoint según tipo de nota
    $endpoint = $tipoNota === 'NC' ? 'nc.php' : 'nd.php';
    $endpointUrl = 'http://127.0.0.1' . dirname($_SERVER['PHP_SELF']) . '/_lib/php-sifen3/' . $endpoint;

    // Llamar al endpoint
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpointUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Error de conexión: $curlError");
    }

    $result = json_decode($response, true);

    if (!$result) {
        if (strpos($response, 'SOAP-ERROR') !== false) {
            throw new Exception("Error de SIFEN: El servidor no está disponible. Intente más tarde.");
        }
        throw new Exception("Respuesta inválida del servidor: " . substr($response, 0, 300));
    }

    // Si es NC y fue exitoso, guardar en BD (ND ya lo hace en su endpoint)
    if ($result['success'] && $tipoNota === 'NC' && !$preview) {
        saveNotaCreditoLocal($pdo, $dbName, $payload, $result['data'] ?? [], $conceptos, $id_factura);
    }

    // Log
    $logFile = __DIR__ . '/logs/notas_' . date('Y-m') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $logEntry = date('Y-m-d H:i:s') . " | $tipoNota | Empresa: $id_empresa | CDC: $cdc | " . ($result['success'] ? 'OK' : 'ERROR: ' . ($result['message'] ?? 'unknown')) . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null
    ]);
}

/**
 * Guardar Nota de Crédito en BD local
 */
function saveNotaCreditoLocal($pdo, $dbName, $payload, $sifenResponse, $conceptos, $id_factura)
{
    try {
        $emisor = $payload['emisor'] ?? [];
        $receptor = $payload['receptor'] ?? [];

        $sql = "INSERT INTO $dbName.de_nc (
            id_factura_origen, cdc_asociado,
            ruc, dv, razon_social, tipo_contribuyente, ciudad, direccion, telefono, email, act_eco, act_eco_desc,
            receptor_documento, receptor_tipo_doc, receptor_razon_social, receptor_ciudad, receptor_direccion, receptor_telefono, receptor_email,
            ndoc, timbrado, fec_timbrado, cod_establecimiento, cod_expedicion, moneda, cambio, motivo,
            estado, id_sifen, fec_proc, est_res, prot_aut, cod_res, msg_res,
            created_at, updated_at
        ) VALUES (
            :id_factura, :cdc_asoc,
            :ruc, :dv, :razon_social, :tipo_contribuyente, :ciudad, :direccion, :telefono, :email, :act_eco, :act_eco_desc,
            :rec_doc, :rec_tipo, :rec_razon, :rec_ciudad, :rec_dir, :rec_tel, :rec_email,
            :ndoc, :timbrado, :fec_timbrado, :cod_est, :cod_exp, :moneda, :cambio, :motivo,
            :estado, :id_sifen, :fec_proc, :est_res, :prot_aut, :cod_res, :msg_res,
            NOW(), NOW()
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_factura' => $id_factura,
            ':cdc_asoc' => $payload['cdc'] ?? '',
            ':ruc' => $emisor['ruc'] ?? '',
            ':dv' => $emisor['dv'] ?? '',
            ':razon_social' => $emisor['razon_social'] ?? '',
            ':tipo_contribuyente' => $emisor['tipo_contribuyente'] ?? '2',
            ':ciudad' => $emisor['ciudad'] ?? 1,
            ':direccion' => $emisor['direccion'] ?? '-',
            ':telefono' => $emisor['telefono'] ?? '-',
            ':email' => $emisor['email'] ?? '-',
            ':act_eco' => $emisor['act_eco'] ?? '0',
            ':act_eco_desc' => $emisor['act_eco_desc'] ?? '-',
            ':rec_doc' => $receptor['documento'] ?? '',
            ':rec_tipo' => $receptor['tipo_doc'] ?? 'ci',
            ':rec_razon' => $receptor['razon_social'] ?? '',
            ':rec_ciudad' => $receptor['ciudad'] ?? 1,
            ':rec_dir' => $receptor['direccion'] ?? '-',
            ':rec_tel' => $receptor['telefono'] ?? '-',
            ':rec_email' => $receptor['email'] ?? '-',
            ':ndoc' => $payload['ndoc'] ?? '',
            ':timbrado' => $payload['timbrado'] ?? '',
            ':fec_timbrado' => $payload['fec_timbrado'] ?? date('Y-m-d'),
            ':cod_est' => $payload['cod_establecimiento'] ?? '001',
            ':cod_exp' => $payload['cod_expedicion'] ?? '001',
            ':moneda' => $payload['moneda'] ?? 'PYG',
            ':cambio' => $payload['cambio'] ?? 1,
            ':motivo' => $payload['motivo'] ?? 1,
            ':estado' => $sifenResponse['estado'] ?? 'Aprobado',
            ':id_sifen' => $sifenResponse['id'] ?? '',
            ':fec_proc' => $sifenResponse['fecha_proceso'] ?? date('Y-m-d H:i:s'),
            ':est_res' => $sifenResponse['estado'] ?? '',
            ':prot_aut' => $sifenResponse['protocolo_auth'] ?? '',
            ':cod_res' => $sifenResponse['codigo_resp'] ?? '',
            ':msg_res' => $sifenResponse['mensaje_resp'] ?? ''
        ]);

        $idNC = $pdo->lastInsertId();

        // Guardar items
        if ($idNC && !empty($conceptos)) {
            $sqlItems = "INSERT INTO $dbName.de_nc_items (nota_credito_id, codigo, descripcion, precio, cantidad, tasa_iva, descuento, unidad_medida) 
                         VALUES (:id_nc, :codigo, :descripcion, :precio, :cantidad, :tasa_iva, :descuento, :unidad_medida)";
            $stmtItems = $pdo->prepare($sqlItems);

            foreach ($conceptos as $c) {
                $stmtItems->execute([
                    ':id_nc' => $idNC,
                    ':codigo' => $c['codigo'] ?? '',
                    ':descripcion' => $c['descripcion'] ?? '',
                    ':precio' => $c['precio'] ?? 0,
                    ':cantidad' => $c['cantidad'] ?? 0,
                    ':tasa_iva' => $c['tasa_iva'] ?? 10,
                    ':descuento' => $c['descuento'] ?? 0,
                    ':unidad_medida' => $c['unidad_medida'] ?? '77'
                ]);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Error guardando NC: " . $e->getMessage());
        return false;
    }
}
