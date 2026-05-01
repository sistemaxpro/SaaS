<?php

/**
 * API para envío de Notas de Remisión a SIFEN Paraguay
 * Endpoints: enviar, consultar, anular
 */

// Configurar OpenSSL para soportar certificados legacy (SHA256/RC2)
// Necesario para certificados .p12 antiguos en OpenSSL 3.x
$legacyCnf = __DIR__ . '/openssl-legacy.cnf';
if (file_exists($legacyCnf)) {
    putenv('OPENSSL_CONF=' . $legacyCnf);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Solo enviar headers si se llama directamente
if (basename($_SERVER['SCRIPT_FILENAME']) === 'nr_sifen_api.php') {
    header('Content-Type: application/json; charset=utf-8');

    // Configuración DB - solo cuando se llama directamente
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    $action = $_GET['action'] ?? '';
    $id = $_GET['id'] ?? '';
    $id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $pdo->exec("SET NAMES utf8");
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
        exit;
    }
}

// Obtener datos de empresa para SIFEN
function getEmpresaConfig($pdo, $masterDb, $id_empresa)
{
    $stmt = $pdo->prepare("SELECT * FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception("Empresa no encontrada");
    }

    // Buscar configuración en habilitacion_sifen (PRIORIDAD ABSOLUTA)
    $habilitacion = null;
    $stmtHab = $pdo->prepare("SELECT * FROM $masterDb.habilitacion_sifen WHERE id_empresa = :id AND activo = 1 LIMIT 1");
    $stmtHab->execute([':id' => $id_empresa]);
    $habilitacion = $stmtHab->fetch(PDO::FETCH_ASSOC);

    if (!$habilitacion) {
        throw new Exception("No se encontró configuración SIFEN activa para esta empresa. Configure en habilitacion_sifen.");
    }

    // Mapear ambiente de habilitacion_sifen (PROD/TEST) a código numérico (1/2)
    $ambienteRaw = $habilitacion['ambiente'] ?? 'TEST';
    $ambienteCodigo = (strtoupper($ambienteRaw) === 'PROD' || $ambienteRaw === '1') ? '1' : '2';

    // Obtener actividad económica principal desde habilitacion_sifen_actividades
    $stmtAct = $pdo->prepare("SELECT a.codigo, a.descripcion 
        FROM $masterDb.habilitacion_sifen_actividades a
        INNER JOIN $masterDb.habilitacion_sifen h ON a.id_habilitacion = h.id
        WHERE h.id_empresa = :id_empresa AND h.activo = 1 AND a.principal = 1 
        LIMIT 1");
    $stmtAct->execute([':id_empresa' => $id_empresa]);
    $actividad = $stmtAct->fetch(PDO::FETCH_ASSOC);

    // Determinar establecimiento y punto de expedición desde habilitacion_sifen_documentos (según empresa/sucursal)
    $establecimientoConfig = '001';
    $puntoConfig = '001';
    $idSucursal = $_SESSION['id_sucursal'] ?? null;

    try {
        // Si hay sucursal, intentar obtener establecimiento/punto por sucursal
        if (!empty($idSucursal)) {
            $stmtSuc = $pdo->prepare("SELECT codigo_establecimiento, punto_expedicion_defecto
                FROM $masterDb.habilitacion_sifen_sucursales
                WHERE id_habilitacion = :id_hab AND id_sucursal = :id_suc AND activo = 1
                LIMIT 1");
            $stmtSuc->execute([
                ':id_hab' => $habilitacion['id'],
                ':id_suc' => $idSucursal
            ]);
            $sucursalConf = $stmtSuc->fetch(PDO::FETCH_ASSOC);
            if ($sucursalConf) {
                $establecimientoConfig = str_pad($sucursalConf['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                $puntoConfig = str_pad($sucursalConf['punto_expedicion_defecto'], 3, '0', STR_PAD_LEFT);
            }
        }

        // Obtener documentos habilitados (tipo_documento = 7) para NR
        $stmtDoc = $pdo->prepare("SELECT codigo_establecimiento, punto_expedicion
            FROM $masterDb.habilitacion_sifen_documentos
            WHERE id_habilitacion = :id_hab AND tipo_documento = 7 AND activo = 1
            ORDER BY codigo_establecimiento, punto_expedicion");
        $stmtDoc->execute([':id_hab' => $habilitacion['id']]);
        $docs = $stmtDoc->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($docs)) {
            // Si ya hay establecimiento por sucursal, buscar un punto válido de documentos
            if (!empty($establecimientoConfig)) {
                $docMatch = null;
                foreach ($docs as $doc) {
                    $docEst = str_pad($doc['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                    if ($docEst === $establecimientoConfig) {
                        $docMatch = $doc;
                        break;
                    }
                }
                if ($docMatch) {
                    $puntoConfig = str_pad($docMatch['punto_expedicion'], 3, '0', STR_PAD_LEFT);
                }
            }

            // Si no se resolvió, tomar el primer documento habilitado
            if (empty($establecimientoConfig) || $establecimientoConfig === '001') {
                $establecimientoConfig = str_pad($docs[0]['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                $puntoConfig = str_pad($docs[0]['punto_expedicion'], 3, '0', STR_PAD_LEFT);
            }
        }
    } catch (Exception $e) {
        // Si falla, se quedan los valores por defecto
        error_log('No se pudo resolver establecimiento/punto: ' . $e->getMessage());
    }

    // Construir config EXCLUSIVAMENTE desde habilitacion_sifen
    $config = [
        'id_empresa' => $empresa['id_empresa'],
        'eruc' => $habilitacion['ruc'],
        'edv' => $habilitacion['dv'],
        'erazon_social' => $habilitacion['razon_social'],
        'ambiente_sifen' => $ambienteCodigo,
        'cert_pass' => $habilitacion['cert_pass'] ?? '',
        'cert_nombre' => $habilitacion['cert_nombre'] ?? '',
        'cert_path' => $habilitacion['cert_path'] ?? __DIR__ . '/_lib/php-sifen3-custom/certificados',
        'csc' => $habilitacion['csc'] ?? '',
        'id_csc' => $habilitacion['id_csc'] ?? '1',
        // En ambiente TEST usar timbrado genérico, en PROD usar el real
        'timbrado' => ($ambienteCodigo === '2') ? '12345678' : ($habilitacion['numero_timbrado'] ?? ''),
        'fecha_timbrado' => ($ambienteCodigo === '2') ? '2020-01-01' : ($habilitacion['fecha_inicio_vigencia'] ?? date('Y-m-d')),
        'cod_act' => $actividad['codigo'] ?? '',
        'des_act' => $actividad['descripcion'] ?? '',
        'ciudad_id' => $habilitacion['cod_ciudad'] ?? 1,
        'departamento' => $habilitacion['cod_depto'] ?? 1,
        'distrito' => $habilitacion['cod_distrito'] ?? 1,
        'direccion' => $habilitacion['direccion'] ?? $empresa['direccion'] ?? '',
        'telefono' => $habilitacion['telefono'] ?? $empresa['telefono'] ?? '',
        'email' => $habilitacion['email'] ?? $empresa['email'] ?? '',
        'establecimiento' => $establecimientoConfig,
        'punto_expedicion' => $puntoConfig
    ];

    return ['empresa' => $empresa, 'config' => $config, 'habilitacion' => $habilitacion];
}

// Obtener nota de remisión con items
function getRemision($pdo, $masterDb, $empresaDb, $id)
{
    $sql = "SELECT nr.*, rm.descripcion as motivo_descripcion 
        FROM $empresaDb.nota_remision nr
        LEFT JOIN $masterDb.remisiones_motivos rm ON nr.motivo_remision = rm.codigo
        WHERE nr.id_remision = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $nr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nr) return null;

    // Items
    $stmt = $pdo->prepare("SELECT * FROM $empresaDb.nota_remision_items WHERE id_remision = :id ORDER BY id_item");
    $stmt->execute([':id' => $id]);
    $nr['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $nr;
}

// Actualizar estado SIFEN
function updateEstadoSifen($pdo, $empresaDb, $id, $estado, $cdc = null, $response = null, $xml_respuesta = null)
{
    $sql = "UPDATE $empresaDb.nota_remision SET estado_sifen = :estado, updated_at = NOW()";
    $params = [':id' => $id, ':estado' => $estado];

    if ($cdc) {
        $sql .= ", cdc = :cdc";
        $params[':cdc'] = $cdc;
    }
    if ($response) {
        $sql .= ", mensaje_sifen = :response";
        $params[':response'] = $response;
    }
    if ($xml_respuesta) {
        $sql .= ", xml_respuesta = :xml_respuesta";
        $params[':xml_respuesta'] = $xml_respuesta;
    }

    $sql .= " WHERE id_remision = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * Limpiar campos SIFEN cuando se edita una remisión
 * Se ejecuta cuando cambia nro_documento, establecimiento o punto_expedicion
 */
function limpiarCamposSifen($pdo, $empresaDb, $id)
{
    $sql = "UPDATE $empresaDb.nota_remision SET
        xml_firmado = NULL,
        xml_respuesta = NULL,
        mensaje_sifen = NULL,
        cdc_asociado = NULL,
        timbrado_asociado = NULL,
        estado_sifen = 'Pendiente',
        cdc = NULL,
        updated_at = NOW()
        WHERE id_remision = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
}

/**
 * Obtener establecimiento y punto de expedición por sucursal
 */
function getEstablecimientoByRemision($pdo, $masterDb, $habilitacion, $nr)
{
    $result = [
        'establecimiento' => '001',
        'punto_expedicion' => '001'
    ];

    // Si la nota de remisión tiene id_sucursal, obtener desde habilitacion_sifen_sucursales
    if (!empty($nr['id_sucursal'])) {
        $stmtSuc = $pdo->prepare("SELECT codigo_establecimiento, punto_expedicion_defecto 
            FROM $masterDb.habilitacion_sifen_sucursales 
            WHERE id_habilitacion = :id_hab AND id_sucursal = :id_suc AND activo = 1");

        try {
            $stmtSuc->execute([':id_hab' => $habilitacion['id'], ':id_suc' => $nr['id_sucursal']]);
            $sucursal = $stmtSuc->fetch(PDO::FETCH_ASSOC);

            if ($sucursal) {
                $result['establecimiento'] = str_pad($sucursal['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                $result['punto_expedicion'] = str_pad($sucursal['punto_expedicion_defecto'], 3, '0', STR_PAD_LEFT);
            }
        } catch (Exception $e) {
            // Si la tabla no existe aún, usar valores por defecto
            error_log("Advertencia: No se pudo obtener establecimiento de sucursal: " . $e->getMessage());
        }
    }

    return $result;
}

// Solo ejecutar el switch si se llama directamente, no cuando es incluido
if (basename($_SERVER['SCRIPT_FILENAME']) === 'nr_sifen_api.php') {
    switch ($action) {
        case 'enviar':
            enviarSifen($pdo, $masterDb, $id, $id_empresa);
            break;
        case 'consultar':
            consultarSifen($pdo, $masterDb, $id, $id_empresa);
            break;
        case 'anular':
            anularSifen($pdo, $masterDb, $id, $id_empresa);
            break;
        case 'anular_local':
            anularLocal($pdo, $masterDb, $id, $id_empresa);
            break;
        case 'generar_xml':
            generarXML($pdo, $masterDb, $id, $id_empresa);
            break;
        case 'getEstablecimientos':
            getEstablecimientos($pdo, $masterDb, $id_empresa);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
}

/**
 * Obtener establecimientos habilitados para NR de una empresa
 */
function getEstablecimientos($pdo, $masterDb, $id_empresa)
{
    try {
        // Buscar habilitación activa de la empresa
        $stmtHab = $pdo->prepare("SELECT id FROM $masterDb.habilitacion_sifen WHERE id_empresa = :id AND activo = 1 LIMIT 1");
        $stmtHab->execute([':id' => $id_empresa]);
        $habilitacion = $stmtHab->fetch(PDO::FETCH_ASSOC);

        $establecimientos = [];

        if ($habilitacion) {
            // Obtener establecimientos para NR (tipo_documento = 7)
            $stmtDoc = $pdo->prepare("SELECT DISTINCT codigo_establecimiento, punto_expedicion 
                FROM $masterDb.habilitacion_sifen_documentos 
                WHERE id_habilitacion = :id AND tipo_documento = 7 AND activo = 1
                ORDER BY codigo_establecimiento, punto_expedicion");
            $stmtDoc->execute([':id' => $habilitacion['id']]);
            $establecimientos = $stmtDoc->fetchAll(PDO::FETCH_ASSOC);

            // Formatear con padding
            foreach ($establecimientos as &$est) {
                $est['codigo_establecimiento'] = str_pad($est['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                $est['punto_expedicion'] = str_pad($est['punto_expedicion'], 3, '0', STR_PAD_LEFT);
                $est['label'] = $est['codigo_establecimiento'] . '-' . $est['punto_expedicion'];
            }
        }

        // Si no hay establecimientos, agregar default
        if (empty($establecimientos)) {
            $establecimientos[] = [
                'codigo_establecimiento' => '001',
                'punto_expedicion' => '001',
                'label' => '001-001'
            ];
        }

        echo json_encode(['success' => true, 'data' => $establecimientos]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Enviar Nota de Remisión a SIFEN
 */
function enviarSifen($pdo, $masterDb, $id, $id_empresa)
{
    try {
        // Obtener configuración de empresa primero (para obtener dbase)
        $configData = getEmpresaConfig($pdo, $masterDb, $id_empresa);
        $empresa = $configData['empresa'];
        $config = $configData['config'];
        $habilitacion = $configData['habilitacion'] ?? null;

        // Determinar la base de datos de la empresa
        $empresaDb = $empresa['dbase'] ?? "smx_{$id_empresa}";

        // Obtener datos de NR
        $nr = getRemision($pdo, $masterDb, $empresaDb, $id);
        if (!$nr) {
            throw new Exception("Nota de Remisión no encontrada");
        }

        if ($nr['estado_sifen'] === 'Aprobado') {
            throw new Exception("Esta Nota de Remisión ya fue aprobada por SIFEN");
        }

        if (!$config) {
            throw new Exception("Configuración SIFEN no encontrada para esta empresa");
        }

        // Verificar certificado desde habilitacion_sifen (REQUERIDO)
        $ruc = $config['eruc'];
        $certNombre = $config['cert_nombre'];
        $certBasePath = $config['cert_path'];

        if (empty($certNombre)) {
            throw new Exception("cert_nombre no configurado en habilitacion_sifen");
        }

        // Construir ruta completa del certificado
        $certPath = rtrim($certBasePath, '/') . '/' . $certNombre;

        if (!file_exists($certPath)) {
            throw new Exception("Certificado no encontrado: {$certPath}. Verifique cert_path y cert_nombre en habilitacion_sifen.");
        }

        $certPassword = $config['cert_pass'];
        if (empty($certPassword)) {
            throw new Exception("Clave del certificado digital no configurada en habilitacion_sifen");
        }

        // Cargar librería SIFEN custom (compatible con OpenSSL 3.x)
        require_once __DIR__ . '/_lib/php-sifen3-custom/src/php-sifen.php';

        // Determinar ambiente (1 = producción, 2 = test)
        $modo = $config['ambiente_sifen'] == 1 ? 'prod' : 'test';

        // Construir emisor desde habilitacion_sifen
        $emisorData = [
            'ruc' => $config['eruc'],
            'dv' => $config['edv'],
            'razon_social' => $config['erazon_social'],
            'tipo_contribuyente' => '2', // Persona jurídica
            'ciudad' => (int)$config['ciudad_id'],
            'direccion' => $config['direccion'],
            'telefono' => $config['telefono'],
            'email' => $config['email'],
            'act_eco' => $config['cod_act'],
            'act_eco_desc' => $config['des_act']
        ];

        // Construir receptor
        $receptorData = [
            'documento' => $nr['receptor_ruc'],
            'tipo_doc' => strlen($nr['receptor_ruc']) > 7 ? 'ruc' : 'ci',
            'razon_social' => $nr['receptor_nombre'],
            'ciudad' => (int)($nr['receptor_ciudad'] ?? 1),
            'direccion' => $nr['receptor_direccion'] ?? '',
            'telefono' => $nr['receptor_telefono'] ?? '',
            'email' => $nr['receptor_email'] ?? ''
        ];

        // Construir conceptos
        $conceptos = [];
        foreach ($nr['items'] as $item) {
            $conceptos[] = new \sifen\Concepto([
                'codigo' => $item['codigo'] ?? 'MERC',
                'descripcion' => $item['descripcion'],
                'precio' => 0,
                'cantidad' => (float)$item['cantidad'],
                'tasa_iva' => 0,
                'descuento' => 0,
                'unidad_medida' => $item['unidad_medida'] ?? '77'
            ]);
        }

        // Construir conductor
        $conductorData = [
            'documento' => $nr['conductor_documento'] ?? '',
            'razon_social' => $nr['conductor_nombre'] ?? ''
        ];

        // Crear objetos SIFEN
        $key = new \sifen\KEY($certPath, $certPassword);
        $sifen = new \sifen\Sifen($modo, $key);

        $emisor = new \sifen\Emisor($emisorData);
        $receptor = new \sifen\Receptor($receptorData);
        $conductor = new \sifen\Conductor($conductorData);

        // Obtener establecimiento y punto de expedición por sucursal
        $estabInfo = getEstablecimientoByRemision($pdo, $masterDb, $habilitacion, $nr);
        $establecimiento = str_pad($estabInfo['establecimiento'], 3, '0', STR_PAD_LEFT);
        $puntoExpedicion = str_pad($estabInfo['punto_expedicion'], 3, '0', STR_PAD_LEFT);

        // Extraer solo el número secuencial del nro_documento (formato: 001-001-0000009)
        $partes = explode('-', $nr['nro_documento'] ?? '0000001');
        $numeroSecuencial = end($partes); // Obtiene la última parte: "0000009"

        $facturaData = [
            'emisor' => $emisor,
            'receptor' => $receptor,
            'conceptos' => $conceptos,
            'conductor' => $conductor,
            'ndoc' => $numeroSecuencial,
            'timbrado' => $config['timbrado'],
            'fec_timbrado' => $config['fecha_timbrado'],
            'inicio' => $nr['fecha_inicio_traslado'],
            'fin' => $nr['fecha_fin_traslado'],
            'cod_establecimiento' => $establecimiento,
            'cod_expedicion' => $puntoExpedicion,
            'moneda' => 'PYG',
            'cambio' => 1,
            'motivo_nr' => (int)$nr['motivo_remision'],
            'kmr' => (float)($nr['km_estimado'] ?? 0)
        ];

        // Log para debug
        error_log("NR SIFEN - RUC: {$config['eruc']}-{$config['edv']} | Timbrado: {$config['timbrado']} | Ambiente: {$config['ambiente_sifen']} | Cert: {$certPath}");

        $factura = new \sifen\Factura(\sifen\DTE::NR, $facturaData);

        // CSC e IDC para la firma y QR
        if (!empty($config['csc'])) {
            $sifen->setCSC($config['csc']);
        }
        if (!empty($config['id_csc'])) {
            $sifen->setIdc($config['id_csc']);
        }

        $sifen->agregarFactura($factura);
        $xmlRequest = $sifen->buildXML(true); // true = Modo Lote (Asíncrono)

        // Obtener CDC generado de la factura (después de buildXML)
        $cdcGenerado = $factura->cdc ?? '';
        if ($cdcGenerado) {
            $cdcGenerado = $cdcGenerado . \sifen\Sifen::getRuc($cdcGenerado, 'dv');
        }

        // Guardar XML generado (xmlRequest es SimpleXMLElement, usar asXML())
        $xmlPath = __DIR__ . "/xml_nr/{$id_empresa}";
        if (!is_dir($xmlPath)) {
            mkdir($xmlPath, 0755, true);
        }
        $xmlDebugStr = is_object($xmlRequest) && method_exists($xmlRequest, 'asXML') ? $xmlRequest->asXML() : (string) $xmlRequest;
        file_put_contents("{$xmlPath}/nr_{$id}.xml", $xmlDebugStr);

        // Enviar a SIFEN
        $respuesta = $sifen->enviar($xmlRequest);

        // Extraer XML de respuesta
        $xmlRespuesta = null;
        if (!empty($respuesta['response'])) {
            $xmlRespuesta = is_string($respuesta['response']) ? $respuesta['response'] : (method_exists($respuesta['response'], 'asXML') ? $respuesta['response']->asXML() : json_encode($respuesta['response']));
        }

        $timbradoEnviar = $config['timbrado'] ?? '';

        if ($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
            $protConsLote = $sifen->getProtConsLote();
            $estadoFinal = 'Pendiente (Lote)';
            $mensajeFinal = 'Lote enviado, esperando procesamiento';
            $protocoloAuth = $sifen->getProtAut();

            if (!empty($protConsLote)) {
                // Intentar consultar el lote hasta 3 veces con espera incremental
                $maxRetries = 3;
                for ($retry = 1; $retry <= $maxRetries; $retry++) {
                    sleep($retry * 2); // 2s, 4s, 6s
                    $resLote = $sifen->queryLote($protConsLote);

                    if (!empty($resLote['response'])) {
                        file_put_contents("{$xmlPath}/nr_{$id}_response.xml", $resLote['response']);

                        $dom = new DOMDocument();
                        @$dom->loadXML($resLote['response']);

                        // Verificar si sigue en procesamiento
                        $nodesCod = $dom->getElementsByTagNameNS('*', 'dCodResLot');
                        if ($nodesCod->length > 0 && $nodesCod->item(0)->nodeValue === '0361') {
                            continue;
                        }

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
                                "Timbrado <strong>$timbradoEnviar</strong> no está autorizado para emitir <strong>Nota de Remisión</strong>.<br><br>" .
                                "<em>Solución:</em> Ingrese al portal SIFEN y habilite el tipo de documento 'Nota de Remisión Electrónica' para este timbrado.";
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

                        if ($estadoFinal !== 'Pendiente (Lote)') {
                            break;
                        }
                    }
                }
            }

            // Usar CDC generado de la factura como fallback
            $idFromSifen = preg_replace('/\D+/', '', $sifen->getId() ?? '');
            $cdc = !empty($idFromSifen) ? $idFromSifen : $cdcGenerado;

            $responseData = json_encode([
                'cdc' => $cdc,
                'estado' => $estadoFinal,
                'codigo' => $codigoError ?? '',
                'mensaje' => $mensajeFinal,
                'protocolo' => $protocoloAuth,
                'fecha_proceso' => $sifen->getFecProc(),
                'protocolo_lote' => $protConsLote
            ]);

            if ($estadoFinal === 'Aprobado') {
                updateEstadoSifen($pdo, $empresaDb, $id, 'Aprobado', $cdc, $responseData, $xmlRespuesta);
                $rucMostrar = $config['eruc'] ?? $empresa['ruc'] ?? 'N/A';
                $timbradoMostrar = $config['timbrado'] ?? 'N/A';
                $empresaNombre = $empresa['empresa'] ?? 'N/A';
                echo json_encode([
                    'success' => true,
                    'message' => "Nota de Remisión aprobada por SIFEN\n\nEmpresa: {$empresaNombre}\nID Empresa: {$id_empresa}\nRUC: {$rucMostrar}\nTimbrado: {$timbradoMostrar}\nCDC: {$cdc}",
                    'cdc' => $cdc,
                    'ruc' => $rucMostrar,
                    'timbrado' => $timbradoMostrar
                ]);
            } else {
                // Estado rechazado o pendiente
                $estadoDB = ($estadoFinal === 'Pendiente (Lote)') ? 'Pendiente' : 'Rechazado';
                updateEstadoSifen($pdo, $empresaDb, $id, $estadoDB, $cdc, $responseData, $xmlRespuesta);
                $rucMostrar = $config['eruc'] ?? $empresa['ruc'] ?? 'N/A';
                $timbradoMostrar = $config['timbrado'] ?? 'N/A';
                $empresaNombre = $empresa['empresa'] ?? 'N/A';
                echo json_encode([
                    'success' => false,
                    'message' => $mensajeFinal,
                    'error' => $mensajeFinal,
                    'codigo' => $codigoError ?? '',
                    'ruc' => $rucMostrar,
                    'timbrado' => $timbradoMostrar
                ]);
            }
        } else {
            updateEstadoSifen($pdo, $empresaDb, $id, 'Rechazado', null, json_encode($respuesta), $xmlRespuesta ?? null);
            $rucMostrar = $config['eruc'] ?? $empresa['ruc'] ?? 'N/A';
            throw new Exception("Sin respuesta de SIFEN. RUC: {$rucMostrar}, Timbrado: {$timbradoEnviar}");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Consultar estado en SIFEN
 */
function consultarSifen($pdo, $masterDb, $id, $id_empresa)
{
    try {
        $cdc = $_GET['cdc'] ?? '';
        if (empty($cdc)) {
            throw new Exception("CDC no proporcionado");
        }

        $configData = getEmpresaConfig($pdo, $masterDb, $id_empresa);
        $empresa = $configData['empresa'];
        $config = $configData['config'];

        // Determinar la base de datos de la empresa
        $empresaDb = $empresa['dbase'] ?? "smx_{$id_empresa}";

        // Usar certificado desde habilitacion_sifen
        $certNombre = $config['cert_nombre'];
        $certBasePath = $config['cert_path'];
        $certPath = rtrim($certBasePath, '/') . '/' . $certNombre;

        if (!file_exists($certPath)) {
            throw new Exception("Certificado no encontrado: {$certPath}");
        }

        $certPassword = $config['cert_pass'];

        require_once __DIR__ . '/_lib/php-sifen3-custom/src/php-sifen.php';

        $ambiente = $config['ambiente_sifen'];
        $modo = ($ambiente == '1') ? 'prod' : 'test';

        $key = new \sifen\KEY($certPath, $certPassword);
        $sifen = new \sifen\Sifen($modo, $key);

        $respuesta = $sifen->siConsDE($cdc);

        // La respuesta puede tener 'status' o 'result' dependiendo de la versión
        $isSuccess = ($respuesta['status'] ?? $respuesta['result'] ?? '') == 'ok' ||
            ($respuesta['result'] ?? '') == 'success';

        if ($isSuccess || !empty($respuesta['data'])) {
            $estado = $sifen->getEstRes();
            $codRes = $sifen->getCodRes() ?? '';
            $msgRes = $sifen->getMsgRes() ?? '';

            // Si no hay estado, intentar extraerlo de la respuesta data
            if (empty($estado) && !empty($respuesta['data'])) {
                $estado = $respuesta['data']['estado'] ?? '';
                $codRes = $respuesta['data']['cod'] ?? $codRes;
                $msgRes = $respuesta['data']['msg'] ?? $msgRes;
            }

            // Si aún está vacío, marcar como Rechazado si hay código de error
            if (empty($estado) && !empty($codRes)) {
                $estado = 'Rechazado';
            }

            updateEstadoSifen($pdo, $empresaDb, $id, $estado, $cdc, json_encode($respuesta));

            echo json_encode([
                'success' => true,
                'message' => "Estado: $estado" . ($msgRes ? " - $msgRes" : ""),
                'estado' => $estado,
                'codigo' => $codRes,
                'mensaje' => $msgRes
            ]);
        } else {
            $errorMsg = $respuesta['error'] ?? $sifen->getMsgRes() ?? 'Error desconocido';
            $codRes = $sifen->getCodRes() ?? '';
            throw new Exception("Error consultando SIFEN [{$codRes}]: " . $errorMsg);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Anular Nota de Remisión en SIFEN
 */
function anularSifen($pdo, $masterDb, $id, $id_empresa)
{
    try {
        // Obtener configuración de empresa primero
        $configData = getEmpresaConfig($pdo, $masterDb, $id_empresa);
        $empresa = $configData['empresa'];
        $config = $configData['config'];

        // Determinar la base de datos de la empresa
        $empresaDb = $empresa['dbase'] ?? "smx_{$id_empresa}";

        $nr = getRemision($pdo, $masterDb, $empresaDb, $id);
        if (!$nr) {
            throw new Exception("Nota de Remisión no encontrada");
        }

        if ($nr['estado_sifen'] !== 'Aprobado') {
            throw new Exception("Solo se pueden anular documentos aprobados");
        }

        $cdc = $nr['cdc'];
        if (empty($cdc)) {
            throw new Exception("CDC no encontrado");
        }

        // Usar certificado desde habilitacion_sifen
        $certNombre = $config['cert_nombre'];
        $certBasePath = $config['cert_path'];
        $certPath = rtrim($certBasePath, '/') . '/' . $certNombre;

        if (!file_exists($certPath)) {
            throw new Exception("Certificado no encontrado: {$certPath}");
        }

        $certPassword = $config['cert_pass'];

        require_once __DIR__ . '/_lib/php-sifen3-custom/src/php-sifen.php';

        $ambiente = $config['ambiente_sifen'];
        $modo = ($ambiente == '1') ? 'prod' : 'test';

        $key = new \sifen\KEY($certPath, $certPassword);
        $sifen = new \sifen\Sifen($modo, $key);

        $respuesta = $sifen->anular($cdc);

        if ($respuesta['status'] == 'ok') {
            updateEstadoSifen($pdo, $empresaDb, $id, 'Anulado', $cdc, json_encode($respuesta));
            echo json_encode([
                'success' => true,
                'message' => 'Nota de Remisión anulada correctamente'
            ]);
        } else {
            throw new Exception("Error anulando en SIFEN");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Generar y firmar XML sin enviar a SIFEN
 */
function generarXML($pdo, $masterDb, $id, $id_empresa)
{
    try {
        if (empty($id)) {
            throw new Exception("ID de remisión requerido");
        }

        // Obtener configuración de empresa (incluyendo habilitacion_sifen)
        $configData = getEmpresaConfig($pdo, $masterDb, $id_empresa);
        $empresa = $configData['empresa'];
        $config = $configData['config'];
        $habilitacion = $configData['habilitacion'] ?? null;

        $empresaDb = $empresa['dbase'] ?? "smx_{$id_empresa}";

        // Obtener datos de NR
        $nr = getRemision($pdo, $masterDb, $empresaDb, $id);
        if (!$nr) {
            throw new Exception("Nota de Remisión no encontrada");
        }

        if (!$config) {
            throw new Exception("Configuración SIFEN no encontrada");
        }

        // Verificar certificado desde habilitacion_sifen
        $certNombre = $config['cert_nombre'];
        $certBasePath = $config['cert_path'];

        if (empty($certNombre)) {
            throw new Exception("cert_nombre no configurado en habilitacion_sifen");
        }

        $certPath = rtrim($certBasePath, '/') . '/' . $certNombre;

        if (!file_exists($certPath)) {
            throw new Exception("Certificado no encontrado: {$certPath}");
        }

        $certPassword = $config['cert_pass'];
        if (empty($certPassword)) {
            throw new Exception("Clave del certificado digital no configurada en habilitacion_sifen");
        }

        // Cargar librería SIFEN custom
        require_once __DIR__ . '/_lib/php-sifen3-custom/src/php-sifen.php';

        $modo = $config['ambiente_sifen'] == 1 ? 'prod' : 'test';

        // Construir emisor desde habilitacion_sifen
        $emisorData = [
            'ruc' => $config['eruc'],
            'dv' => $config['edv'],
            'razon_social' => $config['erazon_social'],
            'tipo_contribuyente' => '2',
            'ciudad' => (int)$config['ciudad_id'],
            'direccion' => $config['direccion'],
            'telefono' => $config['telefono'],
            'email' => $config['email'],
            'act_eco' => $config['cod_act'],
            'act_eco_desc' => $config['des_act']
        ];

        // Construir receptor
        $receptorData = [
            'documento' => $nr['receptor_ruc'],
            'tipo_doc' => strlen($nr['receptor_ruc']) > 7 ? 'ruc' : 'ci',
            'razon_social' => $nr['receptor_nombre'],
            'ciudad' => (int)($nr['receptor_ciudad'] ?? 1),
            'direccion' => $nr['receptor_direccion'] ?? '',
            'telefono' => $nr['receptor_telefono'] ?? '',
            'email' => $nr['receptor_email'] ?? ''
        ];

        // Construir conceptos
        $conceptos = [];
        foreach ($nr['items'] as $item) {
            $conceptos[] = new \sifen\Concepto([
                'codigo' => $item['codigo'] ?? 'MERC',
                'descripcion' => $item['descripcion'],
                'precio' => 0,
                'cantidad' => (float)$item['cantidad'],
                'tasa_iva' => 0,
                'descuento' => 0,
                'unidad_medida' => $item['unidad_medida'] ?? '77'
            ]);
        }

        // Construir conductor
        $conductorData = [
            'documento' => $nr['conductor_documento'] ?? '',
            'razon_social' => $nr['conductor_nombre'] ?? ''
        ];

        // Crear objetos SIFEN
        $key = new \sifen\KEY($certPath, $certPassword);
        $sifen = new \sifen\Sifen($modo, $key);

        $emisor = new \sifen\Emisor($emisorData);
        $receptor = new \sifen\Receptor($receptorData);
        $conductor = new \sifen\Conductor($conductorData);

        // Obtener establecimiento y punto de expedición por sucursal
        $estabInfo = getEstablecimientoByRemision($pdo, $masterDb, $habilitacion, $nr);
        $establecimiento = str_pad($estabInfo['establecimiento'], 3, '0', STR_PAD_LEFT);
        $puntoExpedicion = str_pad($estabInfo['punto_expedicion'], 3, '0', STR_PAD_LEFT);

        // Extraer solo el número secuencial del nro_documento (formato: 001-001-0000009)
        $partes = explode('-', $nr['nro_documento']);
        $numeroSecuencial = end($partes); // Obtiene la última parte: "0000009"

        $facturaData = [
            'emisor' => $emisor,
            'receptor' => $receptor,
            'conceptos' => $conceptos,
            'conductor' => $conductor,
            'ndoc' => $numeroSecuencial,
            'timbrado' => $config['timbrado'],
            'fec_timbrado' => $config['fecha_timbrado'],
            'inicio' => $nr['fecha_inicio_traslado'],
            'fin' => $nr['fecha_fin_traslado'],
            'cod_establecimiento' => $establecimiento,
            'cod_expedicion' => $puntoExpedicion,
            'moneda' => 'PYG',
            'cambio' => 1,
            'motivo_nr' => (int)$nr['motivo_remision'],
            'kmr' => (float)($nr['km_estimado'] ?? 0)
        ];

        $factura = new \sifen\Factura(\sifen\DTE::NR, $facturaData);

        // CSC e IDC para la firma
        $sifen->csc = $config['csc'] ?? '';
        $sifen->idc = $config['id_csc'] ?? '0001';

        $sifen->agregarFactura($factura);
        $xmlRequest = $sifen->buildXML(false);

        // Guardar XML firmado
        $xmlPath = __DIR__ . "/xml_nr/{$id_empresa}";
        if (!is_dir($xmlPath)) {
            mkdir($xmlPath, 0755, true);
        }
        $xmlContent = $xmlRequest->asXML();
        file_put_contents("{$xmlPath}/nr_{$id}.xml", $xmlContent);

        // Guardar XML en la base de datos
        $stmt = $pdo->prepare("UPDATE $empresaDb.nota_remision SET xml_firmado = :xml WHERE id_remision = :id");
        $stmt->execute([':xml' => $xmlContent, ':id' => $id]);

        $result = [
            'success' => true,
            'message' => 'XML generado y firmado correctamente',
            'xml_path' => "xml_nr/{$id_empresa}/nr_{$id}.xml"
        ];

        // Solo hacer echo si se llama directamente
        if (basename($_SERVER['SCRIPT_FILENAME']) === 'nr_sifen_api.php') {
            echo json_encode($result);
        }
        return $result;
    } catch (Exception $e) {
        $result = [
            'success' => false,
            'error' => $e->getMessage()
        ];

        // Solo hacer echo si se llama directamente
        if (basename($_SERVER['SCRIPT_FILENAME']) === 'nr_sifen_api.php') {
            echo json_encode($result);
        }
        return $result;
    }
}

/**
 * Calcular dígito verificador de RUC
 */
if (!function_exists('calcularDV')) {
    function calcularDV($ruc)
    {
        $ruc = preg_replace('/[^0-9]/', '', $ruc);
        $baseMax = 11;
        $k = 2;
        $total = 0;

        for ($i = strlen($ruc) - 1; $i >= 0; $i--) {
            $total += (int)$ruc[$i] * $k;
            $k++;
            if ($k > $baseMax) $k = 2;
        }

        $resto = $total % 11;
        return ($resto > 1) ? (11 - $resto) : 0;
    }
}

/**
 * Anular Nota de Remisión localmente (para rechazadas)
 */
function anularLocal($pdo, $masterDb, $id, $id_empresa)
{
    try {
        if (empty($id)) {
            throw new Exception("ID de remisión requerido");
        }

        // Obtener base de datos de la empresa
        $stmt = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmt->execute([':id' => $id_empresa]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
        $empresaDb = $empresa['dbase'] ?? "smx_{$id_empresa}";

        // Verificar que existe y está rechazada
        $stmt = $pdo->prepare("SELECT estado_sifen FROM $empresaDb.nota_remision WHERE id_remision = :id");
        $stmt->execute([':id' => $id]);
        $nr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$nr) {
            throw new Exception("Nota de remisión no encontrada");
        }

        if ($nr['estado_sifen'] !== 'Rechazado') {
            throw new Exception("Solo se pueden anular localmente las NR rechazadas");
        }

        // Actualizar estado a Anulado
        $stmt = $pdo->prepare("UPDATE $empresaDb.nota_remision SET estado_sifen = 'Anulado', mensaje_sifen = CONCAT(IFNULL(mensaje_sifen, ''), ' | Anulado localmente: " . date('Y-m-d H:i:s') . "') WHERE id_remision = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Nota de remisión anulada localmente'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
