<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/sifen_debug.log');

// Configurar OpenSSL legacy para soportar algoritmos SHA256 en PHP 8.1+/OpenSSL 3.x
$opensslLegacy = __DIR__ . '/openssl-legacy.cnf';
if (file_exists($opensslLegacy)) {
    putenv('OPENSSL_CONF=' . $opensslLegacy);
}

// Use current dir for includes if we move it or absolute path
require __DIR__ . '/_lib/php-sifen3/src/php-sifen.php';

// --- FUNCIONES AUXILIARES ---

/**
 * Actualiza el estado de una NC en la base de datos local
 */
function updateNCStatus($cdc, $estado, $mensaje)
{
    if (!$cdc)
        return false;
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';
    $id_empresa = $_SESSION['id_empresa'] ?? 0;

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if ($dbName) {
            $sql = "UPDATE $dbName.de_nc SET est_res = :estado, msg_res = :msg, updated_at = NOW() WHERE id_sifen = :cdc";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':estado' => $estado, ':msg' => $mensaje, ':cdc' => $cdc]);
            return true;
        }
    } catch (Exception $e) {
        error_log("Error updating NC status: " . $e->getMessage());
    }
    return false;
}

/**
 * Inserta una nueva nota de crédito en la base de datos local
 */
function insertNotaCreditoLocal($payload, $sifenResponse, $conceptos)
{
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';
    $id_empresa = $_SESSION['id_empresa'] ?? (isset($payload['id_empresa']) ? (int) $payload['id_empresa'] : 0);

    if (!$id_empresa) {
        error_log("Error: id_empresa no definido en sesión ni payload");
        return false;
    }

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");

        $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtDB->execute([':id' => $id_empresa]);
        $dbName = $stmtDB->fetchColumn();

        if (!$dbName) {
            error_log("Error: No se encontró base de datos para empresa $id_empresa");
            return false;
        }

        $emisor = $payload['emisor'] ?? [];
        $receptor = $payload['receptor'] ?? [];
        $cdcAsociado = $payload['cdc'] ?? '';
        $ndoc = $payload['ndoc'] ?? '';

        // Verificar si ya existe una NC para este CDC asociado y ndoc (evitar duplicados)
        $stmtCheck = $pdo->prepare("SELECT id, est_res, id_sifen FROM $dbName.de_nc WHERE cdc_asociado = :cdc AND ndoc = :ndoc LIMIT 1");
        $stmtCheck->execute([':cdc' => $cdcAsociado, ':ndoc' => $ndoc]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Ya existe, actualizar en lugar de insertar
            $stmtUpdate = $pdo->prepare("UPDATE $dbName.de_nc SET 
                id_sifen = :id_sifen, fec_proc = :fec_proc, est_res = :est_res, 
                prot_aut = :prot_aut, cod_res = :cod_res, msg_res = :msg_res, 
                prot_cons_lote = :prot_cons, updated_at = NOW()
                WHERE id = :id");
            $stmtUpdate->execute([
                ':id_sifen' => $sifenResponse['id'] ?? $existing['id_sifen'],
                ':fec_proc' => $sifenResponse['fecha_proceso'] ?? null,
                ':est_res' => $sifenResponse['estado'] ?? $existing['est_res'],
                ':prot_aut' => $sifenResponse['protocolo_auth'] ?? null,
                ':cod_res' => $sifenResponse['codigo_resp'] ?? null,
                ':msg_res' => $sifenResponse['mensaje_resp'] ?? null,
                ':prot_cons' => $sifenResponse['protocolo_lote'] ?? null,
                ':id' => $existing['id']
            ]);
            error_log("NC_API: NC existente actualizada (ID: {$existing['id']})");
            return $existing['id'];
        }

        $sqlNC = "INSERT INTO $dbName.de_nc (
            ruc, dv, razon_social, tipo_contribuyente, ciudad, direccion, telefono, email, act_eco, act_eco_desc,
            receptor_documento, receptor_tipo_doc, receptor_razon_social, receptor_ciudad, receptor_direccion, receptor_telefono, receptor_email,
            ndoc, timbrado, fec_timbrado, cod_establecimiento, cod_expedicion, moneda, cambio, motivo, cdc_asociado,
            estado, id_sifen, fec_proc, est_res, prot_aut, cod_res, msg_res, prot_cons_lote, tpo_proces,
            created_at, updated_at
        ) VALUES (
            :ruc, :dv, :razon_social, :tipo_contribuyente, :ciudad, :direccion, :telefono, :email, :act_eco, :act_eco_desc,
            :rec_doc, :rec_tipo, :rec_razon, :rec_ciudad, :rec_dir, :rec_tel, :rec_email,
            :ndoc, :timbrado, :fec_timbrado, :cod_est, :cod_exp, :moneda, :cambio, :motivo, :cdc_asoc,
            :estado, :id_sifen, :fec_proc, :est_res, :prot_aut, :cod_res, :msg_res, :prot_cons, :tpo_pro,
            NOW(), NOW()
        )";

        $stmtNC = $pdo->prepare($sqlNC);
        $stmtNC->execute([
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
            ':motivo' => $payload['motivo'] ?? 2,
            ':cdc_asoc' => $payload['cdc'] ?? '',
            ':estado' => $sifenResponse['estado'] ?? 'Aprobado',
            ':id_sifen' => $sifenResponse['id'] ?? '',
            ':fec_proc' => $sifenResponse['fecha_proceso'] ?? date('Y-m-d H:i:s'),
            ':est_res' => $sifenResponse['estado'] ?? '',
            ':prot_aut' => $sifenResponse['protocolo_auth'] ?? '',
            ':cod_res' => $sifenResponse['codigo_resp'] ?? '',
            ':msg_res' => $sifenResponse['mensaje_resp'] ?? '',
            ':prot_cons' => $sifenResponse['protocolo_lote'] ?? '',
            ':tpo_pro' => $sifenResponse['tipo_proceso'] ?? ''
        ]);

        $idNC = $pdo->lastInsertId();
        $sqlItems = "INSERT INTO $dbName.de_nc_items (nota_credito_id, codigo, descripcion, precio, cantidad, tasa_iva, descuento, unidad_medida) VALUES (:id_nc, :codigo, :descripcion, :precio, :cantidad, :tasa_iva, :descuento, :unidad_medida)";
        $stmtItems = $pdo->prepare($sqlItems);
        foreach ($conceptos as $concepto) {
            $stmtItems->execute([
                ':id_nc' => $idNC,
                ':codigo' => $concepto['codigo'] ?? '',
                ':descripcion' => $concepto['descripcion'] ?? '',
                ':precio' => $concepto['precio'] ?? 0,
                ':cantidad' => $concepto['cantidad'] ?? 0,
                ':tasa_iva' => $concepto['tasa_iva'] ?? 0,
                ':descuento' => $concepto['descuento'] ?? 0,
                ':unidad_medida' => $concepto['unidad_medida'] ?? '77'
            ]);
        }
        return true;
    } catch (Exception $e) {
        error_log("Error insertando NC: " . $e->getMessage());
        return false;
    }
}

// --- PROCESAMIENTO DE INPUT ---

$inputJSON = file_get_contents('php://input');
if (empty($inputJSON) && php_sapi_name() === 'cli')
    $inputJSON = file_get_contents('php://stdin');
$input = json_decode($inputJSON, true) ?: [];
$payload = array_merge($_GET, $_POST, $input);

$get = static function (string $key, array $source, $default = null) {
    return array_key_exists($key, $source) ? $source[$key] : $default;
};

$cleanId = static function ($val) {
    return preg_replace('/\D+/', '', (string) $val);
};

$action = (string) $get('action', $payload, '');
$preview = $action === 'preview' || filter_var($get('preview', $payload, false), FILTER_VALIDATE_BOOL);
$cdcAsociado = preg_replace('/\D+/', '', (string) $get('cdc', $payload, ''));
$certPath = (string) $get('cert_path', $payload, '');
$certPass = (string) $get('cert_pass', $payload, '');
$modo = trim((string) $get('modo', $payload, 'test')) ?: 'test';
$ruc = preg_replace('/\D+/', '', (string) $get('ruc', $payload, ''));

// Obtener y validar timbrado
$timbradoInput = (string) $get('timbrado', $payload, '');
$fecTimbradoInput = (string) $get('fec_timbrado', $payload, '');

error_log("NC_API - Timbrado recibido: '$timbradoInput', Fecha: '$fecTimbradoInput', CDC: '$cdcAsociado'");

// Validación de parámetros
if ($certPath === '' || $certPass === '' || $cdcAsociado === '') {
    echo json_encode(['success' => false, 'message' => 'Faltan parámetros requeridos (cert_path, cert_pass, cdc)']);
    exit;
}
if (strlen($cdcAsociado) !== 44) {
    echo json_encode(['success' => false, 'message' => 'El CDC asociado debe tener exactamente 44 dígitos.']);
    exit;
}

// Validaciones específicas para emisión (no aplican para anular)
if ($action !== 'anular') {
    // Validar timbrado
    if (empty($timbradoInput) || $timbradoInput === '0') {
        echo json_encode(['success' => false, 'message' => 'El número de timbrado es requerido y no puede ser 0.']);
        exit;
    }
    if (strlen($timbradoInput) !== 8 || !ctype_digit($timbradoInput)) {
        echo json_encode(['success' => false, 'message' => "El timbrado debe tener exactamente 8 dígitos numéricos. Recibido: '$timbradoInput'"]);
        exit;
    }

    $motivo = (int) $get('motivo', $payload, 0);
    if ($motivo < 1 || $motivo > 8) {
        echo json_encode(['success' => false, 'message' => 'El motivo de emisión para NC debe estar entre 1 y 8.']);
        exit;
    }
}

// Debug: Log del cert_path recibido
error_log("NC_API - cert_path recibido: '$certPath', is_file: " . (is_file($certPath) ? 'true' : 'false'));

if (!is_file($certPath)) {
    $detail = file_exists($certPath)
        ? "El path existe pero es un directorio, no un archivo"
        : "El archivo no existe";
    echo json_encode(['success' => false, 'message' => "Error con certificado: $detail. Path: $certPath"]);
    exit;
}

// --- EJECUCIÓN ---

try {
    $key = new \sifen\KEY($certPath, $certPass);
    $sifen = new \sifen\Sifen($modo, $key);

    // Configurar CSC e IDC para generación del QR
    $csc = trim((string) $get('csc', $payload, ''));
    $idc = trim((string) $get('idc', $payload, '0001'));
    if (!empty($csc)) {
        $sifen->setCSC($csc);
    }
    if (!empty($idc)) {
        $sifen->setIdc($idc);
    }

    // ACCIÓN: ANULAR
    if ($action === 'anular') {
        $motivo = trim((string) $get('motivo_anulacion', $payload, 'Error de digitación'));
        $xmlRequest = $sifen->anular($cdcAsociado, $motivo);
        $respuesta = $sifen->enviarEvento($xmlRequest);

        if ($respuesta['status'] === 'ok' && !empty($respuesta['response'])) {
            $estRes = $sifen->getEstRes();
            $msgRes = $sifen->getMsgRes();
            $finalEstado = ($estRes === 'Aprobado') ? 'Anulado' : $estRes;
            updateNCStatus($cdcAsociado, $finalEstado, $msgRes);

            echo json_encode([
                'success' => true,
                'message' => 'Resultado SIFEN: ' . $msgRes,
                'data' => ['id' => $cleanId($sifen->getId()), 'estado' => $estRes, 'mensaje' => $msgRes]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error SIFEN: ' . ($respuesta['error'] ?? 'Sin respuesta del servidor.')]);
        }
        exit;
    }

    // ACCIÓN: EMITIR (Default)
    $emisorData = array_merge([
        'ruc' => $ruc ?: '80110747',
        'dv' => (string) $get('dv', $payload, '4'),
        'razon_social' => (string) $get('razon_social', $payload, 'EMISOR NO CONFIGURADO'),
        'tipo_contribuyente' => (string) $get('tipo_contribuyente', $payload, '2'),
        'ciudad' => (int) $get('ciudad', $payload, 1),
        'direccion' => (string) $get('direccion', $payload, '-'),
        'telefono' => (string) $get('telefono', $payload, '-'),
        'email' => (string) $get('email', $payload, '-'),
        'act_eco' => (string) $get('act_eco', $payload, '0'),
        'act_eco_desc' => (string) $get('act_eco_desc', $payload, '-'),
    ], (array) $get('emisor', $payload, []));

    $receptorData = array_merge([
        'documento' => (string) $get('receptor_documento', $payload, '0'),
        'tipo_doc' => (string) $get('receptor_tipo_doc', $payload, 'ci'),
        'razon_social' => (string) $get('receptor_razon_social', $payload, 'RECEPTOR NO CONFIGURADO'),
        'ciudad' => (int) $get('receptor_ciudad', $payload, 1),
        'direccion' => (string) $get('receptor_direccion', $payload, ''),
        'telefono' => '',
        'email' => (string) $get('receptor_email', $payload, ''),
    ], (array) $get('receptor', $payload, []));

    // Normalizar tipo_doc: convertir '2' a 'ruc' y '1' a 'ci'
    $tipoDocRaw = $receptorData['tipo_doc'];
    if ($tipoDocRaw === '2' || strtolower($tipoDocRaw) === 'ruc') {
        $receptorData['tipo_doc'] = 'ruc';
    } else {
        $receptorData['tipo_doc'] = 'ci';
    }

    // Limpiar teléfono del receptor (solo números y +, eliminar caracteres inválidos como "-")
    $receptorData['telefono'] = preg_replace('/[^0-9+]/', '', (string) ($receptorData['telefono'] ?? ''));

    $conceptosRaw = $get('conceptos', $payload, []);
    $conceptos = [];
    foreach ((is_array($conceptosRaw) ? $conceptosRaw : []) as $c) {
        if (is_array($c))
            $conceptos[] = new \sifen\Concepto($c);
    }
    if (!$conceptos) {
        echo json_encode(['success' => false, 'message' => 'No se recibieron conceptos válidos.']);
        exit;
    }

    $hoy = date('Y-m-d');
    $ndoc = (string) $get('ndoc', $payload, '') ?: (string) $get('nro_factura', $payload, '') ?: '0';

    // Obtener valores de timbrado
    $timbradoVal = (string) $get('timbrado', $payload, '0');
    $fecTimbradoVal = (string) $get('fec_timbrado', $payload, $hoy);

    // Formatear establecimiento y expedición a 3 dígitos (mínimo 001)
    $codEstRaw = trim((string) $get('cod_establecimiento', $payload, '001'));
    $codExpRaw = trim((string) $get('cod_expedicion', $payload, '001'));
    $codEstVal = str_pad($codEstRaw ?: '1', 3, '0', STR_PAD_LEFT);
    $codExpVal = str_pad($codExpRaw ?: '1', 3, '0', STR_PAD_LEFT);

    // Log para debug
    error_log("NC_API Debug - timbrado: $timbradoVal, fec_timbrado: $fecTimbradoVal, ndoc: $ndoc, cod_est: $codEstVal, cod_exp: $codExpVal");

    $factura = new \sifen\Factura(\sifen\DTE::NC, [
        'emisor' => new \sifen\Emisor($emisorData),
        'receptor' => new \sifen\Receptor($receptorData),
        'conceptos' => $conceptos,
        'ndoc' => $ndoc,
        'timbrado' => $timbradoVal,
        'fec_timbrado' => $fecTimbradoVal,
        'cod_establecimiento' => $codEstVal,
        'cod_expedicion' => $codExpVal,
        'moneda' => (string) $get('moneda', $payload, 'PYG'),
        'cambio' => (float) $get('cambio', $payload, 1),
        'motivo' => (int) $get('motivo', $payload, 1),
    ]);
    $factura->agregarAsociacion(['cdc' => $cdcAsociado]);

    $sifen->agregarFactura($factura);
    $xmlRequest = $sifen->buildXML(true); // true = Modo Lote (Asíncrono)

    // Obtener CDC generado de la factura (después de buildXML)
    $cdcGenerado = $factura->cdc ?? '';
    if ($cdcGenerado) {
        // Agregar dígito verificador al CDC
        $cdcGenerado = $cdcGenerado . \sifen\Sifen::getRuc($cdcGenerado, 'dv');
    }
    error_log("NC_API - CDC generado: $cdcGenerado");

    // Debug: guardar XML generado
    $xmlDebugStr = is_object($xmlRequest) && method_exists($xmlRequest, 'asXML') ? $xmlRequest->asXML() : (string) $xmlRequest;
    file_put_contents(__DIR__ . '/nc_xml_debug.xml', $xmlDebugStr);
    error_log("NC_API - XML generado guardado en nc_xml_debug.xml");

    if ($preview) {
        $xmlDebug = is_object($xmlRequest) && method_exists($xmlRequest, 'asXML') ? $xmlRequest->asXML() : (string) $xmlRequest;
        echo json_encode([
            'success' => true,
            'message' => 'Preview generado.',
            'data' => ['xml' => $xmlDebug]
        ]);
        exit;
    }

    $respuesta = $sifen->enviar($xmlRequest);

    if (isset($respuesta['status']) && $respuesta['status'] === 'ok' && !empty($respuesta['response'])) {
        $protConsLote = $sifen->getProtConsLote();
        $estadoFinal = 'Pendiente (Lote)';
        $mensajeFinal = 'Lote enviado, esperando procesamiento';

        if (!empty($protConsLote)) {
            // Intentar consultar el lote hasta 3 veces con espera incremental
            $maxRetries = 3;
            for ($retry = 1; $retry <= $maxRetries; $retry++) {
                sleep($retry * 2); // 2s, 4s, 6s
                $resLote = $sifen->queryLote($protConsLote);

                if (!empty($resLote['response'])) {
                    // Log respuesta completa para debug
                    file_put_contents(__DIR__ . '/nc_sifen_response.xml', $resLote['response']);
                    error_log("NC_API - Respuesta SIFEN guardada en nc_sifen_response.xml (intento $retry)");

                    $dom = new DOMDocument();
                    @$dom->loadXML($resLote['response']);

                    // Verificar si sigue en procesamiento
                    $nodesCod = $dom->getElementsByTagNameNS('*', 'dCodResLot');
                    if ($nodesCod->length > 0 && $nodesCod->item(0)->nodeValue === '0361') {
                        // Aún en procesamiento, continuar esperando
                        error_log("NC_API - Lote aún en procesamiento (intento $retry/$maxRetries)");
                        continue;
                    }

                    // Usar búsqueda recursiva sin namespaces para mayor robustez
                    $nodesEst = $dom->getElementsByTagNameNS('*', 'dEstRes');
                    if ($nodesEst->length > 0) {
                        $estadoFinal = $nodesEst->item(0)->nodeValue;
                    }

                    $nodesMsg = $dom->getElementsByTagNameNS('*', 'dMsgRes');
                    if ($nodesMsg->length > 0) {
                        $mensajeFinal = $nodesMsg->item(0)->nodeValue;
                    }

                    // Capturar código de error específico
                    $nodesCodRes = $dom->getElementsByTagNameNS('*', 'dCodRes');
                    $codigoError = ($nodesCodRes->length > 0) ? $nodesCodRes->item(0)->nodeValue : '';

                    // Mensajes descriptivos para errores comunes de SIFEN
                    if ($codigoError === '1101') {
                        $mensajeFinal = "<strong>Error $codigoError</strong><br><br>" .
                            "Timbrado <strong>$timbradoEnviar</strong> no está autorizado para emitir <strong>Nota de Crédito</strong>.<br><br>" .
                            "<em>Solución:</em> Ingrese al portal SIFEN y habilite el tipo de documento 'Nota de Crédito Electrónica' para este timbrado.";
                    } elseif ($codigoError === '1102') {
                        $mensajeFinal = "<strong>Error $codigoError</strong><br><br>" .
                            "Timbrado <strong>$timbradoEnviar</strong> está fuera de vigencia.<br><br>" .
                            "<em>Solución:</em> Verifique las fechas de vigencia en el portal SIFEN.";
                    } elseif ($codigoError === '1103') {
                        $mensajeFinal = "<strong>Error $codigoError</strong><br><br>" .
                            "Establecimiento o punto de expedición no habilitado.<br><br>" .
                            "<em>Solución:</em> Verifique la configuración en el portal SIFEN.";
                    }

                    $nodesProt = $dom->getElementsByTagNameNS('*', 'dProtAut');
                    if ($nodesProt->length > 0) {
                        $protocoloAuth = $nodesProt->item(0)->nodeValue;
                    }

                    // Si obtuvimos un estado final, salir del loop
                    if ($estadoFinal !== 'Pendiente (Lote)') {
                        break;
                    }
                }
            }
        }

        // Usar CDC generado de la factura como fallback si SIFEN no devuelve Id
        $idFromSifen = $cleanId($sifen->getId());
        $idFinal = !empty($idFromSifen) ? $idFromSifen : $cdcGenerado;

        $responseData = [
            'id' => $idFinal,
            'fecha_proceso' => $sifen->getFecProc(),
            'estado' => $estadoFinal,
            'protocolo_auth' => $protocoloAuth ?? $sifen->getProtAut(),
            'codigo_resp' => $sifen->getCodRes(),
            'mensaje_resp' => $mensajeFinal,
            'protocolo_lote' => $protConsLote,
            'tipo_proceso' => $sifen->getTpoProces(),
            'qr_url' => method_exists($sifen, 'getQrUrl') ? $sifen->getQrUrl() : null
        ];

        $dbInserted = insertNotaCreditoLocal($payload, $responseData, $conceptosRaw);
        echo json_encode([
            'success' => true,
            'message' => 'NC procesada' . ($dbInserted ? ' y guardada.' : '.'),
            'data' => array_merge($responseData, ['db_inserted' => $dbInserted])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $respuesta['error'] ?? 'Error desconocido al comunicar con SIFEN',
            'data' => $respuesta
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
