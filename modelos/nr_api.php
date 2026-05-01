<?php

/**
 * API para Notas de Remisión Electrónicas
 * Endpoints: list, create, get, update, delete, motivos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// Configuración de base de datos
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$id_empresa = $_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169;

// Obtener la base de datos de la empresa
$empresaDb = "smx_{$id_empresa}"; // Por defecto
try {
    $stmtEmpDb = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = :id");
    $stmtEmpDb->execute([':id' => $id_empresa]);
    $rowEmpDb = $stmtEmpDb->fetch(PDO::FETCH_ASSOC);
    if ($rowEmpDb && !empty($rowEmpDb['dbase'])) {
        $empresaDb = $rowEmpDb['dbase'];
    }
} catch (Exception $e) {
    // Usar valor por defecto
}

/**
 * Genera el CDC (Código de Control) para Nota de Remisión
 * Estructura: TT + RUC(8) + DV(1) + EST(3) + EXP(3) + NDOC(7) + TIPOCONT(1) + FECHA(8) + TIPOEMISION(1) + CODSEG(9) + DVCDC(1) = 44 chars
 * @param array $empresa Datos de la empresa
 * @param string $nroDocumento Número del documento
 * @param string $fecha Fecha de emisión (Y-m-d o timestamp)
 * @return string CDC de 44 caracteres
 */
function generarCDC($empresa, $nroDocumento, $fecha = null)
{
    $tipoDoc = '07'; // 07 = Nota de Remisión

    // RUC del emisor (8 dígitos)
    $rucEmisor = str_pad(preg_replace('/[^0-9]/', '', $empresa['ruc'] ?? ''), 8, '0', STR_PAD_LEFT);

    // DV del emisor (1 dígito)
    $dvEmisor = $empresa['dv'] ?? '';
    if (empty($dvEmisor)) {
        $dvEmisor = calcularDV($rucEmisor);
    }
    $dvEmisor = substr($dvEmisor, 0, 1);

    // Código establecimiento (3 dígitos)
    $establecimiento = str_pad($empresa['establecimiento'] ?? '001', 3, '0', STR_PAD_LEFT);

    // Código expedición (3 dígitos)  
    $expedicion = str_pad($empresa['punto_expedicion'] ?? '001', 3, '0', STR_PAD_LEFT);

    // Número de documento (7 dígitos)
    $ndoc = str_pad(preg_replace('/[^0-9]/', '', $nroDocumento), 7, '0', STR_PAD_LEFT);

    // Tipo de contribuyente (1 dígito): 1=Persona Física, 2=Persona Jurídica
    $tipoContribuyente = $empresa['tipoContribuyente'] ?? '2';

    // Fecha de emisión (YYYYMMDD - 8 dígitos)
    if (empty($fecha)) {
        $fecha = date('Y-m-d');
    }
    $fechaEmision = date('Ymd', strtotime($fecha));

    // Tipo de emisión (1 dígito): 1=Normal, 2=Contingencia
    $tipoEmision = '1';

    // Código de seguridad (9 dígitos) - usar timestamp o aleatorio
    $codSeguridad = str_pad(substr(time(), -9), 9, '0', STR_PAD_LEFT);

    // Construir CDC sin DV (43 caracteres)
    $cdcSinDV = $tipoDoc . $rucEmisor . $dvEmisor . $establecimiento . $expedicion . $ndoc . $tipoContribuyente . $fechaEmision . $tipoEmision . $codSeguridad;

    // Calcular DV del CDC
    $dvCDC = calcularDV($cdcSinDV);

    // CDC completo (44 caracteres)
    return $cdcSinDV . $dvCDC;
}

/**
 * Calcula el dígito verificador usando Módulo 11
 * @param string $numero Número a calcular
 * @param int $baseMax Base máxima (default 11)
 * @return string Dígito verificador
 */
function calcularDV($numero, $baseMax = 11)
{
    $numero = trim($numero);
    $total = 0;
    $k = 2;

    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        if ($k > $baseMax) {
            $k = 2;
        }
        $total += intval(substr($numero, $i, 1)) * $k;
        $k++;
    }

    $resto = $total % 11;

    if ($resto > 1) {
        return (string)(11 - $resto);
    } else {
        return '0';
    }
}

// Incluir funciones SIFEN (generarXML, etc.) - el archivo está protegido para no ejecutar código cuando es incluido
require_once __DIR__ . '/nr_sifen_api.php';

switch ($action) {
    case 'motivos':
        // Obtener motivos de remisión
        try {
            $stmt = $pdo->query("SELECT codigo, descripcion FROM {$masterDb}.remisiones_motivos ORDER BY codigo");
            if ($stmt) {
                $motivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $motivos = [];
            }
            echo json_encode(['success' => true, 'data' => $motivos]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'list':
        // Listar notas de remisión con paginación para AG Grid
        $startRow = isset($_GET['startRow']) ? (int)$_GET['startRow'] : 0;
        $endRow = isset($_GET['endRow']) ? (int)$_GET['endRow'] : 100;
        $limit = $endRow - $startRow;

        // Filtros
        $where = "nr.id_empresa = :id_empresa";
        $params = [':id_empresa' => $id_empresa];

        if (!empty($_GET['fecha_desde'])) {
            $where .= " AND nr.fecha >= :fecha_desde";
            $params[':fecha_desde'] = $_GET['fecha_desde'];
        }
        if (!empty($_GET['fecha_hasta'])) {
            $where .= " AND nr.fecha <= :fecha_hasta";
            $params[':fecha_hasta'] = $_GET['fecha_hasta'];
        }
        if (!empty($_GET['estado_sifen'])) {
            $where .= " AND nr.estado_sifen = :estado_sifen";
            $params[':estado_sifen'] = $_GET['estado_sifen'];
        }

        // Contar total
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM {$empresaDb}.nota_remision nr WHERE $where");
        $stmtCount->execute($params);
        $rowCount = (int)$stmtCount->fetchColumn();

        // Obtener filas
        $sql = "
            SELECT 
                nr.id_remision,
                nr.nro_documento,
                nr.fecha,
                nr.fecha_inicio_traslado,
                nr.fecha_fin_traslado,
                nr.motivo_remision,
                nr.motivo_descripcion,
                nr.receptor_ruc,
                nr.receptor_nombre,
                nr.receptor_direccion,
                nr.conductor_nombre,
                nr.vehiculo_chapa,
                nr.km_estimado,
                nr.timbrado,
                nr.cdc,
                nr.estado_sifen,
                nr.mensaje_sifen,
                nr.estado,
                nr.created_at
            FROM {$empresaDb}.nota_remision nr
            WHERE $where
            ORDER BY nr.id_remision DESC
            LIMIT $limit OFFSET $startRow
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Para AG Grid infinite row model: lastRow debe ser el total cuando no hay más datos
        // Si hay menos filas que las solicitadas, significa que llegamos al final
        $lastRow = (count($rows) < $limit) ? ($startRow + count($rows)) : -1;
        if ($lastRow === -1 && ($startRow + count($rows)) >= $rowCount) {
            $lastRow = $rowCount;
        }

        // Agregar nro_documento_formateado a cada fila (nro_documento ya viene formateado)
        foreach ($rows as &$row) {
            $row['nro_documento_formateado'] = $row['nro_documento'] ?? '001-001-0000001';
        }
        echo json_encode([
            'success' => true,
            'rows' => $rows,
            'lastRow' => $lastRow
        ]);
        break;

    case 'get':
        // Obtener una nota de remisión con sus items
        $id = $_GET['id'] ?? 0;

        $stmt = $pdo->prepare("SELECT * FROM {$empresaDb}.nota_remision WHERE id_remision = :id AND id_empresa = :id_empresa");
        $stmt->execute([':id' => $id, ':id_empresa' => $id_empresa]);
        $remision = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$remision) {
            echo json_encode(['success' => false, 'error' => 'Nota de remisión no encontrada']);
            exit;
        }

        // Obtener items
        $stmtItems = $pdo->prepare("SELECT * FROM {$empresaDb}.nota_remision_items WHERE id_remision = :id");
        $stmtItems->execute([':id' => $id]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Obtener facturas vinculadas
        $facturasVinculadas = [];
        try {
            $stmtFact = $pdo->prepare("SELECT id_factura, nro_factura, cdc_factura as cdc, fecha_factura as fecha, total_factura as total FROM {$empresaDb}.nota_remision_facturas WHERE id_remision = :id");
            $stmtFact->execute([':id' => $id]);
            $facturasVinculadas = $stmtFact->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Tabla puede no existir
        }

        $remision['items'] = $items;
        $remision['facturas_vinculadas'] = $facturasVinculadas;
        // nro_documento ya viene formateado: Establecimiento-Punto_Expedicion-Numero
        $remision['nro_documento_formateado'] = $remision['nro_documento'] ?? '001-001-0000001';
        echo json_encode(['success' => true, 'data' => $remision]);
        break;

    case 'create':
        // Crear nueva nota de remisión
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }

        // Obtener próximo número de documento secuencial
        $stmtNum = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(nro_documento, '-', -1) AS UNSIGNED)), 0) + 1 FROM {$empresaDb}.nota_remision WHERE id_empresa = :id_empresa");
        $stmtNum->execute([':id_empresa' => $id_empresa]);
        $numeroSecuencial = str_pad($stmtNum->fetchColumn(), 7, '0', STR_PAD_LEFT);

        // Construir nro_documento con formato: Establecimiento-Punto_Expedicion-Numero
        $establecimiento = str_pad($input['establecimiento'] ?? '001', 3, '0', STR_PAD_LEFT);
        $puntoExpedicion = str_pad($input['punto_expedicion'] ?? '001', 3, '0', STR_PAD_LEFT);
        $nroDocumento = $establecimiento . '-' . $puntoExpedicion . '-' . $numeroSecuencial;

        // Obtener datos completos de la empresa para generar CDC
        $stmtEmpresa = $pdo->prepare("SELECT ruc, dv, timbrado, tipoContribuyente, establecimiento, punto_expedicion FROM {$masterDb}.empresa WHERE id_empresa = :id");
        $stmtEmpresa->execute([':id' => $id_empresa]);
        $empresaData = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);
        $timbrado = $empresaData['timbrado'] ?? '';

        // Fecha de emisión
        $fechaEmision = $input['fecha'] ?? date('Y-m-d');

        // Generar CDC
        $cdc = generarCDC($empresaData, $nroDocumento, $fechaEmision);

        // Procesar receptor_ruc: separar RUC del DV si viene junto
        $receptorRuc = trim($input['receptor_ruc'] ?? '');
        $receptorDv = trim($input['receptor_dv'] ?? '');
        if (!empty($receptorRuc) && strpos($receptorRuc, '-') !== false) {
            $partes = explode('-', $receptorRuc);
            $receptorRuc = $partes[0];
            $receptorDv = $partes[1] ?? $receptorDv;
        }

        // Obtener descripción del motivo desde la tabla
        $motivoRemision = $input['motivo_remision'] ?? 1;
        $motivoDescripcion = $input['motivo_descripcion'] ?? '';
        if (empty($motivoDescripcion)) {
            $stmtMotivo = $pdo->prepare("SELECT descripcion FROM {$masterDb}.remisiones_motivos WHERE codigo = :codigo");
            $stmtMotivo->execute([':codigo' => $motivoRemision]);
            $motivoDescripcion = $stmtMotivo->fetchColumn() ?: '';
        }

        // Hardcodear ciudad si está vacía (1 = Asunción es válida para SIFEN)
        $receptorCiudad = $input['receptor_ciudad'] ?? null;
        $receptorCiudadNombre = $input['receptor_ciudad_nombre'] ?? '';
        if (empty($receptorCiudad)) {
            $receptorCiudad = 1; // Asunción
            $receptorCiudadNombre = $receptorCiudadNombre ?: 'ASUNCION';
        }

        // Hardcodear conductor_direccion si está vacío
        $conductorDireccion = trim($input['conductor_direccion'] ?? '');
        if (empty($conductorDireccion)) {
            $conductorDireccion = 'Sin dirección especificada';
        }

        // Hardcodear receptor_direccion si está vacío
        $receptorDireccion = trim($input['receptor_direccion'] ?? '');
        if (empty($receptorDireccion)) {
            $receptorDireccion = 'Sin dirección especificada';
        }

        // Crear tabla de facturas vinculadas si no existe (ANTES de la transacción - DDL hace commit implícito)
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$empresaDb}.nota_remision_facturas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_remision INT NOT NULL,
                    id_factura INT NOT NULL,
                    nro_factura VARCHAR(50),
                    cdc_factura VARCHAR(100),
                    fecha_factura DATE,
                    total_factura DECIMAL(15,2),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_remision (id_remision),
                    INDEX idx_factura (id_factura)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            // Tabla ya existe, continuar
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO {$empresaDb}.nota_remision (
                    id_empresa, nro_documento, fecha, fecha_inicio_traslado, fecha_fin_traslado,
                    motivo_remision, motivo_descripcion,
                    receptor_ruc, receptor_dv, receptor_nombre, receptor_direccion, receptor_ciudad, receptor_ciudad_nombre,
                    conductor_documento, conductor_nombre, conductor_direccion,
                    vehiculo_tipo, vehiculo_marca, vehiculo_chapa,
                    km_estimado, establecimiento, punto_expedicion, timbrado, cdc, cdc_asociado, timbrado_asociado
                ) VALUES (
                    :id_empresa, :nro_documento, :fecha, :fecha_inicio, :fecha_fin,
                    :motivo, :motivo_desc,
                    :receptor_ruc, :receptor_dv, :receptor_nombre, :receptor_direccion, :receptor_ciudad, :receptor_ciudad_nombre,
                    :conductor_doc, :conductor_nombre, :conductor_direccion,
                    :vehiculo_tipo, :vehiculo_marca, :vehiculo_chapa,
                    :km_estimado, :establecimiento, :punto_expedicion, :timbrado, :cdc, :cdc_asociado, :timbrado_asociado
                )
            ");

            $stmt->execute([
                ':id_empresa' => $id_empresa,
                ':nro_documento' => $nroDocumento,
                ':fecha' => $input['fecha'] ?? date('Y-m-d'),
                ':fecha_inicio' => $input['fecha_inicio_traslado'] ?? date('Y-m-d'),
                ':fecha_fin' => $input['fecha_fin_traslado'] ?? date('Y-m-d'),
                ':motivo' => $motivoRemision,
                ':motivo_desc' => $motivoDescripcion,
                ':receptor_ruc' => $receptorRuc,
                ':receptor_dv' => $receptorDv,
                ':receptor_nombre' => $input['receptor_nombre'] ?? '',
                ':receptor_direccion' => $receptorDireccion,
                ':receptor_ciudad' => $receptorCiudad,
                ':receptor_ciudad_nombre' => $receptorCiudadNombre,
                ':conductor_doc' => $input['conductor_documento'] ?? '',
                ':conductor_nombre' => $input['conductor_nombre'] ?? '',
                ':conductor_direccion' => $conductorDireccion,
                ':vehiculo_tipo' => $input['vehiculo_tipo'] ?? '',
                ':vehiculo_marca' => $input['vehiculo_marca'] ?? '',
                ':vehiculo_chapa' => $input['vehiculo_chapa'] ?? '',
                ':km_estimado' => $input['km_estimado'] ?? 0,
                ':establecimiento' => $input['establecimiento'] ?? '001',
                ':punto_expedicion' => $input['punto_expedicion'] ?? '001',
                ':timbrado' => $timbrado,
                ':cdc' => $cdc,
                ':cdc_asociado' => $input['cdc_asociado'] ?? null,
                ':timbrado_asociado' => $input['timbrado_asociado'] ?? null
            ]);

            $idRemision = $pdo->lastInsertId();

            // Insertar facturas vinculadas
            if (!empty($input['facturas_vinculadas']) && is_array($input['facturas_vinculadas'])) {
                $stmtFactura = $pdo->prepare("
                            INSERT INTO {$empresaDb}.nota_remision_facturas 
                            (id_remision, id_factura, nro_factura, cdc_factura, fecha_factura, total_factura)
                            VALUES (:id_remision, :id_factura, :nro_factura, :cdc_factura, :fecha_factura, :total_factura)
                        ");
                foreach ($input['facturas_vinculadas'] as $fac) {
                    $stmtFactura->execute([
                        ':id_remision' => $idRemision,
                        ':id_factura' => $fac['id_factura'] ?? 0,
                        ':nro_factura' => $fac['nro_factura'] ?? '',
                        ':cdc_factura' => $fac['cdc'] ?? '',
                        ':fecha_factura' => $fac['fecha'] ?? null,
                        ':total_factura' => $fac['total'] ?? 0
                    ]);
                }
            }

            // Insertar items
            if (!empty($input['items']) && is_array($input['items'])) {
                $stmtItem = $pdo->prepare("
                            INSERT INTO {$empresaDb}.nota_remision_items 
                            (id_remision, id_producto, codigo, descripcion, cantidad, unidad_medida, precio_unitario, id_factura_origen)
                            VALUES (:id_remision, :id_producto, :codigo, :descripcion, :cantidad, :unidad_medida, :precio_unitario, :id_factura_origen)
                        ");
                foreach ($input['items'] as $item) {
                    $stmtItem->execute([
                        ':id_remision' => $idRemision,
                        ':id_producto' => $item['id_producto'] ?? null,
                        ':codigo' => $item['codigo'] ?? '',
                        ':descripcion' => $item['descripcion'] ?? '',
                        ':cantidad' => $item['cantidad'] ?? 1,
                        ':unidad_medida' => $item['unidad_medida'] ?? '77',
                        ':precio_unitario' => $item['precio_unitario'] ?? 0,
                        ':id_factura_origen' => $item['id_factura_origen'] ?? null
                    ]);
                }
            }

            $pdo->commit();

            // Actualizar id_sucursal si está vacío (usar de variable global o de la solicitud)
            $idSucursal = $input['id_sucursal'] ?? $_SESSION['id_sucursal'] ?? null;
            if (!empty($idSucursal)) {
                $stmtSuc = $pdo->prepare("UPDATE {$empresaDb}.nota_remision SET id_sucursal = :id_sucursal WHERE id_remision = :id");
                $stmtSuc->execute([':id_sucursal' => $idSucursal, ':id' => $idRemision]);
            }

            // Enviar a SIFEN automáticamente (modo lote)
            $sifenResult = null;
            $sifenError = null;
            try {
                // Llamar a la función enviarSifen que ya está incluida
                ob_start();
                enviarSifen($pdo, $masterDb, $idRemision, $id_empresa);
                $sifenOutput = ob_get_clean();
                $sifenResult = json_decode($sifenOutput, true);
            } catch (Exception $e) {
                $sifenError = $e->getMessage();
                error_log("Error enviando a SIFEN: " . $sifenError);
            }

            // Preparar respuesta con resultado de SIFEN
            $response = [
                'success' => true,
                'id' => $idRemision,
                'nro_documento' => $nroDocumento,
                'nro_documento_formateado' => $nroDocumento,
                'cdc' => $cdc
            ];

            if ($sifenResult) {
                $response['sifen'] = $sifenResult;
                if (!empty($sifenResult['success']) && !empty($sifenResult['cdc'])) {
                    $response['cdc'] = $sifenResult['cdc'];
                    $response['estado_sifen'] = 'Aprobado';
                    $response['message'] = $sifenResult['message'] ?? 'Nota de Remisión enviada y aprobada por SIFEN';
                } elseif (isset($sifenResult['error'])) {
                    $response['estado_sifen'] = 'Rechazado';
                    $response['sifen_error'] = $sifenResult['error'];
                }
            } elseif ($sifenError) {
                $response['sifen_error'] = $sifenError;
                $response['estado_sifen'] = 'Error';
            }

            echo json_encode($response);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update':
        // Actualizar nota de remisión
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id_remision'] ?? $_GET['id'] ?? 0;

        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID requerido']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Verificar que esté en estado Pendiente
            $stmtCheck = $pdo->prepare("SELECT id_remision FROM {$empresaDb}.nota_remision WHERE id_remision = :id AND id_empresa = :id_empresa AND estado_sifen = 'Pendiente'");
            $stmtCheck->execute([':id' => $id, ':id_empresa' => $id_empresa]);
            if (!$stmtCheck->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Solo se pueden editar remisiones en estado Pendiente']);
                exit;
            }

            // Procesar receptor_ruc: separar RUC del DV si viene junto
            $receptorRuc = trim($input['receptor_ruc'] ?? '');
            $receptorDv = trim($input['receptor_dv'] ?? '');
            if (!empty($receptorRuc) && strpos($receptorRuc, '-') !== false) {
                $partes = explode('-', $receptorRuc);
                $receptorRuc = $partes[0];
                $receptorDv = $partes[1] ?? $receptorDv;
            }

            // Obtener descripción del motivo desde la tabla
            $motivoRemision = $input['motivo_remision'] ?? 1;
            $motivoDescripcion = $input['motivo_descripcion'] ?? '';
            if (empty($motivoDescripcion)) {
                $stmtMotivo = $pdo->prepare("SELECT descripcion FROM {$masterDb}.remisiones_motivos WHERE codigo = :codigo");
                $stmtMotivo->execute([':codigo' => $motivoRemision]);
                $motivoDescripcion = $stmtMotivo->fetchColumn() ?: '';
            }

            // Hardcodear ciudad si está vacía (1 = Asunción es válida para SIFEN)
            $receptorCiudad = $input['receptor_ciudad'] ?? null;
            $receptorCiudadNombre = $input['receptor_ciudad_nombre'] ?? '';
            if (empty($receptorCiudad)) {
                $receptorCiudad = 1; // Asunción
                $receptorCiudadNombre = $receptorCiudadNombre ?: 'ASUNCION';
            }

            // Hardcodear conductor_direccion si está vacío
            $conductorDireccion = trim($input['conductor_direccion'] ?? '');
            if (empty($conductorDireccion)) {
                $conductorDireccion = 'Sin dirección especificada';
            }

            // Hardcodear receptor_direccion si está vacío
            $receptorDireccion = trim($input['receptor_direccion'] ?? '');
            if (empty($receptorDireccion)) {
                $receptorDireccion = 'Sin dirección especificada';
            }

            // Verificar si el registro tiene CDC, si no lo tiene, generarlo
            $stmtCdc = $pdo->prepare("SELECT cdc, nro_documento, establecimiento, punto_expedicion FROM {$empresaDb}.nota_remision WHERE id_remision = :id");
            $stmtCdc->execute([':id' => $id]);
            $rowCdc = $stmtCdc->fetch(PDO::FETCH_ASSOC);
            $cdcExistente = $rowCdc['cdc'] ?? '';
            $nroDocumentoAnterior = $rowCdc['nro_documento'] ?? '';
            $estAnterior = $rowCdc['establecimiento'] ?? '001';
            $puntoAnterior = $rowCdc['punto_expedicion'] ?? '001';

            // Extraer número secuencial del nro_documento (último componente después de los guiones)
            // Formatos posibles: "0000001" o "001-001-0000001"
            $numeroSecuencial = $nroDocumentoAnterior;
            if (strpos($nroDocumentoAnterior, '-') !== false) {
                $partes = explode('-', $nroDocumentoAnterior);
                $numeroSecuencial = end($partes); // Obtener el último componente
            }
            $numeroSecuencial = str_pad(preg_replace('/[^0-9]/', '', $numeroSecuencial), 7, '0', STR_PAD_LEFT);

            // Construir nro_documento con formato: Establecimiento-Punto_Expedicion-Numero
            $establecimiento = str_pad($input['establecimiento'] ?? '001', 3, '0', STR_PAD_LEFT);
            $puntoExpedicion = str_pad($input['punto_expedicion'] ?? '001', 3, '0', STR_PAD_LEFT);
            $nroDocumentoFormateado = $establecimiento . '-' . $puntoExpedicion . '-' . $numeroSecuencial;

            // Detectar si hay cambios en documento, establecimiento o punto
            $cambioDocumento = ($nroDocumentoFormateado !== $nroDocumentoAnterior);
            $cambioEstablecimiento = ($establecimiento !== $estAnterior);
            $cambioPunto = ($puntoExpedicion !== $puntoAnterior);
            $hayChanges = $cambioDocumento || $cambioEstablecimiento || $cambioPunto;

            $cdc = $cdcExistente;
            if (empty($cdc)) {
                // Obtener datos de empresa para generar CDC
                $stmtEmpresa = $pdo->prepare("SELECT ruc, dv, timbrado, tipoContribuyente, establecimiento, punto_expedicion FROM {$masterDb}.empresa WHERE id_empresa = :id");
                $stmtEmpresa->execute([':id' => $id_empresa]);
                $empresaData = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

                $fechaEmision = $input['fecha'] ?? date('Y-m-d');
                $cdc = generarCDC($empresaData, $numeroSecuencial, $fechaEmision);
            }

            $stmt = $pdo->prepare("
                UPDATE {$empresaDb}.nota_remision SET
                    nro_documento = :nro_documento,
                    fecha = :fecha,
                    fecha_inicio_traslado = :fecha_inicio,
                    fecha_fin_traslado = :fecha_fin,
                    motivo_remision = :motivo,
                    motivo_descripcion = :motivo_desc,
                    receptor_ruc = :receptor_ruc,
                    receptor_dv = :receptor_dv,
                    receptor_nombre = :receptor_nombre,
                    receptor_direccion = :receptor_direccion,
                    receptor_ciudad = :receptor_ciudad,
                    receptor_ciudad_nombre = :receptor_ciudad_nombre,
                    conductor_documento = :conductor_doc,
                    conductor_nombre = :conductor_nombre,
                    conductor_direccion = :conductor_direccion,
                    vehiculo_tipo = :vehiculo_tipo,
                    vehiculo_marca = :vehiculo_marca,
                    vehiculo_chapa = :vehiculo_chapa,
                    km_estimado = :km_estimado,
                    establecimiento = :establecimiento,
                    punto_expedicion = :punto_expedicion,
                    cdc = :cdc,
                    xml_firmado = " . ($hayChanges ? "NULL" : "xml_firmado") . ",
                    xml_respuesta = " . ($hayChanges ? "NULL" : "xml_respuesta") . ",
                    mensaje_sifen = " . ($hayChanges ? "NULL" : "mensaje_sifen") . ",
                    cdc_asociado = " . ($hayChanges ? "NULL" : "cdc_asociado") . ",
                    timbrado_asociado = " . ($hayChanges ? "NULL" : "timbrado_asociado") . ",
                    estado_sifen = " . ($hayChanges ? "'Pendiente'" : "estado_sifen") . "
                WHERE id_remision = :id AND id_empresa = :id_empresa
            ");

            $stmt->execute([
                ':id' => $id,
                ':id_empresa' => $id_empresa,
                ':nro_documento' => $nroDocumentoFormateado,
                ':fecha' => $input['fecha'],
                ':fecha_inicio' => $input['fecha_inicio_traslado'],
                ':fecha_fin' => $input['fecha_fin_traslado'],
                ':motivo' => $motivoRemision,
                ':motivo_desc' => $motivoDescripcion,
                ':receptor_ruc' => $receptorRuc,
                ':receptor_dv' => $receptorDv,
                ':receptor_nombre' => $input['receptor_nombre'],
                ':receptor_direccion' => $receptorDireccion,
                ':receptor_ciudad' => $receptorCiudad,
                ':receptor_ciudad_nombre' => $receptorCiudadNombre,
                ':conductor_doc' => $input['conductor_documento'],
                ':conductor_nombre' => $input['conductor_nombre'],
                ':conductor_direccion' => $conductorDireccion,
                ':vehiculo_tipo' => $input['vehiculo_tipo'] ?? '',
                ':vehiculo_marca' => $input['vehiculo_marca'] ?? '',
                ':vehiculo_chapa' => $input['vehiculo_chapa'],
                ':km_estimado' => $input['km_estimado'] ?? 0,
                ':establecimiento' => $establecimiento,
                ':punto_expedicion' => $puntoExpedicion,
                ':cdc' => $cdc
            ]);

            // Eliminar items existentes y volver a insertar
            $stmtDelItems = $pdo->prepare("DELETE FROM {$empresaDb}.nota_remision_items WHERE id_remision = :id");
            $stmtDelItems->execute([':id' => $id]);

            // Eliminar facturas vinculadas existentes y volver a insertar
            try {
                $stmtDelFact = $pdo->prepare("DELETE FROM {$empresaDb}.nota_remision_facturas WHERE id_remision = :id");
                $stmtDelFact->execute([':id' => $id]);
            } catch (Exception $e) {
                // Tabla puede no existir
            }

            // Insertar facturas vinculadas
            if (!empty($input['facturas_vinculadas']) && is_array($input['facturas_vinculadas'])) {
                $stmtFactura = $pdo->prepare("
                    INSERT INTO {$empresaDb}.nota_remision_facturas 
                    (id_remision, id_factura, nro_factura, cdc_factura, fecha_factura, total_factura)
                    VALUES (:id_remision, :id_factura, :nro_factura, :cdc_factura, :fecha_factura, :total_factura)
                ");
                foreach ($input['facturas_vinculadas'] as $fac) {
                    $stmtFactura->execute([
                        ':id_remision' => $id,
                        ':id_factura' => $fac['id_factura'] ?? 0,
                        ':nro_factura' => $fac['nro_factura'] ?? '',
                        ':cdc_factura' => $fac['cdc'] ?? '',
                        ':fecha_factura' => $fac['fecha'] ?? null,
                        ':total_factura' => $fac['total'] ?? 0
                    ]);
                }
            }

            // Insertar items
            if (!empty($input['items']) && is_array($input['items'])) {
                $stmtItem = $pdo->prepare("
                    INSERT INTO {$empresaDb}.nota_remision_items 
                    (id_remision, id_producto, codigo, descripcion, cantidad, unidad_medida, precio_unitario, id_factura_origen)
                    VALUES (:id_remision, :id_producto, :codigo, :descripcion, :cantidad, :unidad_medida, :precio_unitario, :id_factura_origen)
                ");
                foreach ($input['items'] as $item) {
                    $stmtItem->execute([
                        ':id_remision' => $id,
                        ':id_producto' => $item['id_producto'] ?? null,
                        ':codigo' => $item['codigo'] ?? '',
                        ':descripcion' => $item['descripcion'] ?? '',
                        ':cantidad' => $item['cantidad'] ?? 1,
                        ':unidad_medida' => $item['unidad_medida'] ?? '77',
                        ':precio_unitario' => $item['precio_unitario'] ?? 0,
                        ':id_factura_origen' => $item['id_factura_origen'] ?? null
                    ]);
                }
            }

            $pdo->commit();

            // Actualizar id_sucursal si está vacío (usar de variable global o de la solicitud)
            $idSucursal = $input['id_sucursal'] ?? $_SESSION['id_sucursal'] ?? null;
            if (!empty($idSucursal)) {
                $stmtSuc = $pdo->prepare("UPDATE {$empresaDb}.nota_remision SET id_sucursal = :id_sucursal WHERE id_remision = :id");
                $stmtSuc->execute([':id_sucursal' => $idSucursal, ':id' => $id]);
            }

            // Enviar a SIFEN automáticamente (modo lote)
            $sifenResult = null;
            $sifenError = null;
            try {
                ob_start();
                enviarSifen($pdo, $masterDb, $id, $id_empresa);
                $sifenOutput = ob_get_clean();
                $sifenResult = json_decode($sifenOutput, true);
            } catch (Exception $e) {
                $sifenError = $e->getMessage();
                error_log("Error enviando a SIFEN (update): " . $sifenError);
            }

            // Preparar respuesta con resultado de SIFEN
            $response = ['success' => true, 'sifen_resetted' => $hayChanges];

            if ($sifenResult) {
                $response['sifen'] = $sifenResult;
                if (!empty($sifenResult['success']) && !empty($sifenResult['cdc'])) {
                    $response['cdc'] = $sifenResult['cdc'];
                    $response['estado_sifen'] = 'Aprobado';
                    $response['message'] = $sifenResult['message'] ?? 'Nota de Remisión enviada y aprobada por SIFEN';
                } elseif (isset($sifenResult['error'])) {
                    $response['estado_sifen'] = 'Rechazado';
                    $response['sifen_error'] = $sifenResult['error'];
                }
            } elseif ($sifenError) {
                $response['sifen_error'] = $sifenError;
                $response['estado_sifen'] = 'Error';
            }

            echo json_encode($response);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'delete':
        // Eliminar nota de remisión (solo si está pendiente)
        $id = $_GET['id'] ?? $_POST['id'] ?? 0;

        $stmt = $pdo->prepare("DELETE FROM {$empresaDb}.nota_remision WHERE id_remision = :id AND id_empresa = :id_empresa AND estado_sifen = 'Pendiente'");
        $stmt->execute([':id' => $id, ':id_empresa' => $id_empresa]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No se puede eliminar. Puede que ya esté enviada a SIFEN.']);
        }
        break;

    case 'buscar_cliente':
        // Buscar cliente por RUC o nombre (devuelve uno solo)
        $term = $_GET['term'] ?? $_GET['ruc'] ?? '';

        if (strlen($term) < 2) {
            echo json_encode(['success' => true, 'data' => null]);
            exit;
        }

        // Obtener dbase de la empresa
        $stmtDb = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = :id");
        $stmtDb->execute([':id' => $id_empresa]);
        $dbName = $stmtDb->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, numero as ruc, nombre, direccion, ciudad, telefono, documento
            FROM {$dbName}.clientes 
            WHERE numero LIKE :term OR nombre LIKE :term2 OR documento LIKE :term3
            LIMIT 1
        ");
        $stmt->execute([':term' => "$term%", ':term2' => "%$term%", ':term3' => "$term%"]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente) {
            echo json_encode(['success' => true, 'data' => $cliente, 'source' => 'local']);
        } else {
            echo json_encode(['success' => true, 'data' => null, 'source' => 'local']);
        }
        break;

    case 'buscar_clientes':
        // Buscar clientes para autocompletado (devuelve lista)
        // Soporta búsqueda por múltiples palabras/pistas: "mig val" encuentra "Valdez, Miguel Angel"
        $term = $_GET['term'] ?? '';

        if (strlen($term) < 2) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        // Obtener dbase de la empresa
        $stmtDb = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = :id");
        $stmtDb->execute([':id' => $id_empresa]);
        $dbName = $stmtDb->fetchColumn();

        // Dividir término en palabras para búsqueda por pistas
        $palabras = preg_split('/\s+/', trim($term));
        $palabras = array_filter($palabras, fn($p) => strlen($p) >= 2);

        if (count($palabras) === 0) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        // Construir condiciones WHERE para cada palabra (todas deben coincidir)
        $whereConditions = [];
        $params = [];
        $i = 0;
        foreach ($palabras as $palabra) {
            $paramName = ":term{$i}";
            $whereConditions[] = "(nombre LIKE {$paramName} OR numero LIKE {$paramName} OR documento LIKE {$paramName})";
            $params[$paramName] = "%{$palabra}%";
            $i++;
        }

        $whereClause = implode(' AND ', $whereConditions);

        $stmt = $pdo->prepare("
            SELECT id, numero as ruc, nombre, direccion, ciudad, telefono, documento
            FROM {$dbName}.clientes 
            WHERE {$whereClause}
            ORDER BY nombre
            LIMIT 15
        ");
        $stmt->execute($params);
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $clientes]);
        break;

    case 'buscar_productos':
        // Buscar productos para autocompletado
        $term = $_GET['term'] ?? '';

        if (strlen($term) < 2) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        // Obtener dbase de la empresa
        $stmtDb = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = :id");
        $stmtDb->execute([':id' => $id_empresa]);
        $dbName = $stmtDb->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT 
                p.idproducto as id,
                p.cve_producto as codigo,
                p.desproducto as descripcion,
                p.referencia
            FROM {$dbName}.tblproductos p
            WHERE p.estado = '1' 
              AND (p.cve_producto LIKE :term1 OR p.desproducto LIKE :term2 OR p.referencia LIKE :term3)
            ORDER BY p.desproducto
            LIMIT 15
        ");
        $stmt->execute([':term1' => "%$term%", ':term2' => "%$term%", ':term3' => "%$term%"]);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $productos]);
        break;

    case 'buscar_facturas':
        // Buscar facturas para vincular a la remisión
        // Soporta búsqueda por múltiples palabras/pistas: "mig 001" encuentra facturas de Miguel con 001
        $term = $_GET['term'] ?? '';

        if (strlen($term) < 2) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        // Obtener dbase de la empresa
        $stmtDb = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = :id");
        $stmtDb->execute([':id' => $id_empresa]);
        $dbName = $stmtDb->fetchColumn();

        if (!$dbName) {
            echo json_encode(['success' => false, 'error' => 'Base de datos de empresa no encontrada']);
            exit;
        }

        // Dividir término en palabras para búsqueda por pistas
        $palabras = preg_split('/\s+/', trim($term));
        $palabras = array_filter($palabras, fn($p) => strlen($p) >= 2);

        if (count($palabras) === 0) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        // Construir condiciones WHERE para cada palabra (todas deben coincidir en algún campo)
        $whereConditions = [];
        $params = [];
        $i = 0;
        foreach ($palabras as $palabra) {
            $paramName = ":term{$i}";
            $whereConditions[] = "(fv.nro_factura LIKE {$paramName} OR c.nombre LIKE {$paramName} OR c.numero LIKE {$paramName} OR fv.cdc LIKE {$paramName})";
            $params[$paramName] = "%{$palabra}%";
            $i++;
        }

        $whereClause = implode(' AND ', $whereConditions);

        // Buscar facturas por número, cliente o CDC
        $stmt = $pdo->prepare("
            SELECT 
                fv.id_factura,
                fv.nro_factura,
                fv.fecha,
                fv.total,
                fv.estado,
                fv.cdc,
                fv.estado_sifen,
                c.nombre as nombre_cliente,
                c.numero as ruc_cliente
            FROM {$dbName}.factura_ventas fv
            LEFT JOIN {$dbName}.clientes c ON c.id = fv.id_cliente
            WHERE fv.estado = 1 
              AND ({$whereClause})
            ORDER BY fv.fecha DESC, fv.id_factura DESC
            LIMIT 15
        ");
        $stmt->execute($params);
        $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Para cada factura, obtener sus items desde extracto_productos
        foreach ($facturas as &$factura) {
            $items = [];

            try {
                $stmtItems = $pdo->prepare("
                    SELECT 
                        ep.*,
                        COALESCE(p.cve_producto, '') as prod_codigo,
                        COALESCE(p.desproducto, '') as prod_descripcion
                    FROM {$dbName}.extracto_productos ep
                    LEFT JOIN {$dbName}.tblproductos p ON p.idproducto = ep.idproducto
                    WHERE ep.idfactura = :id_factura
                ");
                $stmtItems->execute([':id_factura' => $factura['id_factura']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Tabla no existe o error, items queda vacío
                $items = [];
            }

            // Procesar items para normalizar estructura
            $factura['items'] = array_map(function ($item) {
                // Intentar obtener código de múltiples fuentes
                $codigo = $item['prod_codigo'] ?? '';
                if (empty($codigo)) $codigo = $item['codigo'] ?? '';
                if (empty($codigo)) $codigo = $item['cve_producto'] ?? '';

                // Intentar obtener descripción de múltiples fuentes
                $descripcion = $item['prod_descripcion'] ?? '';
                if (empty($descripcion)) $descripcion = $item['descripcion'] ?? '';
                if (empty($descripcion)) $descripcion = $item['desproducto'] ?? '';
                if (empty($descripcion)) $descripcion = $item['producto'] ?? '';
                if (empty($descripcion)) $descripcion = $item['concepto'] ?? '';

                // Cantidad: usar 'salida' para ventas, o 'cantidad' si existe
                $cantidad = floatval($item['salida'] ?? $item['cantidad'] ?? 1);

                return [
                    'id_producto' => $item['idproducto'] ?? $item['id_producto'] ?? null,
                    'codigo' => $codigo,
                    'descripcion' => $descripcion,
                    'cantidad' => $cantidad,
                    'unidad_medida' => $item['unidad'] ?? $item['unidad_medida'] ?? '77'
                ];
            }, $items);
        }
        unset($factura); // Romper referencia

        echo json_encode(['success' => true, 'data' => $facturas]);
        break;

    case 'guardar_cliente':
        // Guardar nuevo cliente si no existe
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }

        $ruc = trim($input['ruc'] ?? '');
        $nombre = trim($input['nombre'] ?? '');
        $direccion = trim($input['direccion'] ?? '');
        $documento = trim($input['documento'] ?? '');
        $telefono = trim($input['telefono'] ?? '');

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'El nombre es requerido']);
            exit;
        }

        // Obtener dbase de la empresa
        $stmtDb = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = :id");
        $stmtDb->execute([':id' => $id_empresa]);
        $empData = $stmtDb->fetch(PDO::FETCH_ASSOC);

        if (!$empData || empty($empData['dbase'])) {
            echo json_encode(['success' => false, 'error' => 'Empresa no encontrada o sin base de datos configurada']);
            exit;
        }
        $dbName = $empData['dbase'];

        // Verificar si ya existe por RUC o documento
        $existe = false;
        if (!empty($ruc)) {
            $stmtCheck = $pdo->prepare("SELECT id FROM {$dbName}.clientes WHERE numero = :ruc LIMIT 1");
            $stmtCheck->execute([':ruc' => $ruc]);
            $existe = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        }
        if (!$existe && !empty($documento)) {
            $stmtCheck = $pdo->prepare("SELECT id FROM {$dbName}.clientes WHERE documento = :doc LIMIT 1");
            $stmtCheck->execute([':doc' => $documento]);
            $existe = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        }

        if ($existe) {
            echo json_encode(['success' => true, 'id' => $existe['id'], 'message' => 'Cliente ya existe', 'exists' => true]);
            exit;
        }

        try {
            // Generar llave única
            $llave = uniqid() . '.' . ($ruc ?: time());

            $stmt = $pdo->prepare("
                INSERT INTO {$dbName}.clientes 
                (sucursal, cuenta, fecha, documento, numero, nombre, direccion, pais, ciudad, estado, llave, raiting, telefono)
                VALUES (1, 1, CURDATE(), :documento, :ruc, :nombre, :direccion, 1, 1, 1, :llave, 1, :telefono)
            ");
            $stmt->execute([
                ':ruc' => $ruc,
                ':documento' => $documento ?: '11',
                ':nombre' => $nombre,
                ':direccion' => $direccion,
                ':telefono' => $telefono,
                ':llave' => $llave
            ]);
            $newId = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Cliente agregado', 'exists' => false]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'consultar_ruc_sifen':
        // Consultar RUC en SIFEN
        $ruc = $_GET['ruc'] ?? '';
        $ruc = preg_replace('/[^0-9]/', '', $ruc);

        if (strlen($ruc) < 3) {
            echo json_encode(['success' => false, 'error' => 'RUC muy corto']);
            exit;
        }

        try {
            // Cargar librería SIFEN
            $libPath = __DIR__ . '/_lib/php-sifen3/src/soap-sifen.php';
            if (!file_exists($libPath)) {
                throw new Exception("Librería SIFEN no encontrada en _lib/php-sifen3/src/soap-sifen.php");
            }
            require_once $libPath;

            // Obtener ambiente de empresa (para referencia, pero usamos prod para consultas RUC)
            $stmtEmp = $pdo->prepare("SELECT ambiente_sifen FROM {$masterDb}.empresa WHERE id_empresa = :id");
            $stmtEmp->execute([':id' => $id_empresa]);
            $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

            // IMPORTANTE: Consultas de RUC siempre van a PRODUCCIÓN (sifen.set.gov.py)
            // El ambiente test no soporta consultas de RUC
            $ambiente = 'prod';

            // Usar certificado fijo para consultas RUC
            $certNombreFijo = '80118689.p12';
            $certPassFijo = '3nvcEcwW';

            $certCandidates = [
                __DIR__ . '/_lib/php-sifen3/certificados/' . $certNombreFijo,
                __DIR__ . '/_lib/php-sifen3/src/certificados/' . $certNombreFijo,
                __DIR__ . '/_lib/sifen/certificados/' . $certNombreFijo,
                __DIR__ . '/_lib/certificados/' . $certNombreFijo,
                __DIR__ . '/certificados/' . $certNombreFijo
            ];

            $certPath = '';
            foreach ($certCandidates as $cand) {
                if (file_exists($cand)) {
                    $certPath = $cand;
                    break;
                }
            }

            if (empty($certPath)) {
                throw new Exception("Certificado no encontrado para consulta SIFEN");
            }

            $pkcs12Content = file_get_contents($certPath);
            if (!openssl_pkcs12_read($pkcs12Content, $certs, $certPassFijo)) {
                throw new Exception("Error leyendo certificado PKCS12");
            }

            $pemContent = $certs['pkey'] . "\n" . $certs['cert'] . "\n";
            if (!empty($certs['extracerts'])) {
                foreach ($certs['extracerts'] as $extra) {
                    $pemContent .= $extra . "\n";
                }
            }

            $client = new SifenWSClient($ambiente);
            $client->setCertificateFromString($pemContent);
            $client->setPassphrase($certPassFijo);

            $resSifen = $client->consulta('rEnviConsRUC', ['ruc' => $ruc]);

            if ($resSifen['status'] === 'ok') {
                $cleanXml = preg_replace('/<(\/?)[\w\d]+:(\w+)/', '<\1\2', $resSifen['response']);
                $xmlRes = new SimpleXMLElement($cleanXml);

                $dCodRes = (string)($xmlRes->xpath('//dCodRes')[0] ?? '');

                if ($dCodRes === '0502') {
                    $xContr = $xmlRes->xpath('//xContRUC')[0] ?? $xmlRes->xpath('//xContr')[0] ?? null;

                    if ($xContr) {
                        $nombre = trim((string)($xContr->dRazCons ?? $xContr->dRazSoc ?? ''));
                        $rucBase = trim((string)($xContr->dRUCCons ?? $xContr->dRUC ?? ''));
                        $dv = trim((string)($xContr->dDV ?? ''));

                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'ruc' => $rucBase,
                                'dv' => $dv,
                                'nombre' => $nombre,
                                'direccion' => ''
                            ],
                            'source' => 'sifen'
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Contribuyente no encontrado en SIFEN']);
                    }
                } else {
                    $mensaje = (string)($xmlRes->xpath('//dMsgRes')[0] ?? 'RUC no encontrado');
                    echo json_encode(['success' => false, 'error' => $mensaje]);
                }
            } else {
                throw new Exception($resSifen['error'] ?? 'Error consultando SIFEN');
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
}
