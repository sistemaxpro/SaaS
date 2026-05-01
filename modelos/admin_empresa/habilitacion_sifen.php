<?php

/**
 * Gestión de Habilitaciones SIFEN por Empresa
 * Interfaz para administrar los datos del Form.364-3 de DNIT
 * 
 * API integrada - endpoints via ?action=
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;
$masterDb = 'serproc1';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

if (!defined('SISTEMAX_V1')) {
    define('SISTEMAX_V1', true);
}
require_once dirname(__DIR__, 2) . '/config/database.php';

// Tipos de documento SIFEN
$tiposDocumento = [
    1 => 'Factura Electrónica',
    4 => 'Autofactura Electrónica',
    5 => 'Nota de Crédito Electrónica',
    6 => 'Nota de Débito Electrónica',
    7 => 'Nota de Remisión Electrónica'
];

// Conexión PDO
try {
    $pdo = Database::getMasterConnection();

    // Crear tablas si no existen
    crearTablasHabilitacionSifen($pdo, $masterDb);
} catch (Throwable $e) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        $out = ['success' => false, 'error' => 'Error de conexión'];
        if ($debug) {
            $out['debug'] = $e->getMessage();
        }
        die(json_encode($out));
    }
    http_response_code(500);
    echo $debug ? ("Error de conexión: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')) : "Error de conexión";
    exit;
}

/**
 * Crear tablas de habilitación SIFEN si no existen
 */
function crearTablasHabilitacionSifen($pdo, $masterDb)
{
    try {
        // Tabla: habilitacion_sifen_sucursales
        // Relaciona sucursales con establecimientos SIFEN
        $sql = "CREATE TABLE IF NOT EXISTS {$masterDb}.habilitacion_sifen_sucursales (
            id INT(11) NOT NULL AUTO_INCREMENT,
            id_habilitacion INT(11) NOT NULL,
            id_sucursal INT(11) NOT NULL,
            codigo_establecimiento VARCHAR(3) NOT NULL DEFAULT '001',
            punto_expedicion_defecto VARCHAR(3) NOT NULL DEFAULT '001',
            descripcion VARCHAR(255),
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_habilitacion_sucursal (id_habilitacion, id_sucursal),
            UNIQUE KEY unique_establecimiento (id_habilitacion, codigo_establecimiento),
            KEY idx_id_habilitacion (id_habilitacion),
            KEY idx_id_sucursal (id_sucursal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);

        // Tabla: habilitacion_sifen_puntos_expedicion
        // Múltiples puntos de expedición (cajas/POS) por sucursal
        $sql = "CREATE TABLE IF NOT EXISTS {$masterDb}.habilitacion_sifen_puntos_expedicion (
            id INT(11) NOT NULL AUTO_INCREMENT,
            id_habilitacion_sucursal INT(11) NOT NULL,
            codigo_punto VARCHAR(3) NOT NULL,
            descripcion VARCHAR(255),
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_punto (id_habilitacion_sucursal, codigo_punto),
            KEY idx_id_habilitacion_sucursal (id_habilitacion_sucursal),
            FOREIGN KEY (id_habilitacion_sucursal) REFERENCES {$masterDb}.habilitacion_sifen_sucursales(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);

        // Tabla: cajas asignadas por documento habilitado
        $sql = "CREATE TABLE IF NOT EXISTS {$masterDb}.habilitacion_sifen_documentos_cajas (
            id INT(11) NOT NULL AUTO_INCREMENT,
            id_documento INT(11) NOT NULL,
            caja VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_doc_caja (id_documento, caja),
            KEY idx_id_documento (id_documento),
            CONSTRAINT fk_hsdc_documento FOREIGN KEY (id_documento) REFERENCES {$masterDb}.habilitacion_sifen_documentos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // Log error pero no detiene la aplicación
        error_log("Error creando tablas de habilitación SIFEN: " . $e->getMessage());
    }
}

function anexarCajasADocumentos($pdo, $masterDb, &$documentos)
{
    if (empty($documentos)) return;
    try {
        $stmtCajas = $pdo->prepare("SELECT caja FROM {$masterDb}.habilitacion_sifen_documentos_cajas WHERE id_documento = ? ORDER BY caja ASC");
        foreach ($documentos as &$doc) {
            $stmtCajas->execute([(int)($doc['id'] ?? 0)]);
            $doc['cajas'] = array_map(static function ($r) {
                return (string)($r['caja'] ?? '');
            }, $stmtCajas->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (Throwable $e) {
        foreach ($documentos as &$doc) {
            if (!isset($doc['cajas']) || !is_array($doc['cajas'])) {
                $doc['cajas'] = [];
            }
        }
    }
}

// ======================= API ENDPOINTS =======================
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $projectRoot = dirname(__DIR__, 2);
    $certDirPublic = $projectRoot . '/public/_lib/php-sifen3/certificados/';
    $certDirCustom = $projectRoot . '/public/_lib/php-sifen3-custom/certificados/';

    switch ($action) {
        case 'list':
            $sql = "SELECT h.*, e.empresa as nombre_empresa, e.logos
                    FROM {$masterDb}.habilitacion_sifen h
                    LEFT JOIN {$masterDb}.empresa e ON h.id_empresa = e.id_empresa
                    ORDER BY h.id DESC";
            $stmt = $pdo->query($sql);
            $habilitaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($habilitaciones as &$hab) {
                $stmt2 = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_documentos WHERE id_habilitacion = ?");
                $stmt2->execute([$hab['id']]);
                $hab['documentos'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                anexarCajasADocumentos($pdo, $masterDb, $hab['documentos']);
            }
            echo json_encode(['success' => true, 'data' => $habilitaciones]);
            exit;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT h.*, e.empresa as nombre_empresa FROM {$masterDb}.habilitacion_sifen h LEFT JOIN {$masterDb}.empresa e ON h.id_empresa = e.id_empresa WHERE h.id = ?");
            $stmt->execute([$id]);
            $hab = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($hab) {
                $stmt2 = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_documentos WHERE id_habilitacion = ?");
                $stmt2->execute([$id]);
                $hab['documentos'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                anexarCajasADocumentos($pdo, $masterDb, $hab['documentos']);
            }
            echo json_encode(['success' => true, 'data' => $hab]);
            exit;

        case 'get_by_empresa':
            $idEmpresa = (int)($_GET['id_empresa'] ?? 0);
            $stmt = $pdo->prepare("SELECT h.*, e.empresa as nombre_empresa FROM {$masterDb}.habilitacion_sifen h LEFT JOIN {$masterDb}.empresa e ON h.id_empresa = e.id_empresa WHERE h.id_empresa = ? ORDER BY h.id DESC LIMIT 1");
            $stmt->execute([$idEmpresa]);
            $hab = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($hab) {
                $stmt2 = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_documentos WHERE id_habilitacion = ?");
                $stmt2->execute([$hab['id']]);
                $hab['documentos'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                anexarCajasADocumentos($pdo, $masterDb, $hab['documentos']);
            }
            echo json_encode(['success' => true, 'data' => $hab]);
            exit;

        case 'empresas':
            $sql = "SELECT id_empresa, empresa, ruc, dv, timbrado, activo FROM {$masterDb}.empresa WHERE activo = 1 ORDER BY empresa";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        case 'create':
        case 'update':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($_GET['id'] ?? $data['id'] ?? 0);
            $certPathBase = rtrim($projectRoot, '/') . '/public/_lib/php-sifen3/certificados/';
            $certNombreRaw = trim((string)($data['cert_nombre'] ?? ''));
            if ($certNombreRaw === '') {
                $certNombreRaw = trim((string)($data['cert_path'] ?? ''));
            }
            $certNombreNormalizado = $certNombreRaw !== '' ? basename($certNombreRaw) : null;
            $certPathNormalizado = $certNombreNormalizado ? ($certPathBase . $certNombreNormalizado) : null;

            $actividades = !empty($data['actividades_economicas'])
                ? (is_string($data['actividades_economicas']) ? $data['actividades_economicas'] : json_encode($data['actividades_economicas']))
                : null;

            if ($action === 'create') {
                $sql = "INSERT INTO {$masterDb}.habilitacion_sifen (
                    id_empresa, empresa, numero_formulario, fecha_formulario, ruc, dv, razon_social, nombre_fantasia, estado_contribuyente,
                    representante_tipo_doc, representante_documento, representante_nombre,
                    cod_depto, departamento, cod_distrito, distrito, cod_ciudad, localidad, cod_barrio, barrio, 
                    direccion, numero_casa, telefono, email,
                    actividades_economicas, sistema_contribuyente, numero_timbrado, fecha_inicio_vigencia, fecha_fin_vigencia, estado_timbrado,
                    csc, id_csc, cert_nombre, cert_path, cert_pass, cert_fecha_vencimiento, ambiente, activo
                ) VALUES (
                    :id_empresa, :empresa, :numero_formulario, :fecha_formulario, :ruc, :dv, :razon_social, :nombre_fantasia, :estado_contribuyente,
                    :representante_tipo_doc, :representante_documento, :representante_nombre,
                    :cod_depto, :departamento, :cod_distrito, :distrito, :cod_ciudad, :localidad, :cod_barrio, :barrio, 
                    :direccion, :numero_casa, :telefono, :email,
                    :actividades_economicas, :sistema_contribuyente, :numero_timbrado, :fecha_inicio_vigencia, :fecha_fin_vigencia, :estado_timbrado,
                    :csc, :id_csc, :cert_nombre, :cert_path, :cert_pass, :cert_fecha_vencimiento, :ambiente, :activo
                )";
            } else {
                $sql = "UPDATE {$masterDb}.habilitacion_sifen SET
                    empresa = :empresa, numero_formulario = :numero_formulario, fecha_formulario = :fecha_formulario, ruc = :ruc, dv = :dv, 
                    razon_social = :razon_social, nombre_fantasia = :nombre_fantasia, estado_contribuyente = :estado_contribuyente,
                    representante_tipo_doc = :representante_tipo_doc, representante_documento = :representante_documento, representante_nombre = :representante_nombre,
                    cod_depto = :cod_depto, departamento = :departamento, cod_distrito = :cod_distrito, distrito = :distrito, 
                    cod_ciudad = :cod_ciudad, localidad = :localidad, cod_barrio = :cod_barrio, barrio = :barrio, 
                    direccion = :direccion, numero_casa = :numero_casa, telefono = :telefono, email = :email,
                    actividades_economicas = :actividades_economicas, sistema_contribuyente = :sistema_contribuyente,
                    numero_timbrado = :numero_timbrado, fecha_inicio_vigencia = :fecha_inicio_vigencia, fecha_fin_vigencia = :fecha_fin_vigencia, estado_timbrado = :estado_timbrado,
                    csc = :csc, id_csc = :id_csc, cert_nombre = :cert_nombre, cert_path = :cert_path, cert_pass = :cert_pass, cert_fecha_vencimiento = :cert_fecha_vencimiento,
                    ambiente = :ambiente, activo = :activo, updated_at = NOW()
                    WHERE id = :id";
            }

            $params = [
                ':empresa' => $data['empresa'] ?? null,
                ':numero_formulario' => $data['numero_formulario'] ?? null,
                ':fecha_formulario' => $data['fecha_formulario'] ?: null,
                ':ruc' => $data['ruc'] ?? '',
                ':dv' => $data['dv'] ?? 0,
                ':razon_social' => $data['razon_social'] ?? '',
                ':nombre_fantasia' => $data['nombre_fantasia'] ?? null,
                ':estado_contribuyente' => $data['estado_contribuyente'] ?? 'ACTIVO',
                ':representante_tipo_doc' => $data['representante_tipo_doc'] ?? 'CI',
                ':representante_documento' => $data['representante_documento'] ?? null,
                ':representante_nombre' => $data['representante_nombre'] ?? null,
                ':cod_depto' => $data['cod_depto'] ?: null,
                ':departamento' => $data['departamento'] ?? null,
                ':cod_distrito' => $data['cod_distrito'] ?: null,
                ':distrito' => $data['distrito'] ?? null,
                ':cod_ciudad' => $data['cod_ciudad'] ?: null,
                ':localidad' => $data['localidad'] ?? null,
                ':cod_barrio' => $data['cod_barrio'] ?: null,
                ':barrio' => $data['barrio'] ?? null,
                ':direccion' => $data['direccion'] ?? null,
                ':numero_casa' => $data['numero_casa'] ?? null,
                ':telefono' => $data['telefono'] ?? null,
                ':email' => $data['email'] ?? null,
                ':actividades_economicas' => $actividades,
                ':sistema_contribuyente' => $data['sistema_contribuyente'] ?? null,
                ':numero_timbrado' => $data['numero_timbrado'] ?? '',
                ':fecha_inicio_vigencia' => $data['fecha_inicio_vigencia'] ?: null,
                ':fecha_fin_vigencia' => $data['fecha_fin_vigencia'] ?: null,
                ':estado_timbrado' => $data['estado_timbrado'] ?? 'ACTIVO',
                ':csc' => $data['csc'] ?? null,
                ':id_csc' => $data['id_csc'] ?? null,
                ':cert_nombre' => $certNombreNormalizado,
                ':cert_path' => $certPathNormalizado,
                ':cert_pass' => $data['cert_pass'] ?? null,
                ':cert_fecha_vencimiento' => $data['cert_fecha_vencimiento'] ?: null,
                ':ambiente' => $data['ambiente'] ?? 'TEST',
                ':activo' => $data['activo'] ?? 1
            ];

            if ($action === 'create') {
                $params[':id_empresa'] = $data['id_empresa'];
            } else {
                $params[':id'] = $id;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $newId = $action === 'create' ? $pdo->lastInsertId() : $id;

            // Sincronizar con tabla empresa
            $idEmp = $action === 'create' ? $data['id_empresa'] : null;
            if (!$idEmp && $id) {
                $stmt = $pdo->prepare("SELECT id_empresa FROM {$masterDb}.habilitacion_sifen WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                $idEmp = $row['id_empresa'] ?? null;
            }
            if ($idEmp) {
                $updates = [];
                $syncParams = [':id' => $idEmp];
                if (!empty($data['csc'])) {
                    $updates[] = "csc = :csc";
                    $syncParams[':csc'] = $data['csc'];
                }
                if (!empty($data['id_csc'])) {
                    $updates[] = "id_csc = :id_csc";
                    $syncParams[':id_csc'] = $data['id_csc'];
                }
                if (!empty($data['numero_timbrado'])) {
                    $updates[] = "timbrado = :timbrado";
                    $syncParams[':timbrado'] = $data['numero_timbrado'];
                }
                if (!empty($data['fecha_inicio_vigencia'])) {
                    $updates[] = "timbradoFecha = :timbradoFecha";
                    $syncParams[':timbradoFecha'] = $data['fecha_inicio_vigencia'];
                }
                if (!empty($data['cert_pass'])) {
                    $updates[] = "cert_pass = :cert_pass, password_certificado = :password_certificado";
                    $syncParams[':cert_pass'] = $data['cert_pass'];
                    $syncParams[':password_certificado'] = $data['cert_pass'];
                }
                if (!empty($data['ambiente'])) {
                    $updates[] = "ambiente_sifen = :ambiente";
                    $syncParams[':ambiente'] = $data['ambiente'];
                }
                if (!empty($updates)) {
                    $pdo->prepare("UPDATE {$masterDb}.empresa SET " . implode(', ', $updates) . " WHERE id_empresa = :id")->execute($syncParams);
                }
            }

            echo json_encode(['success' => true, 'id' => $newId, 'message' => $action === 'create' ? 'Creado' : 'Actualizado']);
            exit;

        case 'delete':
            $id = (int)($_GET['id'] ?? 0);
            $pdo->prepare("DELETE FROM {$masterDb}.habilitacion_sifen WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Eliminado']);
            exit;

        case 'documentos':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_documentos WHERE id_habilitacion = ?");
            $stmt->execute([$id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            anexarCajasADocumentos($pdo, $masterDb, $documentos);
            echo json_encode(['success' => true, 'data' => $documentos]);
            exit;

        case 'add_documento':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $tipoDoc = (int)$data['tipo_documento'];
            $nombreTipo = $tiposDocumento[$tipoDoc] ?? 'Desconocido';
            $sql = "INSERT INTO {$masterDb}.habilitacion_sifen_documentos 
                    (id_habilitacion, codigo_establecimiento, punto_expedicion, tipo_documento, tipo_documento_nombre, activo)
                    VALUES (:id_hab, :est, :punto, :tipo, :nombre, 1)
                    ON DUPLICATE KEY UPDATE activo = 1";
            $pdo->prepare($sql)->execute([
                ':id_hab' => $data['id_habilitacion'],
                ':est' => $data['codigo_establecimiento'] ?? '001',
                ':punto' => $data['punto_expedicion'] ?? '001',
                ':tipo' => $tipoDoc,
                ':nombre' => $nombreTipo
            ]);
            echo json_encode(['success' => true]);
            exit;

        case 'remove_documento':
            $id = (int)($_GET['id'] ?? 0);
            $pdo->prepare("DELETE FROM {$masterDb}.habilitacion_sifen_documentos_cajas WHERE id_documento = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM {$masterDb}.habilitacion_sifen_documentos WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            exit;

        case 'add_documento_caja':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $idDocumento = (int)($data['id_documento'] ?? 0);
            $caja = trim((string)($data['caja'] ?? ''));
            $caja = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $caja), 0, 20);
            if ($idDocumento <= 0 || $caja === '') {
                echo json_encode(['success' => false, 'error' => 'Documento o caja inválido']);
                exit;
            }
            $sql = "INSERT INTO {$masterDb}.habilitacion_sifen_documentos_cajas (id_documento, caja)
                    VALUES (:id_documento, :caja)
                    ON DUPLICATE KEY UPDATE caja = VALUES(caja)";
            $pdo->prepare($sql)->execute([
                ':id_documento' => $idDocumento,
                ':caja' => $caja
            ]);
            echo json_encode(['success' => true]);
            exit;

        case 'remove_documento_caja':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $idDocumento = (int)($data['id_documento'] ?? 0);
            $caja = trim((string)($data['caja'] ?? ''));
            if ($idDocumento <= 0 || $caja === '') {
                echo json_encode(['success' => false, 'error' => 'Documento o caja inválido']);
                exit;
            }
            $pdo->prepare("DELETE FROM {$masterDb}.habilitacion_sifen_documentos_cajas WHERE id_documento = ? AND caja = ?")->execute([$idDocumento, $caja]);
            echo json_encode(['success' => true]);
            exit;

        case 'list_cajas_empresa':
            $idEmpresa = (int)($_GET['id_empresa'] ?? ($_SESSION['id_empresa'] ?? 0));
            if ($idEmpresa <= 0) {
                echo json_encode(['success' => false, 'error' => 'Empresa inválida']);
                exit;
            }

            // Obtener configuración de empresa/DB
            $empresaStmt = null;
            try {
                $empresaStmt = $pdo->prepare("SELECT dbase, server, user, password, puerto FROM {$masterDb}.empresa WHERE id_empresa = ? LIMIT 1");
                $empresaStmt->execute([$idEmpresa]);
            } catch (Throwable $e) {
                $empresaStmt = $pdo->prepare("SELECT dbase, server, user, password FROM {$masterDb}.empresa WHERE id_empresa = ? LIMIT 1");
                $empresaStmt->execute([$idEmpresa]);
            }
            $empresa = $empresaStmt->fetch(PDO::FETCH_ASSOC);
            if (!$empresa || empty($empresa['dbase'])) {
                echo json_encode(['success' => false, 'error' => 'Empresa sin base de datos configurada']);
                exit;
            }

            $dbase = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$empresa['dbase']);

            try {
                $pdoEmpresa = Database::getEmpresaConnection($idEmpresa);

                // Validar existencia de tabla cajas
                $stmtTabla = $pdoEmpresa->query("SHOW TABLES LIKE 'cajas'");
                if (!$stmtTabla->fetchColumn()) {
                    echo json_encode(['success' => true, 'data' => []]);
                    exit;
                }

                $sqlCajas = "SELECT id_caja, caja, factura_1, factura_2 FROM {$dbase}.cajas ORDER BY id_caja ASC";
                $rows = $pdoEmpresa->query($sqlCajas)->fetchAll(PDO::FETCH_ASSOC);
                $out = array_map(static function ($r) {
                    $idCaja = (string)($r['id_caja'] ?? '');
                    $nombre = trim((string)($r['caja'] ?? ''));
                    $est = str_pad((string)((int)($r['factura_1'] ?? 0)), 3, '0', STR_PAD_LEFT);
                    $pto = str_pad((string)((int)($r['factura_2'] ?? 0)), 3, '0', STR_PAD_LEFT);
                    return [
                        'id' => $idCaja,
                        'nombre' => $nombre,
                        'factura_1' => $est,
                        'factura_2' => $pto,
                        'label' => $nombre !== '' ? ("#{$idCaja} - {$nombre} ({$est}-{$pto})") : ("#{$idCaja} ({$est}-{$pto})")
                    ];
                }, $rows ?: []);

                echo json_encode(['success' => true, 'data' => $out]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => 'No se pudieron cargar cajas de la empresa']);
            }
            exit;

        case 'actividades':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_actividades WHERE id_habilitacion = ? AND activo = 1 ORDER BY principal DESC, codigo");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        case 'add_actividad':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $sql = "INSERT INTO {$masterDb}.habilitacion_sifen_actividades 
                    (id_habilitacion, codigo, descripcion, principal, activo)
                    VALUES (:id_hab, :codigo, :desc, :principal, 1)
                    ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), principal = VALUES(principal), activo = 1";
            $pdo->prepare($sql)->execute([
                ':id_hab' => $data['id_habilitacion'],
                ':codigo' => $data['codigo'],
                ':desc' => $data['descripcion'] ?? '',
                ':principal' => $data['principal'] ?? 0
            ]);
            echo json_encode(['success' => true]);
            exit;

        case 'remove_actividad':
            $id = (int)($_GET['id'] ?? 0);
            $pdo->prepare("DELETE FROM {$masterDb}.habilitacion_sifen_actividades WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            exit;

        case 'solicitudes':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_solicitudes WHERE id_habilitacion = ? ORDER BY fecha_solicitud DESC");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        case 'add_solicitud':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $tipoDoc = (int)$data['tipo_documento'];
            $nombreTipo = $tiposDocumento[$tipoDoc] ?? 'Desconocido';
            $sql = "INSERT INTO {$masterDb}.habilitacion_sifen_solicitudes 
                    (id_habilitacion, tipo_documento, tipo_documento_nombre, codigo_establecimiento, punto_expedicion, 
                     numero_desde, numero_hasta, fecha_solicitud, fecha_aprobacion, numero_resolucion, estado, observaciones)
                    VALUES (:id_hab, :tipo, :nombre, :est, :punto, :desde, :hasta, :fecha_sol, :fecha_apr, :resolucion, :estado, :obs)";
            $pdo->prepare($sql)->execute([
                ':id_hab' => $data['id_habilitacion'],
                ':tipo' => $tipoDoc,
                ':nombre' => $nombreTipo,
                ':est' => $data['codigo_establecimiento'] ?? '001',
                ':punto' => $data['punto_expedicion'] ?? '001',
                ':desde' => $data['numero_desde'] ?? 1,
                ':hasta' => $data['numero_hasta'] ?: null,
                ':fecha_sol' => $data['fecha_solicitud'] ?: null,
                ':fecha_apr' => $data['fecha_aprobacion'] ?: null,
                ':resolucion' => $data['numero_resolucion'] ?? null,
                ':estado' => $data['estado'] ?? 'PENDIENTE',
                ':obs' => $data['observaciones'] ?? null
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;

        case 'update_solicitud':
            $id = (int)($_GET['id'] ?? 0);
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $sql = "UPDATE {$masterDb}.habilitacion_sifen_solicitudes SET
                    fecha_aprobacion = :fecha_apr, numero_resolucion = :resolucion, estado = :estado, observaciones = :obs
                    WHERE id = :id";
            $pdo->prepare($sql)->execute([
                ':id' => $id,
                ':fecha_apr' => $data['fecha_aprobacion'] ?: null,
                ':resolucion' => $data['numero_resolucion'] ?? null,
                ':estado' => $data['estado'] ?? 'PENDIENTE',
                ':obs' => $data['observaciones'] ?? null
            ]);
            echo json_encode(['success' => true]);
            exit;

        case 'remove_solicitud':
            $id = (int)($_GET['id'] ?? 0);
            $pdo->prepare("DELETE FROM {$masterDb}.habilitacion_sifen_solicitudes WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            exit;

        case 'upload_certificado':
            // Subir archivo de certificado (.p12, .pfx) a la carpeta de certificados
            $uploadDir = $certDirPublic;

            // Crear directorio si no existe
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            if (!is_dir($uploadDir)) {
                echo json_encode(['success' => false, 'error' => 'No se pudo crear carpeta de certificados: ' . $uploadDir]);
                exit;
            }
            if (!is_writable($uploadDir)) {
                echo json_encode(['success' => false, 'error' => 'Sin permisos de escritura en: ' . $uploadDir]);
                exit;
            }

            if (!isset($_FILES['certificado']) || $_FILES['certificado']['error'] !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido',
                    UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo del formulario',
                    UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
                    UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
                    UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal',
                    UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo',
                    UPLOAD_ERR_EXTENSION => 'Extensión de PHP detuvo la subida'
                ];
                $errCode = $_FILES['certificado']['error'] ?? UPLOAD_ERR_NO_FILE;
                echo json_encode(['success' => false, 'error' => $errorMessages[$errCode] ?? 'Error desconocido']);
                exit;
            }

            $file = $_FILES['certificado'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // Validar extensión
            if (!in_array($extension, ['p12', 'pfx'])) {
                echo json_encode(['success' => false, 'error' => 'Solo se permiten archivos .p12 o .pfx']);
                exit;
            }

            // Validar tamaño (máximo 500KB)
            if ($file['size'] > 512000) {
                echo json_encode(['success' => false, 'error' => 'El archivo no puede superar 500KB']);
                exit;
            }

            // Generar nombre único o usar el original
            $nombreArchivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $file['name']);
            $destino = $uploadDir . $nombreArchivo;

            // Si ya existe, move_uploaded_file lo sobrescribe directamente
            if (file_exists($destino) && !is_writable($destino)) {
                echo json_encode(['success' => false, 'error' => 'El certificado existente no tiene permisos de escritura']);
                exit;
            }

            if (move_uploaded_file($file['tmp_name'], $destino)) {
                chmod($destino, 0644);
                echo json_encode([
                    'success' => true,
                    'filename' => $nombreArchivo,
                    'path' => 'public/_lib/php-sifen3/certificados/' . $nombreArchivo,
                    'message' => 'Certificado subido correctamente (sobrescribe si existe)'
                ]);
            } else {
                $tmp = $file['tmp_name'] ?? '';
                $extra = $tmp ? (' tmp=' . $tmp) : '';
                echo json_encode(['success' => false, 'error' => 'Error al mover el archivo a ' . $destino . $extra]);
            }
            exit;

        case 'list_certificados':
            // Listar certificados disponibles
            $certDir = $certDirPublic;
            $certificados = [];
            if (is_dir($certDir)) {
                $files = scandir($certDir);
                foreach ($files as $file) {
                    if (preg_match('/\.(p12|pfx)$/i', $file)) {
                        $certificados[] = [
                            'nombre' => $file,
                            'fecha' => date('Y-m-d H:i:s', filemtime($certDir . $file)),
                            'size' => filesize($certDir . $file)
                        ];
                    }
                }
            }
            echo json_encode(['success' => true, 'data' => $certificados]);
            exit;

        case 'validar_cert_pass':
            // Validar contraseña de certificado
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $certNombre = trim($data['cert_nombre'] ?? '');
            $certPass = (string)($data['cert_pass'] ?? '');

            if (empty($certNombre)) {
                echo json_encode(['success' => false, 'error' => 'Debe seleccionar un certificado']);
                exit;
            }

            // Configurar OpenSSL legacy si aplica
            $legacyCnf = __DIR__ . '/openssl-legacy.cnf';
            if (file_exists($legacyCnf)) {
                putenv('OPENSSL_CONF=' . $legacyCnf);
            }
            if (!getenv('OPENSSL_MODULES')) {
                $modulesDir = '/usr/lib/x86_64-linux-gnu/ossl-modules';
                if (is_dir($modulesDir)) {
                    putenv('OPENSSL_MODULES=' . $modulesDir);
                }
            }

            $certPaths = [
                $certDirPublic . $certNombre,
                $certDirCustom . $certNombre
            ];

            $foundPath = null;
            foreach ($certPaths as $path) {
                if (file_exists($path)) {
                    $foundPath = $path;
                    break;
                }
            }

            if (!$foundPath) {
                echo json_encode(['success' => false, 'error' => 'Certificado no encontrado en el servidor']);
                exit;
            }

            // Intentar validar con openssl_pkcs12_read
            $pkcs12 = @file_get_contents($foundPath);
            if (!$pkcs12) {
                echo json_encode(['success' => false, 'error' => 'No se pudo leer el archivo del certificado']);
                exit;
            }

            $certs = [];
            $ok = @openssl_pkcs12_read($pkcs12, $certs, $certPass);

            if ($ok) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Contraseña correcta',
                    'cert_path' => $foundPath
                ]);
                exit;
            }

            // Si falla, verificar el tipo de error
            $opensslError = openssl_error_string();

            // Intentar validación con comando openssl del sistema (más robusto para legacy)
            $escapedPath = escapeshellarg($foundPath);
            $escapedPass = escapeshellarg($certPass);

            // Usar openssl pkcs12 con provider legacy
            $cmd = "openssl pkcs12 -in {$escapedPath} -passin pass:{$certPass} -noout -legacy 2>&1";
            $output = shell_exec($cmd);

            // Si no hay error, el certificado y contraseña son válidos
            if ($output === null || trim($output) === '' || strpos($output, 'MAC verified OK') !== false) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Certificado validado con OpenSSL legacy',
                    'cert_path' => $foundPath,
                    'legacy' => true
                ]);
                exit;
            }

            // Si el comando openssl también falla, la contraseña es incorrecta
            echo json_encode([
                'success' => false,
                'error' => 'Contraseña inválida o certificado corrupto',
                'openssl_php' => $opensslError,
                'openssl_cmd' => trim($output)
            ]);
            exit;

            // ======================= GEO ENDPOINTS =======================
        case 'geo_departamentos':
            $geoDB = dirname(__DIR__) . '/_lib/php-sifen3/src/db/geo.db';
            try {
                $sqlite = new PDO("sqlite:$geoDB");
                $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $sqlite->query("SELECT DISTINCT cod_depto, desc_depto FROM ref_geografica ORDER BY desc_depto");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'geo_distritos':
            $codDepto = (int)($_GET['cod_depto'] ?? 0);
            $geoDB = dirname(__DIR__) . '/_lib/php-sifen3/src/db/geo.db';
            try {
                $sqlite = new PDO("sqlite:$geoDB");
                $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $sqlite->prepare("SELECT DISTINCT cod_distrito, desc_distrito FROM ref_geografica WHERE cod_depto = ? ORDER BY desc_distrito");
                $stmt->execute([$codDepto]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'geo_ciudades':
            $codDistrito = (int)($_GET['cod_distrito'] ?? 0);
            $geoDB = dirname(__DIR__) . '/_lib/php-sifen3/src/db/geo.db';
            try {
                $sqlite = new PDO("sqlite:$geoDB");
                $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $sqlite->prepare("SELECT DISTINCT cod_ciudad, desc_ciudad FROM ref_geografica WHERE cod_distrito = ? ORDER BY desc_ciudad");
                $stmt->execute([$codDistrito]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'geo_barrios':
            $codCiudad = (int)($_GET['cod_ciudad'] ?? 0);
            $geoDB = dirname(__DIR__) . '/_lib/php-sifen3/src/db/geo.db';
            try {
                $sqlite = new PDO("sqlite:$geoDB");
                $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $sqlite->prepare("SELECT DISTINCT cod_barrio, desc_barrio FROM ref_geografica WHERE cod_ciudad = ? ORDER BY desc_barrio");
                $stmt->execute([$codCiudad]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'geo_search':
        case 'geo_buscar':
            // Búsqueda fuzzy en toda la geografía
            $q = $_GET['q'] ?? '';
            $tipo = $_GET['tipo'] ?? 'all'; // depto, distrito, ciudad, barrio, all
            $codDepto = (int)($_GET['cod_depto'] ?? 0);
            $codDistrito = (int)($_GET['cod_distrito'] ?? 0);
            $codCiudad = (int)($_GET['cod_ciudad'] ?? 0);
            $geoDB = dirname(__DIR__) . '/_lib/php-sifen3/src/db/geo.db';
            try {
                $sqlite = new PDO("sqlite:$geoDB");
                $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $conditions = [];
                $params = [];

                if ($q) {
                    $palabras = preg_split('/\s+/', trim($q));
                    foreach ($palabras as $i => $palabra) {
                        switch ($tipo) {
                            case 'depto':
                                $conditions[] = "desc_depto LIKE :q$i";
                                break;
                            case 'distrito':
                                $conditions[] = "desc_distrito LIKE :q$i";
                                break;
                            case 'ciudad':
                                $conditions[] = "desc_ciudad LIKE :q$i";
                                break;
                            case 'barrio':
                                $conditions[] = "desc_barrio LIKE :q$i";
                                break;
                            default:
                                $conditions[] = "(desc_depto LIKE :q$i OR desc_distrito LIKE :q$i OR desc_ciudad LIKE :q$i OR desc_barrio LIKE :q$i)";
                        }
                        $params[":q$i"] = "%$palabra%";
                    }
                }

                if ($codDepto) {
                    $conditions[] = "cod_depto = :codDepto";
                    $params[':codDepto'] = $codDepto;
                }
                if ($codDistrito) {
                    $conditions[] = "cod_distrito = :codDistrito";
                    $params[':codDistrito'] = $codDistrito;
                }
                if ($codCiudad) {
                    $conditions[] = "cod_ciudad = :codCiudad";
                    $params[':codCiudad'] = $codCiudad;
                }

                $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

                // Seleccionar columnas según el tipo para evitar duplicados
                switch ($tipo) {
                    case 'depto':
                        $sql = "SELECT DISTINCT cod_depto, desc_depto FROM ref_geografica $where ORDER BY desc_depto LIMIT 15";
                        break;
                    case 'distrito':
                        $sql = "SELECT DISTINCT cod_distrito, desc_distrito, cod_depto FROM ref_geografica $where ORDER BY desc_distrito LIMIT 15";
                        break;
                    case 'ciudad':
                        $sql = "SELECT DISTINCT cod_ciudad, desc_ciudad, cod_distrito FROM ref_geografica $where ORDER BY desc_ciudad LIMIT 15";
                        break;
                    case 'barrio':
                        $sql = "SELECT DISTINCT cod_barrio, desc_barrio, cod_ciudad FROM ref_geografica $where ORDER BY desc_barrio LIMIT 15";
                        break;
                    default:
                        $sql = "SELECT DISTINCT cod_depto, desc_depto, cod_distrito, desc_distrito, cod_ciudad, desc_ciudad, cod_barrio, desc_barrio FROM ref_geografica $where ORDER BY desc_depto, desc_distrito, desc_ciudad, desc_barrio LIMIT 15";
                }

                $stmt = $sqlite->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

            // ======================= SIFEN RUC Lookup =======================
        case 'sifen_lookup':
            try {
                $rucRaw = trim($_GET['ruc'] ?? '');
                if (strlen($rucRaw) < 5) {
                    throw new Exception('RUC/Cédula muy corta');
                }

                $baseDir = dirname(__DIR__, 2);
                $certNombreFijo = '80118689.p12';
                $certPassFijo = '3nvcEcwW';

                $candidates = [
                    $baseDir . '/public/_lib/php-sifen3-custom/certificados/' . $certNombreFijo,
                    $baseDir . '/public/_lib/php-sifen3/certificados/' . $certNombreFijo,
                    $baseDir . '/_lib/sifen/certificados/' . $certNombreFijo,
                    $baseDir . '/_lib/certificados/' . $certNombreFijo
                ];

                $certPath = '';
                foreach ($candidates as $cand) {
                    if (file_exists($cand)) {
                        $certPath = $cand;
                        break;
                    }
                }

                if (!$certPath) {
                    throw new Exception('Certificado de consulta no encontrado');
                }

                $pkcs12Content = file_get_contents($certPath);
                if (!openssl_pkcs12_read($pkcs12Content, $certs, $certPassFijo)) {
                    throw new Exception('No se pudo leer el certificado PKCS12');
                }

                $pemContent = $certs['pkey'] . "\n" . $certs['cert'] . "\n";
                if (!empty($certs['extracerts'])) {
                    foreach ($certs['extracerts'] as $extra) {
                        $pemContent .= $extra . "\n";
                    }
                }

                $rucSoloNum = preg_replace('/[^0-9]/', '', $rucRaw);
                if ($rucSoloNum === '') {
                    throw new Exception('RUC inválido');
                }

                $pemTmpPath = sys_get_temp_dir() . '/sifen_lookup_' . uniqid() . '.pem';
                file_put_contents($pemTmpPath, $pemContent);

                $soapXml = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
  <soap:Body>
    <xsd:rEnviConsRUC>
      <xsd:dId>1</xsd:dId>
      <xsd:dRUCCons>' . htmlspecialchars($rucSoloNum) . '</xsd:dRUCCons>
    </xsd:rEnviConsRUC>
  </soap:Body>
</soap:Envelope>';

                $url = 'https://sifen.set.gov.py/de/ws/consultas/consulta-ruc.wsdl';

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $soapXml,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/soap+xml; charset=utf-8',
                        'SOAPAction: ""'
                    ],
                    CURLOPT_SSLCERT => $pemTmpPath,
                    CURLOPT_SSLCERTPASSWD => '',
                    CURLOPT_SSLKEY => $pemTmpPath,
                    CURLOPT_SSLKEYPASSWD => '',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 15
                ]);

                $response = curl_exec($ch);
                $curlError = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                @unlink($pemTmpPath);

                if ($curlError) {
                    throw new Exception('Error de conexión: ' . $curlError);
                }

                if ($httpCode !== 200 || empty($response)) {
                    throw new Exception('SIFEN HTTP ' . $httpCode);
                }

                $cleanXml = preg_replace('/<(\/?)\w+:(\w+)/', '<$1$2', $response);
                $xmlRes = new SimpleXMLElement($cleanXml);

                $dCodRes = (string)($xmlRes->xpath('//dCodRes')[0] ?? '');
                if ($dCodRes !== '0502' && $dCodRes !== '0260') {
                    $dMsgRes = (string)($xmlRes->xpath('//dMsgRes')[0] ?? 'No encontrado en SIFEN');
                    throw new Exception($dMsgRes);
                }

                $xContr = $xmlRes->xpath('//xContRUC')[0] ?? $xmlRes->xpath('//xContr')[0] ?? null;
                if (!$xContr) {
                    throw new Exception('Respuesta inválida de SIFEN');
                }

                $foundNombre = trim((string)($xContr->dRazCons ?? $xContr->dRazSoc ?? ''));
                $foundRucBase = trim((string)($xContr->dRUCCons ?? $xContr->dRUC ?? ''));

                // Calcular DV con algoritmo módulo 11
                function calcularDVSifen($ruc)
                {
                    $ruc = preg_replace('/[^0-9]/', '', $ruc);
                    if (empty($ruc)) return '';
                    $baseMax = 11;
                    $k = 2;
                    $total = 0;
                    for ($i = strlen($ruc) - 1; $i >= 0; $i--) {
                        $total += (int)$ruc[$i] * $k;
                        $k++;
                        if ($k > $baseMax) $k = 2;
                    }
                    $resto = $total % 11;
                    return (string)(($resto > 1) ? (11 - $resto) : 0);
                }

                $foundDV = calcularDVSifen($foundRucBase);

                if ($foundNombre === '') {
                    throw new Exception('Nombre vacío en respuesta SIFEN');
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'razon_social' => $foundNombre,
                        'ruc' => $foundRucBase . '-' . $foundDV,
                        'ruc_base' => $foundRucBase,
                        'dv' => $foundDV
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
            exit;
    }
}

// ======================= HTML VIEW =======================
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Habilitación SIFEN - Facturadores Electrónicos</title>
    <link rel="stylesheet" href="/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="/_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js"></script>
    <script src="/_lib/ag-grid/license.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: {
                            850: '#151e2e'
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.0.4/tesseract.min.js"></script>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && systemDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <style>
        .ag-theme-quartz {
            --ag-font-family: "Poppins", sans-serif;
            --ag-font-size: 13px;
            --ag-odd-row-background-color: rgba(0, 0, 0, 0.05);
        }

        .ag-theme-quartz-dark {
            --ag-font-family: "Poppins", sans-serif;
            --ag-font-size: 13px;
            --ag-odd-row-background-color: rgba(255, 255, 255, 0.04);
        }

        html.dark .ag-theme-quartz-dark,
        html.dark .ag-theme-quartz {
            --ag-background-color: #0b121e !important;
            --ag-foreground-color: #e2e8f0 !important;
            --ag-header-background-color: #0f172a !important;
            --ag-header-foreground-color: #94a3b8 !important;
            --ag-border-color: #1e293b !important;
            --ag-row-border-color: #1e293b !important;
            --ag-row-hover-color: #1e293b !important;
            --ag-selected-row-background-color: rgba(59, 130, 246, 0.15) !important;
            --ag-input-focus-border-color: #3b82f6 !important;
            --ag-data-color: #f1f5f9 !important;
            --ag-font-family: "Poppins", sans-serif;
        }

        :root {
            color-scheme: light;
            --bg: transparent;
            --text: #1f2933;
            --border-color: rgba(0, 0, 0, 0.4);
            --ag-background: rgba(255, 255, 255, 0.2) !important;
            --ag-header-background: rgba(241, 245, 249, 0.6) !important;
        }

        html.dark {
            color-scheme: dark;
            --bg: transparent !important;
            --text: #f1f5f9;
            --border-color: rgba(255, 255, 255, 0.4);
            --ag-background: rgba(30, 41, 59, 0.4) !important;
            --ag-header-background: rgba(15, 23, 42, 0.6) !important;
            background: transparent !important;
            background-color: transparent !important;
        }

        html {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: "Poppins", sans-serif;
            margin: 0;
            padding: 8px;
            background: transparent !important;
            color: var(--text);
            height: 100%;
            overflow: hidden;
            box-sizing: border-box;
        }

        .container {
            height: calc(100% - 0px);
            max-height: calc(100vh - 16px);
            background-color: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(2px);
            border-radius: 12px;
            margin: 0;
            padding: 10px 15px;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        html.dark .container {
            background-color: rgba(12, 23, 39, 0.9) !important;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: nowrap;
            gap: 10px;
            flex-shrink: 0;
        }

        h1 {
            color: #2d7be5;
            font-size: 1.2rem !important;
            font-weight: 500 !important;
        }

        .toolbar {
            display: flex;
            gap: 12px;
        }

        .btn-chip {
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 400;
            cursor: pointer;
            border: 1px solid #3b82f6;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            color: #2d7be5;
            transition: all 0.2s;
            font-size: 13px;
        }

        .btn-chip:hover {
            background: rgba(59, 130, 246, 0.05);
            transform: translateY(-1px);
        }

        .btn-chip--success {
            border-color: #22c55e;
            color: #22c55e;
        }

        .btn-chip--primary {
            border-color: #3b82f6;
            color: #2d7be5;
        }

        .btn-chip--disabled {
            border-color: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-chip:disabled,
        .btn-chip[disabled] {
            cursor: not-allowed;
            opacity: 0.5;
        }

        .btn-chip--danger {
            border-color: #ef4444;
            color: #ef4444;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 10px 10px 35px;
            border-radius: 8px;
            border: 1px solid #ccc;
            width: 200px;
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        #habilitacionesGrid {
            flex: 1 1 auto;
            min-height: 300px;
            height: calc(100vh - 150px);
            width: 100%;
        }

        #habilitacionesGrid.ag-theme-quartz,
        #habilitacionesGrid.ag-theme-quartz-dark {
            height: 100% !important;
            width: 100% !important;
            border-radius: 8px;
        }

        html.dark #habilitacionesGrid {
            background-color: transparent !important;
        }

        /* Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        /* Tabla mini para documentos y actividades */
        .tabla-mini {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }

        html.dark .tabla-mini {
            border-color: #334155;
        }

        .tabla-mini table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .tabla-mini th,
        .tabla-mini td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        html.dark .tabla-mini th,
        html.dark .tabla-mini td {
            border-color: #334155;
        }

        .tabla-mini th {
            background: #f8fafc;
            font-weight: 600;
            color: #64748b;
            position: sticky;
            top: 0;
        }

        html.dark .tabla-mini th {
            background: #1e293b;
        }

        .tabla-mini tbody tr:hover {
            background: rgba(59, 130, 246, 0.05);
        }

        /* Botones mini */
        .btn-mini {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .btn-mini--danger {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .btn-mini:hover {
            opacity: 0.8;
        }

        /* Add form inline */
        .add-form {
            background: rgba(59, 130, 246, 0.05);
            padding: 12px;
            border-radius: 8px;
            border: 1px dashed #3b82f6;
        }

        html.dark .add-form {
            background: rgba(59, 130, 246, 0.1);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .modal {
            background: white;
            border-radius: 16px;
            max-width: 1200px;
            width: 98%;
            max-height: 95vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        html.dark .modal {
            background: #1e293b;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: inherit;
            z-index: 10;
        }

        html.dark .modal-header {
            border-color: #334155;
        }

        .modal-header h2 {
            color: #3b82f6;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            background: none;
            border: none;
            color: #64748b;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: inherit;
        }

        html.dark .modal-footer {
            border-color: #334155;
        }

        /* Form */
        .form-section {
            margin-bottom: 20px;
        }

        .form-section-title {
            color: #f59e0b;
            font-size: 0.9rem;
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        html.dark .form-section-title {
            border-color: #334155;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group.span-2 {
            grid-column: span 2;
        }

        .form-group.span-3 {
            grid-column: span 3;
        }

        .form-group label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group label.required::after {
            content: ' *';
            color: #ef4444;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 8px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #1e293b;
            font-size: 13px;
        }

        html.dark .form-group input,
        html.dark .form-group select,
        html.dark .form-group textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
        }

        /* Documentos */
        .documentos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
        }

        .documento-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        html.dark .documento-item {
            background: #0f172a;
            border-color: #334155;
        }

        .documento-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #3b82f6;
        }

        .documento-item label {
            flex: 1;
            cursor: pointer;
            font-size: 13px;
        }

        .documento-item .establecimiento {
            font-size: 10px;
            color: #64748b;
        }

        /* Stats */
        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
            flex-shrink: 0;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            background: rgba(59, 130, 246, 0.08);
            border-radius: 10px;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .stat-card i {
            font-size: 1.2rem;
            color: #3b82f6;
        }

        .stat-card .value {
            font-size: 1.3rem;
            font-weight: 600;
            color: #3b82f6;
        }

        .stat-card .label {
            font-size: 11px;
            color: #64748b;
        }

        .stat-card.success {
            background: rgba(34, 197, 94, 0.08);
            border-color: rgba(34, 197, 94, 0.2);
        }

        .stat-card.success i,
        .stat-card.success .value {
            color: #22c55e;
        }

        .stat-card.warning {
            background: rgba(245, 158, 11, 0.08);
            border-color: rgba(245, 158, 11, 0.2);
        }

        .stat-card.warning i,
        .stat-card.warning .value {
            color: #f59e0b;
        }

        /* Sistema de Alertas Alpine */
        .alert-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .alert-box {
            background: white;
            border-radius: 16px;
            padding: 24px;
            min-width: 320px;
            max-width: 500px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: scaleIn 0.2s ease-out;
        }

        html.dark .alert-box {
            background: #1e293b;
            border: 1px solid #334155;
        }

        .alert-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
        }

        .alert-icon.success {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .alert-icon.error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }

        .alert-icon.warning {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }

        .alert-icon.info {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .alert-icon.loading {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .alert-title {
            font-size: 18px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 8px;
            color: #1e293b;
        }

        html.dark .alert-title {
            color: #f1f5f9;
        }

        .alert-text {
            font-size: 14px;
            text-align: center;
            color: #64748b;
            margin-bottom: 20px;
        }

        html.dark .alert-text {
            color: #94a3b8;
        }

        .alert-html {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 20px;
        }

        .alert-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .alert-btn {
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .alert-btn:hover {
            transform: translateY(-1px);
        }

        .alert-btn-primary {
            background: #3b82f6;
            color: white;
        }

        .alert-btn-primary:hover {
            background: #2563eb;
        }

        .alert-btn-danger {
            background: #ef4444;
            color: white;
        }

        .alert-btn-danger:hover {
            background: #dc2626;
        }

        .alert-btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }

        html.dark .alert-btn-secondary {
            background: #334155;
            color: #e2e8f0;
        }

        .alert-btn-secondary:hover {
            background: #cbd5e1;
        }

        html.dark .alert-btn-secondary:hover {
            background: #475569;
        }

        .alert-select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: white;
            color: #1e293b;
            font-size: 14px;
            margin-bottom: 20px;
        }

        html.dark .alert-select {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        /* Autocomplete Empresa */
        .autocomplete-container {
            position: relative;
            width: 100%;
        }

        .autocomplete-input {
            width: 100%;
            padding: 10px 12px;
            padding-right: 35px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: white;
            color: #1e293b;
            font-size: 14px;
            box-sizing: border-box;
        }

        html.dark .autocomplete-input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }

        .autocomplete-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .autocomplete-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
        }

        .autocomplete-clear:hover {
            color: #ef4444;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 250px;
            overflow-y: auto;
            background: white;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            z-index: 100;
        }

        html.dark .autocomplete-dropdown {
            background: #1e293b;
            border-color: #334155;
        }

        .autocomplete-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background 0.15s;
        }

        html.dark .autocomplete-item {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }

        .autocomplete-item:hover,
        .autocomplete-item.active {
            background: rgba(59, 130, 246, 0.1);
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item-name {
            font-weight: 500;
            color: #1e293b;
        }

        html.dark .autocomplete-item-name {
            color: #f1f5f9;
        }

        .autocomplete-item-ruc {
            font-size: 12px;
            color: #64748b;
        }

        .autocomplete-item mark {
            background: rgba(59, 130, 246, 0.3);
            color: inherit;
            padding: 0 2px;
            border-radius: 2px;
        }

        .autocomplete-no-results {
            padding: 12px;
            text-align: center;
            color: #64748b;
            font-size: 13px;
        }

        /* Google Maps Direccion Autocomplete */
        .direccion-container {
            position: relative;
            width: 100%;
        }

        .direccion-input {
            width: 100%;
            padding: 8px 10px;
            padding-left: 35px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #1e293b;
            font-size: 13px;
            box-sizing: border-box;
        }

        html.dark .direccion-input {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        .direccion-input:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .direccion-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #ef4444;
            font-size: 14px;
        }

        .direccion-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
        }

        .direccion-clear:hover {
            color: #ef4444;
        }

        /* Google Places Autocomplete dropdown styles */
        .pac-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
            border: 1px solid #e2e8f0;
            font-family: "Poppins", sans-serif;
            z-index: 10000 !important;
            margin-top: 4px;
        }

        html.dark .pac-container {
            background: #1e293b;
            border-color: #334155;
        }

        .pac-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        html.dark .pac-item {
            border-color: #334155;
        }

        .pac-item:hover {
            background: rgba(59, 130, 246, 0.1);
        }

        .pac-item-query {
            font-weight: 500;
            color: #1e293b;
        }

        html.dark .pac-item-query {
            color: #f1f5f9;
        }

        .pac-matched {
            color: #3b82f6;
            font-weight: 600;
        }

        .pac-icon {
            margin-right: 8px;
        }

        html.dark .pac-item span {
            color: #94a3b8;
        }

        /* Phone container con select de país */
        .phone-container {
            display: flex;
            gap: 8px;
            width: 100%;
        }

        .phone-country-select {
            width: 110px;
            padding: 8px 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #1e293b;
            font-size: 13px;
            cursor: pointer;
            flex-shrink: 0;
        }

        html.dark .phone-country-select {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        .phone-country-select:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .phone-number-input {
            flex: 1;
            padding: 8px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #1e293b;
            font-size: 13px;
            min-width: 0;
        }

        html.dark .phone-number-input {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        .phone-number-input:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .phone-number-input::placeholder {
            color: #94a3b8;
        }
    </style>
</head>

<body x-data="habilitacionApp()" x-init="init()">
    <!-- Sistema de Alertas Alpine -->
    <template x-if="alert.show">
        <div class="alert-overlay" @click.self="alert.showCancel ? closeAlert(false) : null">
            <div class="alert-box" :style="alert.width ? 'max-width:' + alert.width + 'px' : ''">
                <div class="alert-icon" :class="alert.type">
                    <template x-if="alert.type === 'success'"><i class="fas fa-check"></i></template>
                    <template x-if="alert.type === 'error'"><i class="fas fa-times"></i></template>
                    <template x-if="alert.type === 'warning'"><i class="fas fa-exclamation-triangle"></i></template>
                    <template x-if="alert.type === 'info'"><i class="fas fa-info"></i></template>
                    <template x-if="alert.type === 'loading'"><i class="fas fa-circle-notch spinner"></i></template>
                </div>
                <div class="alert-title" x-text="alert.title"></div>
                <div class="alert-text" x-show="alert.text" x-text="alert.text"></div>
                <div class="alert-html" x-show="alert.html" x-html="alert.html"></div>
                <template x-if="alert.input === 'select'">
                    <select class="alert-select" x-model="alert.inputValue">
                        <option value="">-- Seleccione --</option>
                        <template x-for="(label, key) in alert.inputOptions" :key="key">
                            <option :value="key" x-text="label"></option>
                        </template>
                    </select>
                </template>
                <div class="alert-buttons" x-show="alert.type !== 'loading'">
                    <button x-show="alert.showCancel" class="alert-btn alert-btn-secondary" @click="closeAlert(false)" x-text="alert.cancelText || 'Cancelar'"></button>
                    <button class="alert-btn" :class="alert.type === 'error' || alert.confirmDanger ? 'alert-btn-danger' : 'alert-btn-primary'" @click="closeAlert(true)" x-text="alert.confirmText || 'Aceptar'"></button>
                </div>
            </div>
        </div>
    </template>

    <div class="container">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-file-invoice"></i> Habilitación SIFEN</h1>
            </div>
            <div class="toolbar">
                <div class="search-box">
                    <input type="text" id="globalFilter" placeholder="Buscar..." @input="onFilterChanged($event)">
                    <i class="fa-solid fa-search"></i>
                </div>
                <button class="btn-chip btn-chip--success" @click="abrirModal()">
                    <i class="fa-solid fa-plus"></i> Nueva
                </button>
                <button class="btn-chip" @click="cargarDatos()">
                    <i class="fa-solid fa-rotate"></i> Actualizar
                </button>
                <button class="btn-chip btn-chip--primary" @click="showSifenGuide = true">
                    <i class="fa-solid fa-circle-info"></i> Requisitos SIFEN
                </button>
                <a href="facturas_sifen.php" class="btn-chip">
                    <i class="fa-solid fa-arrow-left"></i> Volver
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <i class="fas fa-building"></i>
                <div>
                    <div class="value" x-text="habilitaciones.length">0</div>
                    <div class="label">Empresas</div>
                </div>
            </div>
            <div class="stat-card success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <div class="value" x-text="habilitaciones.filter(h => h.ambiente === 'PROD').length">0</div>
                    <div class="label">Producción</div>
                </div>
            </div>
            <div class="stat-card warning">
                <i class="fas fa-flask"></i>
                <div>
                    <div class="value" x-text="habilitaciones.filter(h => h.ambiente === 'TEST').length">0</div>
                    <div class="label">Pruebas</div>
                </div>
            </div>
        </div>

        <div id="habilitacionesGrid" class="ag-theme-quartz"></div>
    </div>

    <div class="modal-overlay" x-show="showSifenGuide" x-cloak @click.self="showSifenGuide = false">
        <div class="modal" style="max-width: 880px;">
            <div class="modal-header">
                <h2><i class="fas fa-circle-info"></i> Requisitos e instrucciones para habilitar en SIFEN</h2>
                <button class="modal-close" @click="showSifenGuide = false">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-section">
                    <h3 class="form-section-title"><i class="fas fa-list-check"></i> Requisitos previos</h3>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <ul style="margin:0; padding-left:18px; line-height:1.8;">
                                <li>Tener RUC activo y datos actualizados en DNIT.</li>
                                <li>Contar con timbrado vigente para comprobantes electrónicos.</li>
                                <li>Tener habilitado el ambiente correcto: <strong>TEST</strong> para pruebas o <strong>PROD</strong> para producción.</li>
                                <li>Disponer del <strong>CSC</strong> e <strong>ID CSC</strong> entregados por DNIT.</li>
                                <li>Contar con certificado digital válido en formato <strong>.p12</strong> o <strong>.pfx</strong> y conocer su contraseña.</li>
                                <li>Definir establecimientos y puntos de expedición que realmente vas a usar.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title"><i class="fas fa-diagram-project"></i> Qué completar en esta pantalla</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Empresa y datos fiscales</label>
                            <div>Seleccioná la empresa, verificá RUC, DV, razón social, dirección y datos del representante legal.</div>
                        </div>
                        <div class="form-group">
                            <label>Timbrado y ambiente</label>
                            <div>Cargá número de timbrado, vigencia y elegí <strong>TEST</strong> o <strong>PROD</strong> según corresponda.</div>
                        </div>
                        <div class="form-group">
                            <label>CSC y certificado</label>
                            <div>Completá <strong>ID CSC</strong>, <strong>CSC</strong>, subí el certificado y validá su contraseña desde el botón correspondiente.</div>
                        </div>
                        <div class="form-group">
                            <label>Documentos y puntos</label>
                            <div>Agregá los tipos de documentos a emitir y asociá establecimientos, puntos de expedición y cajas.</div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title"><i class="fas fa-arrow-down-up-across-line"></i> Orden recomendado</h3>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <ol style="margin:0; padding-left:18px; line-height:1.9;">
                                <li>Seleccioná la empresa correcta.</li>
                                <li>Completá o revisá todos los datos fiscales del Form 364-3.</li>
                                <li>Cargá el timbrado y elegí el ambiente SIFEN.</li>
                                <li>Subí el certificado y usá <strong>Validar</strong> para comprobar la contraseña.</li>
                                <li>Registrá CSC e ID CSC.</li>
                                <li>Guardá la habilitación principal.</li>
                                <li>Después agregá documentos habilitados, puntos de expedición, cajas y actividades si aplica.</li>
                                <li>Recién al final probá una emisión electrónica desde el POS.</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title"><i class="fas fa-triangle-exclamation"></i> Errores frecuentes</h3>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <ul style="margin:0; padding-left:18px; line-height:1.8;">
                                <li>Ambiente <strong>PROD</strong> con certificado o CSC de pruebas.</li>
                                <li>Certificado vencido, corrupto o con contraseña incorrecta.</li>
                                <li>Punto de expedición distinto al que se usa en caja/POS.</li>
                                <li>Timbrado vencido o mal cargado.</li>
                                <li>Razón social o RUC inconsistente con DNIT/SIFEN.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-chip btn-chip--primary" @click="showSifenGuide = false">
                    <i class="fas fa-check"></i> Entendido
                </button>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <template x-if="showModal">
        <div class="modal-overlay" @click.self="cerrarModal()">
            <div class="modal">
                <div class="modal-header">
                    <h2><i class="fas fa-edit"></i> <span x-text="formData.id ? 'Editar' : 'Nueva'"></span> Habilitación</h2>
                    <button class="modal-close" @click="cerrarModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- Empresa con Autocomplete -->
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-building"></i> Empresa</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="required">Seleccionar Empresa</label>
                                <div class="autocomplete-container">
                                    <input type="text"
                                        class="autocomplete-input"
                                        x-model="empresaSearch"
                                        @input="filtrarEmpresas()"
                                        @focus="showEmpresaDropdown = true"
                                        @click="showEmpresaDropdown = true"
                                        @keydown.escape="showEmpresaDropdown = false"
                                        @keydown.arrow-down.prevent="navegarEmpresa(1)"
                                        @keydown.arrow-up.prevent="navegarEmpresa(-1)"
                                        @keydown.enter.prevent="seleccionarEmpresaActiva()"
                                        placeholder="Escriba para buscar... ej: siste eas">
                                    <button type="button" class="autocomplete-clear"
                                        x-show="empresaSearch"
                                        @click="limpiarEmpresa()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="autocomplete-dropdown"
                                        x-show="showEmpresaDropdown && empresaSearch.length > 0"
                                        @click.outside="showEmpresaDropdown = false">
                                        <template x-if="empresasFiltradas.length > 0">
                                            <template x-for="(emp, idx) in empresasFiltradas" :key="emp.id_empresa">
                                                <div class="autocomplete-item"
                                                    :class="{'active': idx === empresaActiveIndex}"
                                                    @click="seleccionarEmpresa(emp)"
                                                    @mouseenter="empresaActiveIndex = idx">
                                                    <div class="autocomplete-item-name" x-html="resaltarCoincidencias(emp.empresa, empresaSearch)"></div>
                                                    <div class="autocomplete-item-ruc" x-text="`RUC: ${emp.ruc}-${emp.dv}`"></div>
                                                </div>
                                            </template>
                                        </template>
                                        <template x-if="empresasFiltradas.length === 0">
                                            <div class="autocomplete-no-results">
                                                <i class="fas fa-search"></i> No se encontraron resultados
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario 364-3 -->
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-file-alt"></i> Formulario 364-3</h3>
                        <div class="form-grid">
                            <div class="form-group"><label>Nº Formulario</label><input type="text" x-model="formData.numero_formulario" placeholder="364010024412"></div>
                            <div class="form-group"><label>Fecha</label><input type="date" x-model="formData.fecha_formulario"></div>
                        </div>
                    </div>

                    <!-- Contribuyente -->
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-user-tie"></i> Contribuyente</h3>
                        <div class="form-grid">
                            <div class="form-group"><label class="required">RUC</label><input type="text" x-model="formData.ruc"></div>
                            <div class="form-group"><label class="required">DV</label><input type="number" x-model="formData.dv" min="0" max="9"></div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="button"
                                    @click="extraerConOCR()"
                                    :disabled="ocrProcessing"
                                    style="padding:8px 16px;min-width:auto;white-space:nowrap;width:100%;"
                                    class="btn-chip"
                                    :class="ocrProcessing ? 'btn-chip--disabled' : 'btn-chip--primary'">
                                    <i :class="ocrProcessing ? 'fas fa-spinner fa-spin' : 'fas fa-image'"></i>
                                    <span x-text="ocrProcessing ? 'Procesando OCR...' : 'OCR'"></span>
                                </button>
                            </div>
                            <div class="form-group span-2"><label class="required">Razón Social</label><input type="text" x-model="formData.razon_social"></div>
                            <div class="form-group span-2"><label>Nombre Fantasía</label><input type="text" x-model="formData.nombre_fantasia"></div>
                            <div class="form-group"><label>Estado</label>
                                <select x-model="formData.estado_contribuyente">
                                    <option value="ACTIVO">Activo</option>
                                    <option value="INACTIVO">Inactivo</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Representante -->
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-user-shield"></i> Representante Legal</h3>
                        <div class="form-grid">
                            <div class="form-group"><label>Tipo Doc</label>
                                <select x-model="formData.representante_tipo_doc">
                                    <option value="CI">CI</option>
                                    <option value="RUC">RUC</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Documento</label><input type="text" x-model="formData.representante_documento"></div>
                            <div class="form-group span-2"><label>Nombre</label><input type="text" x-model="formData.representante_nombre"></div>
                        </div>
                    </div>

                    <!-- Ubicación -->
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-map-marker-alt"></i> Ubicación</h3>
                        <div class="form-grid">
                            <!-- Departamento -->
                            <div class="form-group">
                                <label>Departamento</label>
                                <div class="autocomplete-container">
                                    <input type="text" class="autocomplete-input"
                                        x-model="geoSearch.departamento"
                                        @input="buscarGeo('departamento')"
                                        @focus="buscarGeo('departamento')"
                                        @keydown.arrow-down.prevent="navegarGeo('departamento', 1)"
                                        @keydown.arrow-up.prevent="navegarGeo('departamento', -1)"
                                        @keydown.enter.prevent="seleccionarGeoActivo('departamento')"
                                        @keydown.escape="cerrarGeoDropdown('departamento')"
                                        @click.outside="cerrarGeoDropdown('departamento')"
                                        placeholder="Buscar departamento...">
                                    <button type="button" class="autocomplete-clear" x-show="geoSearch.departamento" @click="limpiarGeo('departamento')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="autocomplete-dropdown" x-show="showGeoDropdown.departamento && geoResultados.departamento.length > 0">
                                        <template x-for="(item, idx) in geoResultados.departamento" :key="item.cod_depto">
                                            <div class="autocomplete-item" :class="{'active': geoActiveIndex.departamento === idx}" @click="seleccionarGeo('departamento', item)">
                                                <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_depto, geoSearch.departamento)"></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <!-- Distrito -->
                            <div class="form-group">
                                <label>Distrito</label>
                                <div class="autocomplete-container">
                                    <input type="text" class="autocomplete-input"
                                        x-model="geoSearch.distrito"
                                        @input="buscarGeo('distrito')"
                                        @focus="buscarGeo('distrito')"
                                        @keydown.arrow-down.prevent="navegarGeo('distrito', 1)"
                                        @keydown.arrow-up.prevent="navegarGeo('distrito', -1)"
                                        @keydown.enter.prevent="seleccionarGeoActivo('distrito')"
                                        @keydown.escape="cerrarGeoDropdown('distrito')"
                                        @click.outside="cerrarGeoDropdown('distrito')"
                                        placeholder="Buscar distrito..."
                                        :disabled="!formData.cod_depto">
                                    <button type="button" class="autocomplete-clear" x-show="geoSearch.distrito" @click="limpiarGeo('distrito')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="autocomplete-dropdown" x-show="showGeoDropdown.distrito && geoResultados.distrito.length > 0">
                                        <template x-for="(item, idx) in geoResultados.distrito" :key="item.cod_distrito">
                                            <div class="autocomplete-item" :class="{'active': geoActiveIndex.distrito === idx}" @click="seleccionarGeo('distrito', item)">
                                                <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_distrito, geoSearch.distrito)"></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <!-- Ciudad/Localidad -->
                            <div class="form-group">
                                <label>Ciudad</label>
                                <div class="autocomplete-container">
                                    <input type="text" class="autocomplete-input"
                                        x-model="geoSearch.ciudad"
                                        @input="buscarGeo('ciudad')"
                                        @focus="buscarGeo('ciudad')"
                                        @keydown.arrow-down.prevent="navegarGeo('ciudad', 1)"
                                        @keydown.arrow-up.prevent="navegarGeo('ciudad', -1)"
                                        @keydown.enter.prevent="seleccionarGeoActivo('ciudad')"
                                        @keydown.escape="cerrarGeoDropdown('ciudad')"
                                        @click.outside="cerrarGeoDropdown('ciudad')"
                                        placeholder="Buscar ciudad..."
                                        :disabled="!formData.cod_distrito">
                                    <button type="button" class="autocomplete-clear" x-show="geoSearch.ciudad" @click="limpiarGeo('ciudad')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="autocomplete-dropdown" x-show="showGeoDropdown.ciudad && geoResultados.ciudad.length > 0">
                                        <template x-for="(item, idx) in geoResultados.ciudad" :key="item.cod_ciudad">
                                            <div class="autocomplete-item" :class="{'active': geoActiveIndex.ciudad === idx}" @click="seleccionarGeo('ciudad', item)">
                                                <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_ciudad, geoSearch.ciudad)"></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <!-- Barrio -->
                            <div class="form-group">
                                <label>Barrio</label>
                                <div class="autocomplete-container">
                                    <input type="text" class="autocomplete-input"
                                        x-model="geoSearch.barrio"
                                        @input="buscarGeo('barrio')"
                                        @focus="buscarGeo('barrio')"
                                        @keydown.arrow-down.prevent="navegarGeo('barrio', 1)"
                                        @keydown.arrow-up.prevent="navegarGeo('barrio', -1)"
                                        @keydown.enter.prevent="seleccionarGeoActivo('barrio')"
                                        @keydown.escape="cerrarGeoDropdown('barrio')"
                                        @click.outside="cerrarGeoDropdown('barrio')"
                                        placeholder="Buscar barrio..."
                                        :disabled="!formData.cod_ciudad">
                                    <button type="button" class="autocomplete-clear" x-show="geoSearch.barrio" @click="limpiarGeo('barrio')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="autocomplete-dropdown" x-show="showGeoDropdown.barrio && geoResultados.barrio.length > 0">
                                        <template x-for="(item, idx) in geoResultados.barrio" :key="item.cod_barrio">
                                            <div class="autocomplete-item" :class="{'active': geoActiveIndex.barrio === idx}" @click="seleccionarGeo('barrio', item)">
                                                <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_barrio, geoSearch.barrio)"></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <!-- Dirección -->
                            <div class="form-group span-2">
                                <label><i class="fas fa-map-marker-alt" style="color:#ef4444;margin-right:4px;"></i> Dirección</label>
                                <div class="direccion-container">
                                    <i class="fas fa-map-marker-alt direccion-icon"></i>
                                    <input type="text"
                                        class="direccion-input"
                                        x-model="formData.direccion"
                                        placeholder="Ingrese la dirección...">
                                    <button type="button" class="direccion-clear" x-show="formData.direccion" @click="formData.direccion = ''">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group"><label>Nº Casa</label><input type="text" x-model="formData.numero_casa"></div>
                            <!-- Teléfono con código de país -->
                            <div class="form-group">
                                <label><i class="fas fa-phone" style="color:#22c55e;margin-right:4px;"></i> Teléfono</label>
                                <div class="phone-container">
                                    <select class="phone-country-select" x-model="formData.telefono_pais">
                                        <option value="+595">🇵🇾 +595</option>
                                        <option value="+54">🇦🇷 +54</option>
                                        <option value="+55">🇧🇷 +55</option>
                                        <option value="+598">🇺🇾 +598</option>
                                        <option value="+591">🇧🇴 +591</option>
                                        <option value="+56">🇨🇱 +56</option>
                                        <option value="+51">🇵🇪 +51</option>
                                        <option value="+593">🇪🇨 +593</option>
                                        <option value="+57">🇨🇴 +57</option>
                                        <option value="+58">🇻🇪 +58</option>
                                        <option value="+52">🇲🇽 +52</option>
                                        <option value="+1">🇺🇸 +1</option>
                                        <option value="+34">🇪🇸 +34</option>
                                    </select>
                                    <input type="tel"
                                        class="phone-number-input"
                                        x-model="formData.telefono_numero"
                                        placeholder="981 123456"
                                        @input="actualizarTelefono()">
                                </div>
                            </div>
                            <div class="form-group span-2"><label>Email</label><input type="email" x-model="formData.email"></div>
                        </div>
                    </div>

                    <!-- Timbrado y SIFEN -->
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-stamp"></i> Timbrado y SIFEN</h3>
                        <div class="form-grid">
                            <div class="form-group"><label class="required">Nº Timbrado</label><input type="text" x-model="formData.numero_timbrado"></div>
                            <div class="form-group"><label>Estado</label>
                                <select x-model="formData.estado_timbrado">
                                    <option value="ACTIVO">Activo</option>
                                    <option value="INACTIVO">Inactivo</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Inicio Vigencia</label><input type="date" x-model="formData.fecha_inicio_vigencia"></div>
                            <div class="form-group"><label>Fin Vigencia</label><input type="date" x-model="formData.fecha_fin_vigencia"></div>
                            <div class="form-group"><label>Ambiente</label>
                                <select x-model="formData.ambiente">
                                    <option value="TEST">Pruebas</option>
                                    <option value="PROD">Producción</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Sistema</label><input type="text" x-model="formData.sistema_contribuyente" value="SISTEMAX"></div>
                        </div>
                    </div>

                    <!-- CSC y Certificado -->
                    <div class="form-section">
                        <h3 class="form-section-title"><i class="fas fa-key"></i> Seguridad</h3>
                        <div class="form-grid">
                            <div class="form-group"><label>ID CSC</label><input type="text" x-model="formData.id_csc" placeholder="0001"></div>
                            <div class="form-group span-3"><label>CSC</label><input type="text" x-model="formData.csc"></div>
                            <div class="form-group span-2">
                                <label>Certificado (.p12)</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="text" x-model="formData.cert_nombre" placeholder="archivo.p12" style="flex:1;" readonly>
                                    <label class="btn-chip btn-chip--primary" style="cursor:pointer;margin:0;padding:6px 12px;">
                                        <i class="fas fa-upload"></i> Subir
                                        <input type="file" accept=".p12,.pfx" @change="subirCertificado($event)" style="display:none;">
                                    </label>
                                    <button type="button" class="btn-chip btn-chip--secondary" @click="seleccionarCertificado()" style="padding:6px 12px;">
                                        <i class="fas fa-folder-open"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group" x-data="{ showPass: false }">
                                <label>Contraseña</label>
                                <div style="position:relative;">
                                    <input :type="showPass ? 'text' : 'password'" x-model="formData.cert_pass" style="width:100%;padding-right:35px;">
                                    <button type="button" @click="showPass = !showPass" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:4px;">
                                        <i :class="showPass ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                                    </button>
                                </div>
                                <div style="margin-top:8px;">
                                    <button type="button" class="btn-chip btn-chip--secondary" @click="validarCertPass()" style="padding:6px 12px;">
                                        <i class="fas fa-check-circle"></i> Validar contraseña
                                    </button>
                                </div>
                            </div>
                            <div class="form-group"><label>Vencimiento</label><input type="date" x-model="formData.cert_fecha_vencimiento"></div>
                        </div>
                    </div>

                    <!-- Documentos Habilitados -->
                    <div class="form-section" x-show="formData.id">
                        <h3 class="form-section-title">
                            <i class="fas fa-file-invoice"></i> Documentos Habilitados
                            <button type="button" class="btn-chip btn-chip--success" style="padding:4px 10px;font-size:11px;margin-left:auto;" @click="agregarDocumento()">
                                <i class="fas fa-plus"></i> Agregar
                            </button>
                        </h3>
                        <div class="tabla-mini">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tipo Documento</th>
                                        <th>Establecimiento</th>
                                        <th>Punto Exp.</th>
                                        <th style="width:60px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="doc in documentosLista" :key="doc.id">
                                        <tr>
                                            <td x-text="doc.tipo_documento_nombre"></td>
                                            <td x-text="doc.codigo_establecimiento"></td>
                                            <td x-text="doc.punto_expedicion"></td>
                                            <td>
                                                <button type="button" class="btn-mini btn-mini--danger" @click="eliminarDocumento(doc.id)" title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="documentosLista.length === 0">
                                        <td colspan="4" style="text-align:center;color:#64748b;">Sin documentos habilitados</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Form agregar documento -->
                        <div x-show="showAddDocumento" class="add-form" style="margin-top:10px;">
                            <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr auto;">
                                <div class="form-group">
                                    <label>Tipo Documento</label>
                                    <select x-model="nuevoDocumento.tipo_documento">
                                        <template x-for="(nombre, tipo) in tiposDocumento" :key="tipo">
                                            <option :value="tipo" x-text="nombre"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Establecimiento</label>
                                    <input type="text" x-model="nuevoDocumento.codigo_establecimiento" placeholder="001" maxlength="3">
                                </div>
                                <div class="form-group">
                                    <label>Punto Exp.</label>
                                    <input type="text" x-model="nuevoDocumento.punto_expedicion" placeholder="001" maxlength="3">
                                </div>
                                <div class="form-group" style="justify-content:flex-end;">
                                    <label>&nbsp;</label>
                                    <div style="display:flex;gap:6px;">
                                        <button type="button" class="btn-chip btn-chip--success" style="padding:6px 12px;" @click="guardarDocumento()"><i class="fas fa-check"></i></button>
                                        <button type="button" class="btn-chip" style="padding:6px 12px;" @click="showAddDocumento=false"><i class="fas fa-times"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actividades Económicas -->
                    <div class="form-section" x-show="formData.id">
                        <h3 class="form-section-title">
                            <i class="fas fa-briefcase"></i> Actividades Económicas
                            <button type="button" class="btn-chip btn-chip--success" style="padding:4px 10px;font-size:11px;margin-left:auto;" @click="agregarActividad()">
                                <i class="fas fa-plus"></i> Agregar
                            </button>
                        </h3>
                        <div class="tabla-mini">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:100px;">Código</th>
                                        <th>Descripción</th>
                                        <th style="width:80px;">Principal</th>
                                        <th style="width:60px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="act in actividadesLista" :key="act.id">
                                        <tr>
                                            <td x-text="act.codigo"></td>
                                            <td x-text="act.descripcion"></td>
                                            <td style="text-align:center;">
                                                <span x-show="act.principal == 1" class="badge badge-success">Sí</span>
                                                <span x-show="act.principal != 1" class="badge badge-info">No</span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn-mini btn-mini--danger" @click="eliminarActividad(act.id)" title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="actividadesLista.length === 0">
                                        <td colspan="4" style="text-align:center;color:#64748b;">Sin actividades económicas</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Form agregar actividad -->
                        <div x-show="showAddActividad" class="add-form" style="margin-top:10px;">
                            <div class="form-grid" style="grid-template-columns: 1fr 3fr auto auto;">
                                <div class="form-group">
                                    <label>Código</label>
                                    <input type="text" x-model="nuevaActividad.codigo" placeholder="47300" maxlength="20">
                                </div>
                                <div class="form-group">
                                    <label>Descripción</label>
                                    <input type="text" x-model="nuevaActividad.descripcion" placeholder="Descripción de la actividad">
                                </div>
                                <div class="form-group">
                                    <label>Principal</label>
                                    <select x-model="nuevaActividad.principal">
                                        <option value="0">No</option>
                                        <option value="1">Sí</option>
                                    </select>
                                </div>
                                <div class="form-group" style="justify-content:flex-end;">
                                    <label>&nbsp;</label>
                                    <div style="display:flex;gap:6px;">
                                        <button type="button" class="btn-chip btn-chip--success" style="padding:6px 12px;" @click="guardarActividad()"><i class="fas fa-check"></i></button>
                                        <button type="button" class="btn-chip" style="padding:6px 12px;" @click="showAddActividad=false"><i class="fas fa-times"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Documentos (modo crear - solo checkboxes) -->
                    <div class="form-section" x-show="!formData.id">
                        <h3 class="form-section-title"><i class="fas fa-file-invoice"></i> Documentos a Habilitar</h3>
                        <div class="documentos-grid">
                            <template x-for="(nombre, tipo) in tiposDocumento" :key="tipo">
                                <div class="documento-item">
                                    <input type="checkbox" :id="'doc_'+tipo" :checked="documentosSeleccionados.includes(parseInt(tipo))" @change="toggleDocumento(parseInt(tipo))">
                                    <label :for="'doc_'+tipo">
                                        <span x-text="nombre"></span>
                                        <div class="establecimiento">001-001</div>
                                    </label>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-chip" @click="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
                    <button class="btn-chip btn-chip--success" @click="guardar()" :disabled="guardando">
                        <i class="fas fa-save"></i> <span x-text="guardando ? 'Guardando...' : 'Guardar'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <script>
        const API_URL = `${window.location.origin}/habilitacion_sifen.php`;
        const ID_EMPRESA_URL = <?php echo (int)($_GET['id_empresa'] ?? 0); ?>;

        function habilitacionApp() {
            return {
                habilitaciones: [],
                empresas: [],
                showModal: false,
                showSifenGuide: false,
                guardando: false,
                ocrProcessing: false,
                gridApi: null,
                gridInstance: null,

                // Sistema de Alertas
                alert: {
                    show: false,
                    type: 'info',
                    title: '',
                    text: '',
                    html: '',
                    showCancel: false,
                    confirmText: 'Aceptar',
                    cancelText: 'Cancelar',
                    confirmDanger: false,
                    input: null,
                    inputOptions: {},
                    inputValue: '',
                    width: null,
                    resolve: null
                },

                tiposDocumento: {
                    1: 'Factura Electrónica',
                    4: 'Autofactura Electrónica',
                    5: 'Nota de Crédito Electrónica',
                    6: 'Nota de Débito Electrónica',
                    7: 'Nota de Remisión Electrónica'
                },

                documentosSeleccionados: [],
                documentosLista: [],
                actividadesLista: [],
                showAddDocumento: false,
                showAddActividad: false,
                nuevoDocumento: {
                    tipo_documento: 1,
                    codigo_establecimiento: '001',
                    punto_expedicion: '001'
                },
                nuevaActividad: {
                    codigo: '',
                    descripcion: '',
                    principal: 0
                },
                formData: {},

                // Autocomplete Empresa
                empresaSearch: '',
                empresasFiltradas: [],
                showEmpresaDropdown: false,
                empresaActiveIndex: 0,

                // Autocomplete Geográfico (cascada)
                geoSearch: {
                    departamento: '',
                    distrito: '',
                    ciudad: '',
                    barrio: ''
                },
                geoResultados: {
                    departamento: [],
                    distrito: [],
                    ciudad: [],
                    barrio: []
                },
                showGeoDropdown: {
                    departamento: false,
                    distrito: false,
                    ciudad: false,
                    barrio: false
                },
                geoActiveIndex: {
                    departamento: 0,
                    distrito: 0,
                    ciudad: 0,
                    barrio: 0
                },
                geoCache: {
                    departamentos: [],
                    distritos: {},
                    ciudades: {},
                    barrios: {}
                },

                // Métodos del Sistema de Alertas
                showAlert(options) {
                    return new Promise(resolve => {
                        this.alert = {
                            show: true,
                            type: options.type || 'info',
                            title: options.title || '',
                            text: options.text || '',
                            html: options.html || '',
                            showCancel: options.showCancel || false,
                            confirmText: options.confirmText || 'Aceptar',
                            cancelText: options.cancelText || 'Cancelar',
                            confirmDanger: options.confirmDanger || false,
                            input: options.input || null,
                            inputOptions: options.inputOptions || {},
                            inputValue: '',
                            width: options.width || null,
                            resolve: resolve
                        };
                        if (options.timer) {
                            setTimeout(() => this.closeAlert(true), options.timer);
                        }
                    });
                },

                closeAlert(confirmed) {
                    const result = {
                        isConfirmed: confirmed,
                        value: this.alert.input ? this.alert.inputValue : confirmed
                    };
                    if (this.alert.resolve) this.alert.resolve(result);
                    this.alert.show = false;
                },

                toast(title, text, type = 'success', timer = 2000) {
                    this.showAlert({
                        title,
                        text,
                        type,
                        timer
                    });
                },

                async init() {
                    window.habApp = this; // Guardar referencia global
                    await this.cargarEmpresas();
                    await this.cargarDatos();
                    this.inicializarGrid();
                    // Asegurar que los datos se carguen en el grid después de inicializarlo
                    if (this.gridApi && this.habilitaciones.length > 0) {
                        this.gridApi.setGridOption('rowData', this.habilitaciones);
                    }
                    if (ID_EMPRESA_URL) {
                        this.$nextTick(() => {
                            setTimeout(() => {
                                this.abrirEdicionPorEmpresa(ID_EMPRESA_URL);
                            }, 0);
                        });
                    }
                },

                async abrirEdicionPorEmpresa(idEmpresa) {
                    if (!idEmpresa) return;
                    try {
                        const res = await fetch(`${API_URL}?action=get_by_empresa&id_empresa=${idEmpresa}`);
                        const data = await res.json();
                        if (data.success && data.data && data.data.id) {
                            await this.editarHabilitacion(data.data.id);
                            return;
                        }
                    } catch (e) {
                        console.error('Error buscando habilitación por empresa:', e);
                    }

                    // Si no existe habilitación, abrir modal con empresa preseleccionada
                    this.resetFormData();
                    const emp = this.empresas.find(e => String(e.id_empresa) === String(idEmpresa));
                    if (emp) {
                        this.formData.id_empresa = emp.id_empresa;
                        this.formData.empresa = emp.empresa;
                        this.empresaSearch = emp.empresa;
                        this.cargarDatosEmpresa();
                    }
                    this.showModal = true;
                },

                async cargarDatos() {
                    try {
                        const res = await fetch(`${API_URL}?action=list`);
                        const data = await res.json();
                        if (data.success) {
                            this.habilitaciones = data.data;
                            // Actualizar grid si existe - múltiples métodos para compatibilidad
                            const api = this.gridApi || this.gridInstance || window.habApp?.gridApi;
                            if (api) {
                                if (typeof api.setGridOption === 'function') {
                                    api.setGridOption('rowData', this.habilitaciones);
                                } else if (typeof api.setRowData === 'function') {
                                    api.setRowData(this.habilitaciones);
                                } else if (typeof api.updateGridOptions === 'function') {
                                    api.updateGridOptions({
                                        rowData: this.habilitaciones
                                    });
                                }
                            }
                        }
                    } catch (e) {
                        console.error('Error cargando datos:', e);
                    }
                },

                async cargarEmpresas() {
                    try {
                        const res = await fetch(`${API_URL}?action=empresas`);
                        const data = await res.json();
                        if (data.success) this.empresas = data.data;
                    } catch (e) {
                        console.error(e);
                    }
                },

                onFilterChanged(e) {
                    if (this.gridApi) this.gridApi.setGridOption('quickFilterText', e.target.value);
                },

                inicializarGrid() {
                    const self = this;
                    const columnDefs = [{
                            headerName: 'ID',
                            field: 'id',
                            width: 70
                        },
                        {
                            headerName: 'Empresa',
                            field: 'nombre_empresa',
                            flex: 1,
                            minWidth: 200
                        },
                        {
                            headerName: 'RUC',
                            field: 'ruc',
                            width: 120,
                            valueFormatter: p => p.data ? `${p.data.ruc}-${p.data.dv}` : ''
                        },
                        {
                            headerName: 'Timbrado',
                            field: 'numero_timbrado',
                            width: 110
                        },
                        {
                            headerName: 'Vigencia',
                            width: 100,
                            valueFormatter: p => p.data?.fecha_inicio_vigencia || '-'
                        },
                        {
                            headerName: 'Ambiente',
                            field: 'ambiente',
                            width: 100,
                            cellRenderer: p => p.value ? `<span class="badge ${p.value === 'PROD' ? 'badge-success' : 'badge-warning'}">${p.value}</span>` : ''
                        },
                        {
                            headerName: 'Docs',
                            width: 70,
                            valueFormatter: p => p.data?.documentos?.length || 0,
                            cellStyle: {
                                textAlign: 'center'
                            }
                        },
                        {
                            headerName: 'Estado',
                            field: 'activo',
                            width: 90,
                            cellRenderer: p => `<span class="badge ${p.value == 1 ? 'badge-success' : 'badge-danger'}">${p.value == 1 ? 'Activo' : 'Inactivo'}</span>`
                        }
                    ];

                    const gridOptions = {
                        theme: "legacy",
                        columnDefs,
                        rowData: self.habilitaciones,

                        // Modelo cliente para soportar agrupación
                        rowModelType: 'clientSide',

                        defaultColDef: {
                            flex: 1,
                            minWidth: 100,
                            resizable: true,
                            sortable: true,
                            filter: true,
                            enableValue: true,
                            menuTabs: ['generalMenuTab', 'filterMenuTab', 'columnsMenuTab']
                        },
                        animateRows: true,
                        rowHeight: 42,
                        headerHeight: 38,
                        rowSelection: 'single',

                        // Menú de columnas en header
                        suppressMenuHide: false,
                        columnMenu: 'legacy',

                        // Sidebar con herramientas
                        sideBar: {
                            toolPanels: [{
                                    id: 'columns',
                                    labelDefault: 'Columnas',
                                    labelKey: 'columns',
                                    iconKey: 'columns',
                                    toolPanel: 'agColumnsToolPanel',
                                    toolPanelParams: {
                                        suppressRowGroups: false,
                                        suppressValues: false,
                                        suppressPivots: true,
                                        suppressPivotMode: true
                                    }
                                },
                                {
                                    id: 'filters',
                                    labelDefault: 'Filtros',
                                    labelKey: 'filters',
                                    iconKey: 'filter',
                                    toolPanel: 'agFiltersToolPanel'
                                }
                            ],
                            position: 'right',
                            defaultToolPanel: ''
                        },

                        // Pivot desactivado
                        pivotMode: false,

                        // Funciones Enterprise
                        enableRangeSelection: true,
                        enableCharts: true,
                        rowGroupPanelShow: 'always',
                        groupDisplayType: 'groupRows',

                        // Status bar
                        statusBar: {
                            statusPanels: [{
                                    statusPanel: 'agTotalAndFilteredRowCountComponent',
                                    align: 'left'
                                },
                                {
                                    statusPanel: 'agSelectedRowCountComponent',
                                    align: 'center'
                                },
                                {
                                    statusPanel: 'agAggregationComponent',
                                    align: 'right'
                                }
                            ]
                        },

                        // Textos en español
                        localeText: {
                            loadingOoo: 'Cargando...',
                            noRowsToShow: 'No hay registros',

                            // Menús y Columnas
                            pinColumn: 'Fijar Columna',
                            valueAggregation: 'Agregación de Valor',
                            autosizeThiscolumn: 'Autoajustar esta columna',
                            autosizeAllColumns: 'Autoajustar todas las columnas',
                            groupBy: 'Agrupar por',
                            ungroupBy: 'Desagrupar por',
                            resetColumns: 'Restablecer Columnas',
                            expandAll: 'Expandir Todo',
                            collapseAll: 'Contraer Todo',
                            toolPanel: 'Panel de Herramientas',

                            // Sidebar / Panel de columnas
                            columns: 'Columnas',
                            filters: 'Filtros',
                            rowGroupColumns: 'Columnas de Agrupación',
                            rowGroupColumnsEmptyMessage: 'Arrastre columnas aquí para agrupar',
                            valueColumns: 'Columnas de Valor',
                            valueColumnsEmptyMessage: 'Arrastre columnas aquí para agregar',
                            pivotColumns: 'Columnas de Pivote',
                            pivotColumnsEmptyMessage: 'Arrastre columnas aquí para pivotar',
                            pivotMode: 'Modo Pivote',
                            groups: 'Grupos de Filas',
                            values: 'Valores',
                            pivots: 'Etiquetas de Columna',

                            // Status bar
                            totalRows: 'Filas Totales',
                            totalAndFilteredRows: 'Filas',
                            filteredRows: 'Filtradas',
                            selectedRows: 'Seleccionadas',
                            more: 'Más',
                            to: 'a',
                            of: 'de',
                            page: 'Página',
                            nextPage: 'Siguiente',
                            lastPage: 'Última',
                            firstPage: 'Primera',
                            previousPage: 'Anterior',

                            // Filtros de Texto/Número
                            filterOoo: 'Filtrar...',
                            equals: 'Igual',
                            notEqual: 'No igual',
                            contains: 'Contiene',
                            notContains: 'No contiene',
                            startsWith: 'Empieza con',
                            endsWith: 'Termina con',
                            lessThan: 'Menor que',
                            lessThanOrEqual: 'Menor o igual que',
                            greaterThan: 'Mayor que',
                            greaterThanOrEqual: 'Mayor o igual que',
                            inRange: 'En rango',
                            blank: 'Vacío',
                            notBlank: 'No Vacío',
                            andCondition: 'Y',
                            orCondition: 'O',
                            applyFilter: 'Aplicar',
                            resetFilter: 'Restablecer',
                            clearFilter: 'Limpiar',

                            // Operaciones
                            copy: 'Copiar',
                            ctrlC: 'Ctrl+C',
                            paste: 'Pegar',
                            ctrlV: 'Ctrl+V',
                            export: 'Exportar',
                            csvExport: 'Exportar CSV',
                            excelExport: 'Exportar Excel',

                            // Agregaciones
                            sum: 'Suma',
                            min: 'Mínimo',
                            max: 'Máximo',
                            avg: 'Promedio',
                            count: 'Cantidad',
                            none: 'Ninguno',

                            // Columnas
                            noPin: 'Sin Fijar',
                            pinLeft: 'Fijar Izquierda',
                            pinRight: 'Fijar Derecha',
                            copyWithHeaders: 'Copiar con encabezados',

                            // Arrastrar
                            rowDragRows: 'filas'
                        },

                        onGridReady: params => {
                            self.gridApi = params.api;
                            window.habApp = self;
                            self.updateGridTheme();
                        },
                        onRowDoubleClicked: params => {
                            self.editarHabilitacion(params.data.id);
                        },
                        getContextMenuItems: params => {
                            if (!params.node) return ['copy', 'export'];
                            const data = params.node.data;
                            return [{
                                    name: 'Editar',
                                    icon: '<i class="fas fa-edit" style="color:#3b82f6;"></i>',
                                    action: () => self.editarHabilitacion(data.id)
                                },
                                {
                                    name: 'Anular',
                                    icon: '<i class="fas fa-ban" style="color:#ef4444;"></i>',
                                    action: () => self.anularHabilitacion(data.id)
                                },
                                'separator',
                                {
                                    name: 'Ver Actividades',
                                    icon: '<i class="fas fa-list" style="color:#f59e0b;"></i>',
                                    action: () => self.verActividades(data.id)
                                },
                                {
                                    name: 'Ver Documentos',
                                    icon: '<i class="fas fa-file-alt" style="color:#22c55e;"></i>',
                                    action: () => self.verSolicitudes(data.id)
                                },
                                'separator',
                                {
                                    name: 'Eliminar',
                                    icon: '<i class="fas fa-trash" style="color:#ef4444;"></i>',
                                    action: () => self.eliminarHabilitacion(data.id)
                                }
                            ];
                        }
                    };

                    const gridDiv = document.querySelector('#habilitacionesGrid');
                    self.gridInstance = agGrid.createGrid(gridDiv, gridOptions);
                    self.gridApi = self.gridInstance; // En AG-Grid moderno, createGrid devuelve el API

                    // Observer para cambios de tema
                    const observer = new MutationObserver((mutations) => {
                        for (const mutation of mutations) {
                            if (mutation.attributeName === 'class') {
                                self.updateGridTheme();
                                break;
                            }
                        }
                    });
                    observer.observe(document.documentElement, {
                        attributes: true,
                        attributeFilter: ['class']
                    });
                },

                updateGridTheme() {
                    const gridDiv = document.querySelector('#habilitacionesGrid');
                    const isDark = document.documentElement.classList.contains('dark');
                    // Remover ambos temas primero
                    gridDiv.classList.remove('ag-theme-quartz', 'ag-theme-quartz-dark');
                    // Agregar el tema correcto
                    gridDiv.classList.add(isDark ? 'ag-theme-quartz-dark' : 'ag-theme-quartz');
                },

                resetFormData() {
                    this.formData = {
                        id: null,
                        id_empresa: '',
                        empresa: '',
                        numero_formulario: '',
                        fecha_formulario: '',
                        ruc: '',
                        dv: '',
                        razon_social: '',
                        nombre_fantasia: '',
                        estado_contribuyente: 'ACTIVO',
                        representante_tipo_doc: 'CI',
                        representante_documento: '',
                        representante_nombre: '',
                        cod_depto: null,
                        departamento: '',
                        cod_distrito: null,
                        distrito: '',
                        cod_ciudad: null,
                        localidad: '',
                        cod_barrio: null,
                        barrio: '',
                        direccion: '',
                        numero_casa: '',
                        telefono: '',
                        telefono_pais: '+595',
                        telefono_numero: '',
                        email: '',
                        sistema_contribuyente: 'SISTEMAX',
                        numero_timbrado: '',
                        fecha_inicio_vigencia: '',
                        fecha_fin_vigencia: '',
                        estado_timbrado: 'ACTIVO',
                        csc: '',
                        id_csc: '0001',
                        cert_nombre: '',
                        cert_pass: '',
                        cert_fecha_vencimiento: '',
                        ambiente: 'TEST',
                        activo: 1
                    };
                    this.documentosSeleccionados = [1, 5, 6, 7];
                    this.documentosLista = [];
                    this.actividadesLista = [];
                    this.showAddDocumento = false;
                    this.showAddActividad = false;
                    // Limpiar geo search
                    this.geoSearch = {
                        departamento: '',
                        distrito: '',
                        ciudad: '',
                        barrio: ''
                    };
                    this.geoResultados = {
                        departamento: [],
                        distrito: [],
                        ciudad: [],
                        barrio: []
                    };
                    this.showGeoDropdown = {
                        departamento: false,
                        distrito: false,
                        ciudad: false,
                        barrio: false
                    };
                },

                abrirModal() {
                    this.resetFormData();
                    this.empresaSearch = '';
                    this.empresasFiltradas = [];
                    this.showEmpresaDropdown = false;
                    this.showModal = true;
                },
                cerrarModal() {
                    this.showModal = false;
                    if (window.parent && window.parent !== window && typeof window.parent.cerrarApp === 'function') {
                        window.parent.cerrarApp();
                    }
                },

                async editarHabilitacion(id) {
                    try {
                        const res = await fetch(`${API_URL}?action=get&id=${id}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            this.formData = {
                                ...data.data
                            };
                            this.documentosSeleccionados = (data.data.documentos || []).map(d => parseInt(d.tipo_documento));
                            this.documentosLista = data.data.documentos || [];
                            // Cargar actividades
                            await this.cargarActividades(id);
                            // Inicializar búsqueda geográfica con valores existentes
                            this.initGeoSearch();
                            // Parsear teléfono en código de país y número
                            this.parsearTelefono();
                            // Inicializar búsqueda de empresa
                            this.empresaSearch = data.data.empresa || '';
                            this.showAddDocumento = false;
                            this.showAddActividad = false;
                            this.showModal = true;
                        }
                    } catch (e) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'No se pudo cargar'
                        });
                    }
                },

                async cargarDocumentos(id) {
                    const res = await fetch(`${API_URL}?action=documentos&id=${id}`);
                    const data = await res.json();
                    if (data.success) this.documentosLista = data.data;
                },

                async cargarActividades(id) {
                    const res = await fetch(`${API_URL}?action=actividades&id=${id}`);
                    const data = await res.json();
                    if (data.success) this.actividadesLista = data.data;
                },

                agregarDocumento() {
                    this.nuevoDocumento = {
                        tipo_documento: 1,
                        codigo_establecimiento: '001',
                        punto_expedicion: '001'
                    };
                    this.showAddDocumento = true;
                },

                async guardarDocumento() {
                    if (!this.formData.id) return;
                    await fetch(`${API_URL}?action=add_documento`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            id_habilitacion: this.formData.id,
                            ...this.nuevoDocumento
                        })
                    });
                    await this.cargarDocumentos(this.formData.id);
                    this.showAddDocumento = false;
                },

                async eliminarDocumento(id) {
                    const result = await this.showAlert({
                        type: 'warning',
                        title: '¿Eliminar documento?',
                        showCancel: true,
                        confirmText: 'Sí'
                    });
                    if (result.isConfirmed) {
                        await fetch(`${API_URL}?action=remove_documento&id=${id}`);
                        await this.cargarDocumentos(this.formData.id);
                    }
                },

                agregarActividad() {
                    this.nuevaActividad = {
                        codigo: '',
                        descripcion: '',
                        principal: 0
                    };
                    this.showAddActividad = true;
                },

                async guardarActividad() {
                    if (!this.formData.id || !this.nuevaActividad.codigo) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Ingrese el código de actividad'
                        });
                        return;
                    }
                    await fetch(`${API_URL}?action=add_actividad`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            id_habilitacion: this.formData.id,
                            ...this.nuevaActividad
                        })
                    });
                    await this.cargarActividades(this.formData.id);
                    this.showAddActividad = false;
                },

                async eliminarActividad(id) {
                    const result = await this.showAlert({
                        type: 'warning',
                        title: '¿Eliminar actividad?',
                        showCancel: true,
                        confirmText: 'Sí'
                    });
                    if (result.isConfirmed) {
                        await fetch(`${API_URL}?action=remove_actividad&id=${id}`);
                        await this.cargarActividades(this.formData.id);
                    }
                },

                // === Métodos para Certificados ===
                async subirCertificado(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    // Validar extensión
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!['p12', 'pfx'].includes(ext)) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Solo se permiten archivos .p12 o .pfx'
                        });
                        event.target.value = '';
                        return;
                    }

                    // Validar tamaño
                    if (file.size > 512000) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'El archivo no puede superar 500KB'
                        });
                        event.target.value = '';
                        return;
                    }

                    const formData = new FormData();
                    formData.append('certificado', file);

                    try {
                        this.showAlert({
                            type: 'loading',
                            title: 'Subiendo certificado...'
                        });

                        const res = await fetch(`${API_URL}?action=upload_certificado`, {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();

                        if (data.success) {
                            this.formData.cert_nombre = data.filename;
                            this.showAlert({
                                type: 'success',
                                title: 'Certificado subido',
                                text: `Archivo: ${data.filename}`,
                                timer: 2000
                            });
                        } else {
                            this.showAlert({
                                type: 'error',
                                title: 'Error',
                                text: data.error || 'Error al subir archivo'
                            });
                        }
                    } catch (error) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Error de conexión'
                        });
                    }
                    event.target.value = '';
                },

                async seleccionarCertificado() {
                    // Cargar lista de certificados existentes
                    try {
                        const res = await fetch(`${API_URL}?action=list_certificados`);
                        const data = await res.json();

                        if (!data.success || data.data.length === 0) {
                            this.showAlert({
                                type: 'info',
                                title: 'Sin certificados',
                                text: 'No hay certificados subidos. Use el botón "Subir" para agregar uno.'
                            });
                            return;
                        }

                        const options = data.data.reduce((acc, cert) => {
                            acc[cert.nombre] = cert.nombre;
                            return acc;
                        }, {});

                        const result = await this.showAlert({
                            type: 'info',
                            title: 'Seleccionar Certificado',
                            input: 'select',
                            inputOptions: options,
                            showCancel: true,
                            confirmText: 'Seleccionar'
                        });

                        if (result.isConfirmed && result.value) {
                            this.formData.cert_nombre = result.value;
                        }
                    } catch (error) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Error al cargar certificados'
                        });
                    }
                },

                async validarCertPass() {
                    if (!this.formData.cert_nombre) {
                        this.showAlert({
                            type: 'warning',
                            title: 'Seleccione un certificado'
                        });
                        return;
                    }

                    if (!this.formData.cert_pass) {
                        this.showAlert({
                            type: 'warning',
                            title: 'Ingrese la contraseña'
                        });
                        return;
                    }

                    try {
                        this.showAlert({
                            type: 'loading',
                            title: 'Validando contraseña...'
                        });

                        const res = await fetch(`${API_URL}?action=validar_cert_pass`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                cert_nombre: this.formData.cert_nombre,
                                cert_pass: this.formData.cert_pass
                            })
                        });
                        const data = await res.json();

                        if (data.success) {
                            this.showAlert({
                                type: 'success',
                                title: 'Contraseña correcta',
                                text: 'El certificado se pudo leer correctamente.'
                            });
                        } else {
                            this.showAlert({
                                type: 'error',
                                title: 'Contraseña inválida',
                                text: data.error || 'No se pudo validar el certificado.'
                            });
                        }
                    } catch (error) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Error de conexión al validar.'
                        });
                    }
                },

                async eliminarHabilitacion(id) {
                    const result = await this.showAlert({
                        type: 'warning',
                        title: '¿Eliminar?',
                        text: 'Esta acción no se puede deshacer',
                        showCancel: true,
                        confirmText: 'Sí, eliminar',
                        confirmDanger: true
                    });
                    if (result.isConfirmed) {
                        await fetch(`${API_URL}?action=delete&id=${id}`);
                        this.toast('Eliminado', '', 'success');
                        this.cargarDatos();
                    }
                },

                async anularHabilitacion(id) {
                    const result = await this.showAlert({
                        type: 'warning',
                        title: '¿Anular habilitación?',
                        text: 'La habilitación quedará inactiva',
                        showCancel: true,
                        confirmText: 'Sí, anular',
                        confirmDanger: true
                    });
                    if (result.isConfirmed) {
                        await fetch(`${API_URL}?action=update&id=${id}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                activo: 0
                            })
                        });
                        this.toast('Anulado', 'La habilitación fue anulada', 'success');
                        this.cargarDatos();
                    }
                },

                async verActividades(id) {
                    const res = await fetch(`${API_URL}?action=actividades&id=${id}`);
                    const data = await res.json();
                    if (data.success && data.data.length > 0) {
                        const html = data.data.map(a => `
                            <div style="padding:8px;margin:4px 0;background:rgba(59,130,246,0.1);border-radius:6px;">
                                <strong>${a.codigo}</strong> ${a.principal == 1 ? '<span class="badge badge-success">Principal</span>' : ''}
                                <div style="font-size:12px;color:#64748b;">${a.descripcion}</div>
                            </div>
                        `).join('');
                        this.showAlert({
                            type: 'info',
                            title: 'Actividades Económicas',
                            html,
                            width: 600
                        });
                    } else {
                        this.showAlert({
                            type: 'info',
                            title: 'Sin actividades',
                            text: 'No hay actividades registradas'
                        });
                    }
                },

                async verSolicitudes(id) {
                    const res = await fetch(`${API_URL}?action=solicitudes&id=${id}`);
                    const data = await res.json();
                    if (data.success && data.data.length > 0) {
                        const html = data.data.map(s => `
                            <div style="padding:8px;margin:4px 0;background:rgba(245,158,11,0.1);border-radius:6px;">
                                <strong>${s.tipo_documento_nombre}</strong> 
                                <span class="badge ${s.estado === 'APROBADO' ? 'badge-success' : s.estado === 'RECHAZADO' ? 'badge-danger' : 'badge-warning'}">${s.estado}</span>
                                <div style="font-size:12px;color:#64748b;">${s.codigo_establecimiento}-${s.punto_expedicion} | ${s.fecha_solicitud || 'Sin fecha'}</div>
                            </div>
                        `).join('');
                        this.showAlert({
                            type: 'info',
                            title: 'Solicitudes de Documentos',
                            html,
                            width: 600
                        });
                    } else {
                        this.showAlert({
                            type: 'info',
                            title: 'Sin solicitudes',
                            text: 'No hay solicitudes registradas'
                        });
                    }
                },

                cargarDatosEmpresa() {
                    const emp = this.empresas.find(e => e.id_empresa == this.formData.id_empresa);
                    if (emp) {
                        this.formData.ruc = emp.ruc;
                        this.formData.dv = emp.dv;
                        this.formData.razon_social = emp.empresa;
                        this.formData.numero_timbrado = emp.timbrado || '';
                    }
                },

                // Métodos Autocomplete Empresa
                filtrarEmpresas() {
                    const busqueda = this.empresaSearch.toLowerCase().trim();
                    if (!busqueda) {
                        this.empresasFiltradas = [];
                        return;
                    }

                    // Dividir búsqueda en palabras
                    const palabras = busqueda.split(/\s+/).filter(p => p.length > 0);

                    this.empresasFiltradas = this.empresas.filter(emp => {
                        const nombre = emp.empresa.toLowerCase();
                        const ruc = emp.ruc.toLowerCase();
                        const texto = `${nombre} ${ruc}`;

                        // Todas las palabras deben coincidir en alguna parte
                        return palabras.every(palabra => texto.includes(palabra));
                    }).slice(0, 10); // Limitar a 10 resultados

                    this.empresaActiveIndex = 0;
                    this.showEmpresaDropdown = true;
                },

                seleccionarEmpresa(emp) {
                    this.formData.id_empresa = emp.id_empresa;
                    this.formData.empresa = emp.empresa;
                    this.empresaSearch = emp.empresa;
                    this.showEmpresaDropdown = false;
                    this.cargarDatosEmpresa();
                },

                seleccionarEmpresaActiva() {
                    if (this.empresasFiltradas.length > 0 && this.empresaActiveIndex >= 0) {
                        this.seleccionarEmpresa(this.empresasFiltradas[this.empresaActiveIndex]);
                    }
                },

                navegarEmpresa(direccion) {
                    if (this.empresasFiltradas.length === 0) return;
                    this.empresaActiveIndex += direccion;
                    if (this.empresaActiveIndex < 0) this.empresaActiveIndex = this.empresasFiltradas.length - 1;
                    if (this.empresaActiveIndex >= this.empresasFiltradas.length) this.empresaActiveIndex = 0;
                },

                limpiarEmpresa() {
                    this.empresaSearch = '';
                    this.formData.id_empresa = '';
                    this.empresasFiltradas = [];
                    this.showEmpresaDropdown = false;
                    // Limpiar datos del formulario
                    this.formData.ruc = '';
                    this.formData.dv = '';
                    this.formData.razon_social = '';
                    this.formData.numero_timbrado = '';
                },

                resaltarCoincidencias(texto, busqueda) {
                    if (!busqueda) return texto;
                    const palabras = busqueda.toLowerCase().split(/\s+/).filter(p => p.length > 0);
                    let resultado = texto;

                    palabras.forEach(palabra => {
                        const regex = new RegExp(`(${this.escapeRegex(palabra)})`, 'gi');
                        resultado = resultado.replace(regex, '<mark>$1</mark>');
                    });

                    return resultado;
                },

                escapeRegex(str) {
                    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                },

                // ==================== MÉTODOS GEO AUTOCOMPLETADO ====================
                async cargarDepartamentos() {
                    if (this.geoCache.departamentos.length > 0) return;
                    try {
                        const res = await fetch(`${API_URL}?action=geo_departamentos`);
                        const data = await res.json();
                        if (data.success) this.geoCache.departamentos = data.data;
                    } catch (e) {
                        console.error('Error cargando departamentos:', e);
                    }
                },

                async buscarGeo(tipo) {
                    const busqueda = this.geoSearch[tipo].toLowerCase().trim();

                    switch (tipo) {
                        case 'departamento':
                            // Cargar departamentos si no están en caché
                            if (this.geoCache.departamentos.length === 0) {
                                await this.cargarDepartamentos();
                            }
                            // Filtrar localmente
                            if (!busqueda) {
                                this.geoResultados.departamento = this.geoCache.departamentos.slice(0, 15);
                            } else {
                                const palabras = busqueda.split(/\s+/).filter(p => p.length > 0);
                                this.geoResultados.departamento = this.geoCache.departamentos.filter(d => {
                                    const texto = d.desc_depto.toLowerCase();
                                    return palabras.every(p => texto.includes(p));
                                }).slice(0, 15);
                            }
                            this.geoActiveIndex.departamento = 0;
                            this.showGeoDropdown.departamento = true;
                            break;

                        case 'distrito':
                            if (!this.formData.cod_depto) return;
                            const codDepto = this.formData.cod_depto;
                            // Cargar distritos del departamento si no están en caché
                            if (!this.geoCache.distritos[codDepto]) {
                                try {
                                    const res = await fetch(`${API_URL}?action=geo_distritos&cod_depto=${codDepto}`);
                                    const data = await res.json();
                                    if (data.success) this.geoCache.distritos[codDepto] = data.data;
                                } catch (e) {
                                    console.error('Error cargando distritos:', e);
                                    return;
                                }
                            }
                            // Filtrar localmente
                            const distritos = this.geoCache.distritos[codDepto] || [];
                            if (!busqueda) {
                                this.geoResultados.distrito = distritos.slice(0, 15);
                            } else {
                                const palabras = busqueda.split(/\s+/).filter(p => p.length > 0);
                                this.geoResultados.distrito = distritos.filter(d => {
                                    const texto = d.desc_distrito.toLowerCase();
                                    return palabras.every(p => texto.includes(p));
                                }).slice(0, 15);
                            }
                            this.geoActiveIndex.distrito = 0;
                            this.showGeoDropdown.distrito = true;
                            break;

                        case 'ciudad':
                            if (!this.formData.cod_distrito) return;
                            const codDistrito = this.formData.cod_distrito;
                            // Cargar ciudades del distrito si no están en caché
                            if (!this.geoCache.ciudades[codDistrito]) {
                                try {
                                    const res = await fetch(`${API_URL}?action=geo_ciudades&cod_distrito=${codDistrito}`);
                                    const data = await res.json();
                                    if (data.success) this.geoCache.ciudades[codDistrito] = data.data;
                                } catch (e) {
                                    console.error('Error cargando ciudades:', e);
                                    return;
                                }
                            }
                            // Filtrar localmente
                            const ciudades = this.geoCache.ciudades[codDistrito] || [];
                            if (!busqueda) {
                                this.geoResultados.ciudad = ciudades.slice(0, 15);
                            } else {
                                const palabras = busqueda.split(/\s+/).filter(p => p.length > 0);
                                this.geoResultados.ciudad = ciudades.filter(c => {
                                    const texto = c.desc_ciudad.toLowerCase();
                                    return palabras.every(p => texto.includes(p));
                                }).slice(0, 15);
                            }
                            this.geoActiveIndex.ciudad = 0;
                            this.showGeoDropdown.ciudad = true;
                            break;

                        case 'barrio':
                            if (!this.formData.cod_ciudad) return;
                            const codCiudad = this.formData.cod_ciudad;
                            // Cargar barrios de la ciudad si no están en caché
                            if (!this.geoCache.barrios[codCiudad]) {
                                try {
                                    const res = await fetch(`${API_URL}?action=geo_barrios&cod_ciudad=${codCiudad}`);
                                    const data = await res.json();
                                    if (data.success) this.geoCache.barrios[codCiudad] = data.data;
                                } catch (e) {
                                    console.error('Error cargando barrios:', e);
                                    return;
                                }
                            }
                            // Filtrar localmente
                            const barrios = this.geoCache.barrios[codCiudad] || [];
                            if (!busqueda) {
                                this.geoResultados.barrio = barrios.slice(0, 15);
                            } else {
                                const palabras = busqueda.split(/\s+/).filter(p => p.length > 0);
                                this.geoResultados.barrio = barrios.filter(b => {
                                    const texto = b.desc_barrio.toLowerCase();
                                    return palabras.every(p => texto.includes(p));
                                }).slice(0, 15);
                            }
                            this.geoActiveIndex.barrio = 0;
                            this.showGeoDropdown.barrio = true;
                            break;
                    }
                },

                seleccionarGeo(tipo, item) {
                    switch (tipo) {
                        case 'departamento':
                            this.formData.cod_depto = item.cod_depto;
                            this.formData.departamento = item.desc_depto;
                            this.geoSearch.departamento = item.desc_depto;
                            this.showGeoDropdown.departamento = false;
                            // Limpiar cascada
                            this.limpiarGeoCascada('departamento');
                            break;

                        case 'distrito':
                            this.formData.cod_distrito = item.cod_distrito;
                            this.formData.distrito = item.desc_distrito;
                            this.geoSearch.distrito = item.desc_distrito;
                            this.showGeoDropdown.distrito = false;
                            // Limpiar cascada
                            this.limpiarGeoCascada('distrito');
                            break;

                        case 'ciudad':
                            this.formData.cod_ciudad = item.cod_ciudad;
                            this.formData.localidad = item.desc_ciudad;
                            this.geoSearch.ciudad = item.desc_ciudad;
                            this.showGeoDropdown.ciudad = false;
                            // Limpiar cascada
                            this.limpiarGeoCascada('ciudad');
                            break;

                        case 'barrio':
                            this.formData.cod_barrio = item.cod_barrio;
                            this.formData.barrio = item.desc_barrio;
                            this.geoSearch.barrio = item.desc_barrio;
                            this.showGeoDropdown.barrio = false;
                            break;
                    }
                },

                limpiarGeoCascada(desde) {
                    // Limpiar campos dependientes en cascada
                    const niveles = ['departamento', 'distrito', 'ciudad', 'barrio'];
                    const idx = niveles.indexOf(desde);

                    for (let i = idx + 1; i < niveles.length; i++) {
                        const nivel = niveles[i];
                        this.geoSearch[nivel] = '';
                        this.geoResultados[nivel] = [];
                        this.showGeoDropdown[nivel] = false;

                        if (nivel === 'distrito') {
                            this.formData.cod_distrito = null;
                            this.formData.distrito = '';
                        } else if (nivel === 'ciudad') {
                            this.formData.cod_ciudad = null;
                            this.formData.localidad = '';
                        } else if (nivel === 'barrio') {
                            this.formData.cod_barrio = null;
                            this.formData.barrio = '';
                        }
                    }
                },

                seleccionarGeoActivo(tipo) {
                    if (this.geoResultados[tipo].length > 0 && this.geoActiveIndex[tipo] >= 0) {
                        this.seleccionarGeo(tipo, this.geoResultados[tipo][this.geoActiveIndex[tipo]]);
                    }
                },

                navegarGeo(tipo, direccion) {
                    if (this.geoResultados[tipo].length === 0) return;
                    this.geoActiveIndex[tipo] += direccion;
                    if (this.geoActiveIndex[tipo] < 0) this.geoActiveIndex[tipo] = this.geoResultados[tipo].length - 1;
                    if (this.geoActiveIndex[tipo] >= this.geoResultados[tipo].length) this.geoActiveIndex[tipo] = 0;
                },

                cerrarGeoDropdown(tipo) {
                    this.showGeoDropdown[tipo] = false;
                },

                limpiarGeo(tipo) {
                    this.geoSearch[tipo] = '';
                    this.geoResultados[tipo] = [];
                    this.showGeoDropdown[tipo] = false;

                    if (tipo === 'departamento') {
                        this.formData.cod_depto = null;
                        this.formData.departamento = '';
                        this.limpiarGeoCascada('departamento');
                    } else if (tipo === 'distrito') {
                        this.formData.cod_distrito = null;
                        this.formData.distrito = '';
                        this.limpiarGeoCascada('distrito');
                    } else if (tipo === 'ciudad') {
                        this.formData.cod_ciudad = null;
                        this.formData.localidad = '';
                        this.limpiarGeoCascada('ciudad');
                    } else if (tipo === 'barrio') {
                        this.formData.cod_barrio = null;
                        this.formData.barrio = '';
                    }
                },

                // Inicializar geo search cuando se edita
                initGeoSearch() {
                    this.geoSearch.departamento = this.formData.departamento || '';
                    this.geoSearch.distrito = this.formData.distrito || '';
                    this.geoSearch.ciudad = this.formData.localidad || '';
                    this.geoSearch.barrio = this.formData.barrio || '';
                },

                // Actualizar teléfono completo desde código de país + número
                actualizarTelefono() {
                    const pais = this.formData.telefono_pais || '+595';
                    const numero = (this.formData.telefono_numero || '').replace(/\D/g, '');
                    this.formData.telefono = numero ? pais + numero : '';
                },

                // Parsear teléfono existente en código de país y número
                parsearTelefono() {
                    const tel = this.formData.telefono || '';
                    if (!tel) {
                        this.formData.telefono_pais = '+595';
                        this.formData.telefono_numero = '';
                        return;
                    }

                    // Lista de códigos de país para detectar
                    const codigos = ['+595', '+54', '+55', '+598', '+591', '+56', '+51', '+593', '+57', '+58', '+52', '+1', '+34'];

                    for (const cod of codigos) {
                        if (tel.startsWith(cod)) {
                            this.formData.telefono_pais = cod;
                            this.formData.telefono_numero = tel.substring(cod.length);
                            return;
                        }
                    }

                    // Si no se detecta código, asumir Paraguay
                    this.formData.telefono_pais = '+595';
                    this.formData.telefono_numero = tel.replace(/^\+/, '');
                },

                toggleDocumento(tipo) {
                    const idx = this.documentosSeleccionados.indexOf(tipo);
                    if (idx >= 0) this.documentosSeleccionados.splice(idx, 1);
                    else this.documentosSeleccionados.push(tipo);
                },

                async guardar() {
                    if (!this.formData.id_empresa && !this.formData.id) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Seleccione empresa'
                        });
                        return;
                    }
                    if (!this.formData.ruc || !this.formData.razon_social || !this.formData.numero_timbrado) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Complete campos requeridos'
                        });
                        return;
                    }

                    this.guardando = true;
                    try {
                        const action = this.formData.id ? 'update' : 'create';
                        const url = this.formData.id ? `${API_URL}?action=${action}&id=${this.formData.id}` : `${API_URL}?action=${action}`;

                        const res = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.formData)
                        });
                        const data = await res.json();

                        if (data.success) {
                            const habId = data.id || this.formData.id;
                            // Actualizar documentos
                            const docsRes = await fetch(`${API_URL}?action=documentos&id=${habId}`);
                            const docsData = await docsRes.json();
                            const actuales = (docsData.data || []).map(d => parseInt(d.tipo_documento));

                            for (const doc of docsData.data || []) {
                                if (!this.documentosSeleccionados.includes(parseInt(doc.tipo_documento))) {
                                    await fetch(`${API_URL}?action=remove_documento&id=${doc.id}`);
                                }
                            }
                            for (const tipo of this.documentosSeleccionados) {
                                if (!actuales.includes(tipo)) {
                                    await fetch(`${API_URL}?action=add_documento`, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json'
                                        },
                                        body: JSON.stringify({
                                            id_habilitacion: habId,
                                            tipo_documento: tipo
                                        })
                                    });
                                }
                            }

                            this.toast('Guardado', '', 'success');
                            this.cerrarModal();
                            this.cargarDatos();
                        } else {
                            this.showAlert({
                                type: 'error',
                                title: 'Error',
                                text: data.error || 'Error al guardar'
                            });
                        }
                    } catch (e) {
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'Error de conexión'
                        });
                    }
                    this.guardando = false;
                },
                async extraerConOCR() {
                    this.ocrProcessing = true;
                    try {
                        // Crear input file invisible
                        const input = document.createElement('input');
                        input.type = 'file';
                        input.accept = 'image/*,application/pdf';
                        input.onchange = async (e) => {
                            const file = e.target.files[0];
                            if (!file) {
                                this.ocrProcessing = false;
                                return;
                            }

                            // Leer archivo
                            const reader = new FileReader();
                            reader.onload = async (event) => {
                                try {
                                    const isMime = file.type.includes('pdf');
                                    if (isMime) {
                                        this.showAlert({
                                            type: 'info',
                                            title: 'OCR',
                                            text: 'Procesando PDF, por favor espere...'
                                        });
                                        await this.procesarPDF(event.target.result);
                                    } else {
                                        this.showAlert({
                                            type: 'info',
                                            title: 'OCR',
                                            text: 'Procesando imagen, por favor espere...'
                                        });
                                        const result = await Tesseract.recognize(event.target.result, 'spa+eng', {
                                            logger: m => console.log('OCR Progress:', m)
                                        });
                                        const text = result.data.text;
                                        console.log('Texto extraído:', text);
                                        this.extraerDatosDelOCR(text);
                                    }
                                    this.showAlert({
                                        type: 'success',
                                        title: 'OCR',
                                        text: 'Datos extraídos exitosamente'
                                    });
                                } catch (err) {
                                    console.error('Error OCR:', err);
                                    this.showAlert({
                                        type: 'error',
                                        title: 'Error OCR',
                                        text: 'No se pudo procesar archivo: ' + err.message
                                    });
                                } finally {
                                    this.ocrProcessing = false;
                                }
                            };
                            if (file.type.includes('pdf')) {
                                reader.readAsArrayBuffer(file);
                            } else {
                                reader.readAsDataURL(file);
                            }
                        };
                        input.click();
                    } catch (e) {
                        console.error('Error abriendo selector de archivo:', e);
                        this.showAlert({
                            type: 'error',
                            title: 'Error',
                            text: 'No se pudo abrir el selector de archivo'
                        });
                        this.ocrProcessing = false;
                    }
                },
                extraerDatosDelOCR(texto) {
                    // Limpiar texto
                    texto = texto.toUpperCase();

                    // Buscar RUC (8 dígitos)
                    const rucMatch = texto.match(/\b(\d{8})\b/);
                    if (rucMatch) {
                        this.formData.ruc = rucMatch[1];
                        // Calcular DV
                        this.formData.dv = this.calcularDVRUC(rucMatch[1]);
                    }

                    // Buscar razón social (palabras después de "RAZÓN" o "EMPRESA")
                    const razonMatch = texto.match(/(?:RAZÓN\s+SOCIAL|EMPRESA|NOMBRE)[\s:]*([A-ZÁÉÍÓÚÑ\s&,.'-]+)/i);
                    if (razonMatch && razonMatch[1]) {
                        this.formData.razon_social = razonMatch[1].trim().slice(0, 100);
                    }

                    // Buscar Timbrado (típicamente 8 dígitos después de "TIMBRADO" o "N°")
                    const timbradoMatch = texto.match(/(?:TIMBRADO|N°|No\s)[:=\s]*([0-9]{8})/i);
                    if (timbradoMatch && timbradoMatch[1]) {
                        this.formData.numero_timbrado = timbradoMatch[1];
                    }

                    // Buscar teléfono
                    let telMatch = texto.match(/\(\+?595\)[\s\-]?9\d{1}[\s\-]?\d{3}[\s\-]?\d{4,5}/);
                    if (telMatch) {
                        const cleanTel = telMatch[0].replace(/[\(\)\s\-]/g, '');
                        if (!cleanTel.startsWith('+')) {
                            this.formData.telefono = '+' + cleanTel;
                        } else {
                            this.formData.telefono = cleanTel;
                        }
                    } else {
                        telMatch = texto.match(/\+595\s*9\d{1}\s*\d{3}\s*\d{4,5}/);
                        if (!telMatch) telMatch = texto.match(/\+595\s*2\d{1}\s*\d{4,5}/);
                        if (!telMatch) telMatch = texto.match(/0(?:9|2)\d{1}\s*\d{3,4}\s*\d{3,4}/);
                        if (!telMatch) telMatch = texto.match(/(?:\+595|0)[0-9\s\-()]{8,}/);

                        if (telMatch) {
                            let cleanTel = telMatch[0].replace(/[\s\-()]/g, '');
                            if (cleanTel.length >= 9) {
                                if (!cleanTel.startsWith('+')) {
                                    if (cleanTel.startsWith('0')) {
                                        cleanTel = '+595' + cleanTel.slice(1);
                                    } else {
                                        cleanTel = '+' + cleanTel;
                                    }
                                }
                                this.formData.telefono = cleanTel;
                            }
                        }
                    }

                    // Buscar email
                    const emailMatch = texto.match(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/);
                    if (emailMatch) {
                        this.formData.email = emailMatch[0].toLowerCase();
                    }
                },
                calcularDVRUC(ruc) {
                    if (!ruc || ruc.length !== 8) return '';
                    const baseMax = 11;
                    let k = 2;
                    let total = 0;
                    for (let i = ruc.length - 1; i >= 0; i--) {
                        total += parseInt(ruc[i]) * k;
                        k++;
                        if (k > baseMax) k = 2;
                    }
                    const resto = total % 11;
                    return String((resto > 1) ? (11 - resto) : 0);
                },
                async procesarPDF(arrayBuffer) {
                    // Configurar worker de PDF.js
                    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

                    const pdf = await pdfjsLib.getDocument({
                        data: arrayBuffer
                    }).promise;
                    let textoCompleto = '';

                    // Procesar cada página
                    for (let i = 1; i <= Math.min(pdf.numPages, 3); i++) {
                        const page = await pdf.getPage(i);
                        const textContent = await page.getTextContent();
                        const pageText = textContent.items.map(item => item.str).join(' ');
                        textoCompleto += pageText + '\n';
                    }

                    console.log('Texto extraído del PDF:', textoCompleto);
                    this.extraerDatosDelOCR(textoCompleto);
                }
            };
        }
    </script>
</body>

</html>
