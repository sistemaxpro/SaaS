<?php

/**
 * Formulario Único Empresa (Alta/Edición)
 * Alpine.js + Tailwind - Sin ScriptCase
 * 
 * Uso:
 *   editar_empresa.php          → Nueva empresa
 *   editar_empresa.php?id=123   → Editar empresa 123
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/Modules/Empresas/SuscripcionController.php';

$masterDb = defined('MASTER_DB') ? MASTER_DB : 'serproc1';

// Tomar id de GET, o si no existe, usar id_empresa de la sesión (empresa abierta)
$idEmpresa = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_SESSION['id_empresa'] ?? 0);
$isEdit = $idEmpresa > 0;

/**
 * Crear base de datos para nueva empresa y usuario soporte
 * @param int $software 1=tienda_169, 2=flota_ovetense
 */
function crearBaseDatosEmpresa($pdo, $idEmpresa, $empresaData, $masterDb, $software = 1)
{
    $result = [
        'db_created' => false,
        'user_created' => false,
        'errors' => [],
        'db_name' => '',
        'tables_created' => 0,
        'tables_created_list' => [],
        'db_source' => ''
    ];

    // Determinar DB source según software
    $dbSource = ($software == 2) ? 'flota_ovetense' : 'tienda_169';
    $result['db_source'] = $dbSource;

    try {
        $dbName = 'empresa_' . $idEmpresa;
        $result['db_name'] = $dbName;

        // 1. Crear la base de datos
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $result['db_created'] = true;

        // 2. Obtener todas las tablas desde $dbSource
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = '{$dbSource}' AND TABLE_TYPE = 'BASE TABLE'");
        $tablas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tablas)) {
            $result['errors'][] = "No se encontraron tablas en {$dbSource}";
            return $result;
        }

        // 3. Copiar todas las tablas desde $dbSource
        foreach ($tablas as $table) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `{$dbSource}`.`{$table}`");
                $result['tables_created']++;
                $result['tables_created_list'][] = $table;
            } catch (Exception $e) {
                $result['errors'][] = "Error en tabla {$table}: " . $e->getMessage();
            }
        }

        // 4. Crear usuario soporte
        $loginSoporte = 'soporte' . $idEmpresa;
        $passwordHash = md5('Armagedon123');
        $nombreEmpresa = $empresaData['empresa'] ?? 'Empresa ' . $idEmpresa;
        $emailEmpresa = $empresaData['email'] ?? '';
        $telefonoEmpresa = $empresaData['telefono'] ?? '';

        // Verificar si existe tabla sec_users
        $hasSecUsers = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'sec_users'")->fetch();
        if ($hasSecUsers) {
            $sqlUser = "INSERT INTO `{$dbName}`.`sec_users` 
                        (login, pswd, name, email, phone, active, priv_admin, ti, id_empresa, id_sucursal, id_grupo, id_caja)
                        VALUES 
                        (:login, :pswd, :name, :email, :phone, 'Y', 'Y', 1, :id_empresa, 1, 1, 1)";
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute([
                ':login' => $loginSoporte,
                ':pswd' => $passwordHash,
                ':name' => 'Soporte TI - ' . $nombreEmpresa,
                ':email' => $emailEmpresa,
                ':phone' => $telefonoEmpresa,
                ':id_empresa' => $idEmpresa
            ]);

            $lastUserId = $pdo->lastInsertId();
            if ($lastUserId) {
                $hasSecUsersGroups = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'sec_users_groups'")->fetch();
                if ($hasSecUsersGroups) {
                    $pdo->exec("INSERT INTO `{$dbName}`.`sec_users_groups` (id_login, id_grupo) VALUES ({$lastUserId}, 1)");
                }
            }
            $result['user_created'] = true;
            $result['login'] = $loginSoporte;
        }
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

/**
 * Sincronizar estructura de tablas desde DB source a una base de datos existente
 * @param int $software 1=tienda_169, 2=flota_ovetense
 */
function sincronizarEstructuraEmpresa($pdo, $dbName, $software = 1)
{
    // Determinar DB source según software
    $dbSource = ($software == 2) ? 'flota_ovetense' : 'tienda_169';

    $result = [
        'db_created' => false,
        'tables_created' => 0,
        'tables_updated' => 0,
        'tables_created_list' => [],
        'tables_updated_list' => [],
        'columns_added_list' => [],
        'errors' => [],
        'db_source' => $dbSource
    ];

    try {
        // Verificar que la base de datos existe
        $checkDb = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'")->fetch();
        if (!$checkDb) {
            // Si no existe, crearla
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $result['db_created'] = true;
        }

        // Obtener tablas existentes en destino
        $stmtDest = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                                 WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_TYPE = 'BASE TABLE'");
        $tablesExisting = $stmtDest->fetchAll(PDO::FETCH_COLUMN);
        $existingSet = array_flip($tablesExisting);

        // Obtener todas las tablas desde $dbSource
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = '{$dbSource}' AND TABLE_TYPE = 'BASE TABLE'");
        $tablesSource = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tablesSource)) {
            $result['errors'][] = "No se encontraron tablas en {$dbSource}";
            return $result;
        }

        // Crear/sincronizar tablas desde $dbSource
        foreach ($tablesSource as $table) {
            if (!isset($existingSet[$table])) {
                // Crear tabla que no existe
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `{$dbSource}`.`{$table}`");
                    $result['tables_created']++;
                    $result['tables_created_list'][] = $table;
                } catch (Exception $e) {
                    $result['errors'][] = "Error creando {$table}: " . $e->getMessage();
                }
            } else {
                // Sincronizar columnas de tabla existente
                try {
                    $colsSource = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS 
                                               WHERE TABLE_SCHEMA = '{$dbSource}' AND TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_COLUMN);
                    $colsDest = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS 
                                             WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_COLUMN);
                    $colsDestSet = array_flip($colsDest);

                    $tableUpdated = false;
                    foreach ($colsSource as $col) {
                        if (!isset($colsDestSet[$col])) {
                            $colDef = $pdo->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA 
                                                   FROM information_schema.COLUMNS 
                                                   WHERE TABLE_SCHEMA = '{$dbSource}' AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'")->fetch(PDO::FETCH_ASSOC);
                            if ($colDef) {
                                $nullable = $colDef['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
                                $default = $colDef['COLUMN_DEFAULT'] !== null ? "DEFAULT " . $pdo->quote($colDef['COLUMN_DEFAULT']) : '';
                                $extra = $colDef['EXTRA'];
                                $pdo->exec("ALTER TABLE `{$dbName}`.`{$table}` ADD COLUMN `{$col}` {$colDef['COLUMN_TYPE']} {$nullable} {$default} {$extra}");
                                $result['columns_added_list'][] = "{$table}.{$col}";
                                $tableUpdated = true;
                            }
                        }
                    }
                    if ($tableUpdated) {
                        $result['tables_updated']++;
                        $result['tables_updated_list'][] = $table;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = "Error sincronizando {$table}: " . $e->getMessage();
                }
            }
        }
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

$action = $_GET['action'] ?? '';

// ======================= API: Consulta RUC SIFEN =======================
if ($action === 'sifen_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rucRaw = trim($_GET['ruc'] ?? '');
        if (strlen($rucRaw) < 5) {
            echo json_encode(['success' => false, 'error' => 'RUC/Cédula muy corta']);
            exit;
        }

        $baseDir = dirname(__DIR__);
        $certNombreFijo = '80118689.p12';
        $certPassFijo = '3nvcEcwW';

        $candidates = [
            $baseDir . '/_lib/php-sifen3-custom/certificados/' . $certNombreFijo,
            $baseDir . '/_lib/php-sifen3/certificados/' . $certNombreFijo,
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
            echo json_encode(['success' => false, 'error' => $dMsgRes]);
            exit;
        }

        $xContr = $xmlRes->xpath('//xContRUC')[0] ?? $xmlRes->xpath('//xContr')[0] ?? null;
        if (!$xContr) {
            throw new Exception('Respuesta inválida de SIFEN');
        }

        $foundNombre = trim((string)($xContr->dRazCons ?? $xContr->dRazSoc ?? ''));
        $foundRucBase = trim((string)($xContr->dRUCCons ?? $xContr->dRUC ?? ''));

        // Calcular DV con algoritmo módulo 11
        function calcularDV($ruc)
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

        $foundDV = calcularDV($foundRucBase);

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
}

// ======================= API: Subir Logo =======================
if ($action === 'upload_logo') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió el archivo');
        }

        $file = $_FILES['logo'];
        $idEmp = (int)($_POST['id_empresa'] ?? 0);

        // Validar tipo MIME
        $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception('Tipo de archivo no permitido');
        }

        // Validar tamaño (2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            throw new Exception('El archivo excede 2MB');
        }

        // Directorio destino
        $uploadDir = dirname(__DIR__) . '/_lib/file/img/empresa/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generar nombre único
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            $ext = 'png';
        }

        $filename = ($idEmp > 0 ? 'empresa_' . $idEmp : 'logo_' . time()) . '.' . $ext;
        $destPath = $uploadDir . $filename;

        // Mover archivo
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('Error al guardar el archivo');
        }

        // Actualizar en la base de datos si ya existe la empresa
        if ($idEmp > 0) {
            $pdo = Database::getMasterConnection();
            $stmt = $pdo->prepare("UPDATE {$masterDb}.empresa SET logos = ? WHERE id_empresa = ?");
            $stmt->execute([$filename, $idEmp]);
        }

        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'path' => '/_lib/file/img/empresa/' . $filename
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Eliminar empresa (con backup) =======================
if ($action === 'delete') {
    // Registrar todos los errores
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', dirname(__DIR__) . '/log_delete_error.txt');

    // Log inicial
    error_log("=== DELETE REQUEST INICIADO ===");
    error_log("Método: " . $_SERVER['REQUEST_METHOD']);
    error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'no especificado'));

    // Limpiar cualquier salida anterior y preparar JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    ob_start();

    // Headers seguros
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    // Array para almacenar el progreso
    $operations = [];
    $pdo = null;

    try {
        // Leer input
        $input = file_get_contents('php://input');
        error_log("Input recibido: " . strlen($input) . " bytes");
        error_log("Input content: " . substr($input, 0, 200));

        $payload = json_decode($input, true);
        if (!$payload) {
            $payload = $_POST;
            error_log("JSON decode falló, usando \$_POST");
            error_log("POST content: " . print_r($_POST, true));
        } else {
            error_log("JSON decode exitoso");
        }

        $id = (int)($payload['id_empresa'] ?? 0);
        $confirmacion = trim($payload['confirmacion'] ?? '');

        error_log("ID: {$id}, Confirmacion: {$confirmacion}");

        if ($id <= 0) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'error' => 'ID de empresa inválido',
                'operations' => []
            ]);
            exit;
        }

        $operations[] = [
            'step' => 'Validación inicial',
            'status' => 'success',
            'details' => "ID empresa: {$id}"
        ];

        // 1. Conectar a la base de datos
        try {
            error_log("Conectando a BD: {$dbHost}/{$masterDb}");
        $pdo = Database::getMasterConnection();

            $operations[] = [
                'step' => 'Conexión a BD',
                'status' => 'success',
                'details' => "Host: {$dbHost}, Base: {$masterDb}"
            ];
        } catch (PDOException $e) {
            $operations[] = [
                'step' => 'Conexión a BD',
                'status' => 'error',
                'details' => 'Error de conexión: ' . $e->getMessage()
            ];
            throw $e;
        } catch (Exception $e) {
            $operations[] = [
                'step' => 'Conexión a BD',
                'status' => 'error',
                'details' => $e->getMessage()
            ];
            throw $e;
        }

        // 2. Obtener datos de la empresa
        try {
            $stmt = $pdo->prepare("SELECT * FROM `" . $masterDb . "`.`empresa` WHERE id_empresa = ?");
            $stmt->execute([$id]);
            $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$empresa) {
                error_log("Empresa no encontrada: id={$id}");
                ob_end_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => 'Empresa no encontrada',
                    'operations' => $operations
                ]);
                exit;
            }

            $operations[] = [
                'step' => 'Consulta empresa',
                'status' => 'success',
                'details' => "Empresa: {$empresa['empresa']}, RUC: {$empresa['ruc']}"
            ];
        } catch (Exception $e) {
            error_log("Error al consultar empresa: " . $e->getMessage());
            $operations[] = [
                'step' => 'Consulta empresa',
                'status' => 'error',
                'details' => $e->getMessage()
            ];
            throw $e;
        }

        // 3. Validar confirmación (aceptar RUC o RUC-DV)
        // Construir ambas versiones del RUC
        $rucSolo = $empresa['ruc'];
        $rucConDv = $empresa['ruc'] . '-' . $empresa['dv'];
        $confirmacionLimpia = strtoupper(trim($confirmacion));

        // Normalizar para comparación
        $rucSoloNorm = strtoupper($rucSolo);
        $rucConDvNorm = strtoupper($rucConDv);

        $rucValido = ($confirmacionLimpia === $rucSoloNorm || $confirmacionLimpia === $rucConDvNorm);

        if (!$rucValido) {
            error_log("RUC no coincide. Esperado: {$rucSoloNorm} o {$rucConDvNorm}, Recibido: {$confirmacionLimpia}");
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => "Confirmación incorrecta. Debe escribir el RUC exacto ({$rucConDv}).",
                'operations' => $operations
            ]);
            exit;
        }

        $operations[] = [
            'step' => 'Validación RUC',
            'status' => 'success',
            'details' => "RUC confirmado correctamente ({$confirmacionLimpia})"
        ];

        // 4. Obtener nombre de la base de datos
        $dbName = $empresa['dbase'] ?: 'empresa_' . $id;
        $backupDir = dirname(__DIR__) . '/_lib/file/backupDB';
        $backupFile = '';
        $backupSuccess = false;
        $dbExists = false;

        $operations[] = [
            'step' => 'Preparación',
            'status' => 'success',
            'details' => "Base de datos a eliminar: {$dbName}"
        ];

        // 5. Crear directorio de backup
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // 6. Verificar existencia de la base de datos
        try {
            $checkDb = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'")->fetch();
            $dbExists = $checkDb ? true : false;

            $operations[] = [
                'step' => 'Verificación BD',
                'status' => 'success',
                'details' => $dbExists ? "Base de datos encontrada ({$dbName})" : "Base de datos no existe"
            ];
        } catch (Exception $e) {
            $operations[] = [
                'step' => 'Verificación BD',
                'status' => 'error',
                'details' => $e->getMessage()
            ];
            throw $e;
        }

        // 7. Hacer backup si la base de datos existe
        if ($dbExists) {
            $timestamp = date('Ymd_His');
            $backupFile = "{$backupDir}/{$dbName}_{$timestamp}.sql";

            // Intentar con mysqldump
            // Usar putenv para pasar contraseña seguramente
            putenv("MYSQL_PWD=" . $dbPass);

            $cmd = sprintf(
                'mysqldump -h %s -u %s --skip-extended-insert %s > %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($backupFile)
            );

            error_log("Ejecutando mysqldump: {$dbName}");
            $output = [];
            $returnCode = null;
            exec($cmd, $output, $returnCode);
            putenv("MYSQL_PWD=");  // Limpiar variable de ambiente

            if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 100) {
                $backupSuccess = true;
                $fileSize = filesize($backupFile);

                $operations[] = [
                    'step' => 'Backup mysqldump',
                    'status' => 'success',
                    'details' => "Archivo guardado: " . basename($backupFile) . " ({$fileSize} bytes)"
                ];
            } else {
                // Backup alternativo con PHP
                error_log("mysqldump falló (returnCode: {$returnCode}), usando backup PHP");
                error_log("Output mysqldump: " . implode(" | ", $output));

                $operations[] = [
                    'step' => 'Backup mysqldump',
                    'status' => 'warning',
                    'details' => 'mysqldump falló (código: ' . $returnCode . '), intentando método alternativo'
                ];

                $backupContent = "-- Backup de {$dbName}\n";
                $backupContent .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
                $backupContent .= "-- Empresa: {$empresa['empresa']}\n";
                $backupContent .= "-- RUC: {$empresa['ruc']}\n\n";

                try {
                    error_log("Iniciando backup PHP de {$dbName}");

                    // Las consultas a information_schema funcionan desde cualquier base de datos
                    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$dbName}'")->fetchAll(PDO::FETCH_COLUMN);
                    $backupContent .= "-- Total de tablas: " . count($tables) . "\n\n";
                    error_log("Se encontraron " . count($tables) . " tablas en {$dbName}");

                    foreach ($tables as $table) {
                        try {
                            // Usar información_schema para obtener CREATE TABLE
                            $createStmt = $pdo->query("SELECT CONCAT('CREATE TABLE `', TABLE_NAME, '` (', GROUP_CONCAT(COLUMN_DEFINITION ORDER BY ORDINAL_POSITION SEPARATOR ', '), ')') as CreateStatement FROM (SELECT TABLE_NAME, ORDINAL_POSITION, CONCAT('`', COLUMN_NAME, '` ', COLUMN_TYPE, IF(IS_NULLABLE = 'NO', ' NOT NULL', ''), IF(COLUMN_DEFAULT IS NOT NULL, CONCAT(' DEFAULT ', COLUMN_DEFAULT), '')) as COLUMN_DEFINITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}') AS cols GROUP BY TABLE_NAME")->fetch(PDO::FETCH_ASSOC);

                            if ($createStmt && isset($createStmt['CreateStatement'])) {
                                $backupContent .= $createStmt['CreateStatement'] . ";\n\n";
                            }
                        } catch (Exception $e) {
                            error_log("Error al procesar tabla {$table}: " . $e->getMessage());
                            $backupContent .= "-- Error en tabla {$table}: " . $e->getMessage() . "\n\n";
                        }
                    }

                    error_log("Escribiendo backup en: {$backupFile}");
                    if (file_put_contents($backupFile, $backupContent) !== false) {
                        $backupSuccess = true;
                        $fileSize = filesize($backupFile);
                        error_log("Backup PHP guardado exitosamente: {$fileSize} bytes");

                        $operations[] = [
                            'step' => 'Backup alternativo',
                            'status' => 'success',
                            'details' => "Backup PHP guardado: " . basename($backupFile) . " ({$fileSize} bytes)"
                        ];
                    } else {
                        error_log("No se pudo escribir el archivo de backup en {$backupFile}");
                        $operations[] = [
                            'step' => 'Backup alternativo',
                            'status' => 'error',
                            'details' => 'No se pudo escribir el archivo de backup'
                        ];
                    }
                } catch (Exception $e) {
                    error_log("Excepción durante backup PHP: " . $e->getMessage());
                    $operations[] = [
                        'step' => 'Backup alternativo',
                        'status' => 'error',
                        'details' => 'Error en backup: ' . $e->getMessage()
                    ];
                    throw $e;
                }
            }
        }

        // 8. Eliminar la base de datos (solo si backup fue exitoso)
        if ($dbExists) {
            if ($backupSuccess) {
                try {
                    error_log("Eliminando base de datos: {$dbName}");
                    $pdo->exec("DROP DATABASE IF EXISTS `" . $dbName . "`");

                    error_log("Base de datos {$dbName} eliminada exitosamente");

                    $operations[] = [
                        'step' => 'Eliminar BD',
                        'status' => 'success',
                        'details' => "Base de datos {$dbName} eliminada correctamente"
                    ];
                } catch (PDOException $e) {
                    error_log("Error PDO al eliminar BD: " . $e->getMessage());
                    $operations[] = [
                        'step' => 'Eliminar BD',
                        'status' => 'error',
                        'details' => 'Error al eliminar BD: ' . $e->getMessage()
                    ];
                    throw $e;
                } catch (Exception $e) {
                    error_log("Error al eliminar BD: " . $e->getMessage());
                    $operations[] = [
                        'step' => 'Eliminar BD',
                        'status' => 'error',
                        'details' => $e->getMessage()
                    ];
                    throw $e;
                }
            } else {
                $operations[] = [
                    'step' => 'Eliminar BD',
                    'status' => 'error',
                    'details' => 'No se pudo crear el backup. Base de datos NO fue eliminada por seguridad.'
                ];

                ob_end_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => 'No se pudo crear el backup. La base de datos NO fue eliminada por seguridad.',
                    'operations' => $operations
                ]);
                exit;
            }
        }

        // 9. Eliminar registro de empresa
        try {
            error_log("Eliminando registro de empresa id={$id}");
            $stmtDel = $pdo->prepare("DELETE FROM `" . $masterDb . "`.`empresa` WHERE id_empresa = ?");
            $stmtDel->execute([$id]);

            $rowsDeleted = $stmtDel->rowCount();
            error_log("Registros eliminados: {$rowsDeleted}");

            $operations[] = [
                'step' => 'Eliminar registro',
                'status' => 'success',
                'details' => "Registro de empresa eliminado de la tabla maestro ({$rowsDeleted} filas afectadas)"
            ];
        } catch (PDOException $e) {
            error_log("Error PDO al eliminar registro: " . $e->getMessage());
            $operations[] = [
                'step' => 'Eliminar registro',
                'status' => 'error',
                'details' => 'Error al eliminar registro: ' . $e->getMessage()
            ];
            throw $e;
        } catch (Exception $e) {
            error_log("Error al eliminar registro: " . $e->getMessage());
            $operations[] = [
                'step' => 'Eliminar registro',
                'status' => 'error',
                'details' => $e->getMessage()
            ];
            throw $e;
        }

        // Respuesta exitosa
        ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => true,
            'message' => 'Empresa eliminada correctamente',
            'backup_file' => $backupSuccess ? basename($backupFile) : null,
            'backup_path' => $backupSuccess ? $backupFile : null,
            'db_deleted' => $dbExists,
            'operations' => $operations
        ]);
    } catch (PDOException $e) {
        // Error de PDO
        error_log("PDOException: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Error de base de datos: ' . $e->getMessage(),
            'operations' => $operations ?? []
        ]);
    } catch (Exception $e) {
        // Error general
        error_log("Exception: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'operations' => $operations ?? []
        ]);
    } catch (Throwable $e) {
        // Error crítico
        error_log("Throwable: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Error crítico: ' . $e->getMessage(),
            'operations' => $operations ?? []
        ]);
    } finally {
        // Cerrar conexión
        error_log("=== DELETE REQUEST FINALIZADO ===");
        if ($pdo) {
            $pdo = null;
        }
    }
    exit;
}

// ======================= API: Obtener empresa =======================
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido']);
            exit;
        }

        $pdo = Database::getMasterConnection();

        $stmt = $pdo->prepare("SELECT * FROM {$masterDb}.empresa WHERE id_empresa = ?");
        $stmt->execute([$id]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empresa) {
            echo json_encode(['success' => false, 'error' => 'Empresa no encontrada']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $empresa]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Guardar empresa =======================
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['id_empresa'] ?? 0);
        $isUpdate = $id > 0;

        $pdo = Database::getMasterConnection();

        // Obtener columnas permitidas
        $colsStmt = $pdo->query("SHOW COLUMNS FROM {$masterDb}.empresa");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $allowed = array_flip($cols);

        // Campos a guardar
        $data = [
            'empresa' => trim($payload['empresa'] ?? ''),
            'nombre_fantasia' => trim($payload['nombre_fantasia'] ?? ''),
            'ruc' => trim($payload['ruc'] ?? ''),
            'dv' => trim($payload['dv'] ?? ''),
            'telefono' => trim($payload['telefono'] ?? ''),
            'telefono2' => trim($payload['telefono2'] ?? ''),
            'email' => trim($payload['email'] ?? ''),
            'email2' => trim($payload['email2'] ?? ''),
            'direccion' => trim($payload['direccion'] ?? ''),
            'numero_casa' => trim($payload['numero_casa'] ?? ''),
            'ciudad' => trim($payload['ciudad'] ?? ''),
            'pais' => trim($payload['pais'] ?? ''),
            'activo' => (int)($payload['activo'] ?? 1),
            'software' => (int)($payload['software'] ?? 1),
            'dbase' => trim($payload['dbase'] ?? ''),
            'logos' => trim($payload['logos'] ?? ''),
            'rubro' => trim($payload['rubro'] ?? ''),
            'web' => (int)($payload['web'] ?? 0),
            'web_url' => trim($payload['web_url'] ?? ''),
            'web_ck' => trim($payload['web_ck'] ?? ''),
            'web_cs' => trim($payload['web_cs'] ?? ''),
            'factura_electronica' => (int)($payload['factura_electronica'] ?? 0),
            'ambiente_sifen' => trim($payload['ambiente_sifen'] ?? ''),
            'timbrado' => trim($payload['timbrado'] ?? ''),
            'vigencia_ini' => $payload['vigencia_ini'] ?: null,
            'vigencia_fin' => $payload['vigencia_fin'] ?: null,
        ];

        // Validaciones obligatorias
        if (empty($data['empresa'])) {
            echo json_encode(['success' => false, 'error' => 'El nombre de empresa es obligatorio']);
            exit;
        }
        if (empty($data['ruc'])) {
            echo json_encode(['success' => false, 'error' => 'El RUC es obligatorio']);
            exit;
        }

        // Filtrar solo campos permitidos
        $filtered = [];
        foreach ($data as $k => $v) {
            if (isset($allowed[$k])) {
                $filtered[$k] = $v;
            }
        }

        if ($isUpdate) {
            // UPDATE
            // Forzar siempre empresa_{id} como nombre de base de datos
            $dbName = 'empresa_' . $id;

            // Obtener base de datos anterior
            $stmtOld = $pdo->prepare("SELECT dbase FROM {$masterDb}.empresa WHERE id_empresa = ?");
            $stmtOld->execute([$id]);
            $oldDbName = $stmtOld->fetchColumn() ?: '';

            $migrationResult = [
                'migrated' => false,
                'old_db' => $oldDbName,
                'backup_file' => '',
                'tables_migrated' => 0,
                'migration_errors' => []
            ];

            // Si la DB anterior es diferente a empresa_{id}, migrar datos
            if (!empty($oldDbName) && $oldDbName !== $dbName) {
                // Verificar si la DB anterior existe
                $checkOldDb = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$oldDbName}'")->fetch();

                if ($checkOldDb) {
                    // 1. Crear la nueva base de datos si no existe
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                    // 2. Obtener todas las tablas de la DB anterior
                    $tablesOld = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                                              WHERE TABLE_SCHEMA = '{$oldDbName}' AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

                    // 3. Migrar cada tabla con estructura y datos
                    foreach ($tablesOld as $table) {
                        try {
                            // Crear tabla en destino si no existe
                            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `{$oldDbName}`.`{$table}`");
                            // Copiar datos
                            $pdo->exec("INSERT IGNORE INTO `{$dbName}`.`{$table}` SELECT * FROM `{$oldDbName}`.`{$table}`");
                            $migrationResult['tables_migrated']++;
                        } catch (Exception $e) {
                            $migrationResult['migration_errors'][] = "Error migrando {$table}: " . $e->getMessage();
                        }
                    }

                    // 4. Crear backup de la DB anterior
                    $backupDir = dirname(__DIR__) . '/_lib/file/backupDB';
                    if (!is_dir($backupDir)) {
                        mkdir($backupDir, 0755, true);
                    }

                    $timestamp = date('Ymd_His');
                    $backupFile = "{$backupDir}/{$oldDbName}_{$timestamp}.sql";

                    $cmd = sprintf(
                        'mysqldump -h%s -u%s -p%s %s > %s 2>&1',
                        escapeshellarg($dbHost),
                        escapeshellarg($dbUser),
                        escapeshellarg($dbPass),
                        escapeshellarg($oldDbName),
                        escapeshellarg($backupFile)
                    );

                    exec($cmd, $output, $returnCode);

                    if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 100) {
                        $migrationResult['backup_file'] = basename($backupFile);
                        $migrationResult['migrated'] = true;
                    } else {
                        // Backup alternativo con PHP
                        $backupContent = "-- Backup de {$oldDbName} - " . date('Y-m-d H:i:s') . "\n";
                        $backupContent .= "-- Migrado a: {$dbName}\n\n";

                        foreach ($tablesOld as $table) {
                            try {
                                $createStmt = $pdo->query("SHOW CREATE TABLE `{$oldDbName}`.`{$table}`")->fetch(PDO::FETCH_ASSOC);
                                $backupContent .= $createStmt['Create Table'] . ";\n\n";
                            } catch (Exception $e) {
                                $backupContent .= "-- Error en tabla {$table}\n\n";
                            }
                        }

                        file_put_contents($backupFile, $backupContent);
                        $migrationResult['backup_file'] = basename($backupFile);
                        $migrationResult['migrated'] = true;
                    }
                }
            }

            $filtered['dbase'] = $dbName;

            $sets = [];
            foreach ($filtered as $k => $v) {
                $sets[] = "{$k} = :{$k}";
            }
            $sql = "UPDATE {$masterDb}.empresa SET " . implode(', ', $sets) . " WHERE id_empresa = :id_empresa";
            $stmt = $pdo->prepare($sql);
            foreach ($filtered as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':id_empresa', $id);
            $stmt->execute();

            // Completar permisos de admin al grupo soporte_(id_empresa)
            $grupoName = "soporte_{$id}";
            try {
                // Verificar si existe el grupo soporte_{id_empresa}
                $stmtGrupo = $pdo->prepare("SELECT id_grupo FROM {$masterDb}.sec_groups WHERE grupo = ?");
                $stmtGrupo->execute([$grupoName]);
                $grupoRow = $stmtGrupo->fetch(PDO::FETCH_ASSOC);

                if ($grupoRow) {
                    $idGrupo = $grupoRow['id_grupo'];

                    // Obtener todas las aplicaciones disponibles
                    $appsStmt = $pdo->query("SELECT id_app FROM {$masterDb}.sec_apps");
                    $apps = $appsStmt->fetchAll(PDO::FETCH_COLUMN);

                    // Para cada aplicación, verificar si el grupo tiene acceso con permisos admin
                    foreach ($apps as $idApp) {
                        $checkStmt = $pdo->prepare(
                            "SELECT id_app FROM {$masterDb}.sec_groups_apps WHERE id_grupo = ? AND id_app = ?"
                        );
                        $checkStmt->execute([$idGrupo, $idApp]);
                        $exists = $checkStmt->fetch();

                        if (!$exists) {
                            // Crear registro con permisos admin
                            $insertStmt = $pdo->prepare(
                                "INSERT INTO {$masterDb}.sec_groups_apps (id_grupo, id_app, admin, view, create, update, delete, export, print)
                                 VALUES (?, ?, 1, 1, 1, 1, 1, 1, 1)"
                            );
                            $insertStmt->execute([$idGrupo, $idApp]);
                        } else {
                            // Si existe, actualizar a permisos admin si no los tiene
                            $updateStmt = $pdo->prepare(
                                "UPDATE {$masterDb}.sec_groups_apps SET admin = 1, view = 1, create = 1, update = 1, delete = 1, export = 1, print = 1
                                 WHERE id_grupo = ? AND id_app = ?"
                            );
                            $updateStmt->execute([$idGrupo, $idApp]);
                        }
                    }
                }
            } catch (Exception $e) {
                // Registrar error pero continuar sin detener la actualización
                error_log("Error al completar permisos de soporte: " . $e->getMessage());
            }
            // Sincronizar estructura desde DB source (crea la DB si no existe)
            $software = isset($filtered['software']) ? (int)$filtered['software'] : 1;
            $syncResult = sincronizarEstructuraEmpresa($pdo, $dbName, $software);

            echo json_encode([
                'success' => true,
                'id' => $id,
                'db_name' => $dbName,
                'db_source' => $syncResult['db_source'] ?? '',
                'db_created' => $syncResult['db_created'] ?? false,
                'old_db_name' => $migrationResult['old_db'],
                'migration' => $migrationResult,
                'message' => $migrationResult['migrated']
                    ? "Empresa actualizada. Datos migrados desde {$oldDbName}"
                    : 'Empresa actualizada correctamente',
                'sync_tables_created' => $syncResult['tables_created'] ?? 0,
                'sync_tables_updated' => $syncResult['tables_updated'] ?? 0,
                'sync_tables_created_list' => $syncResult['tables_created_list'] ?? [],
                'sync_tables_updated_list' => $syncResult['tables_updated_list'] ?? [],
                'sync_columns_added_list' => $syncResult['columns_added_list'] ?? [],
                'sync_errors' => $syncResult['errors'] ?? []
            ]);
        } else {
            // INSERT
            $columns = array_keys($filtered);
            $placeholders = array_map(fn($c) => ':' . $c, $columns);
            $sql = "INSERT INTO {$masterDb}.empresa (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            foreach ($filtered as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->execute();

            $newIdEmpresa = $pdo->lastInsertId();

            // Crear base de datos y usuario soporte para nueva empresa
            $software = isset($filtered['software']) ? (int)$filtered['software'] : 1;
            $setupResult = crearBaseDatosEmpresa($pdo, $newIdEmpresa, $filtered, $masterDb, $software);
            $suscripcionAuto = SuscripcionController::asegurarSuscripcionEmpresa((int)$newIdEmpresa, [
                'created_by' => (int)($_SESSION['id_login'] ?? 0),
            ]);

            // Actualizar dbase en la empresa
            $pdo->prepare("UPDATE {$masterDb}.empresa SET dbase = ? WHERE id_empresa = ?")->execute(['empresa_' . $newIdEmpresa, $newIdEmpresa]);

            echo json_encode([
                'success' => true,
                'id' => $newIdEmpresa,
                'message' => 'Empresa creada correctamente',
                'db_created' => $setupResult['db_created'] ?? false,
                'user_created' => $setupResult['user_created'] ?? false,
                'suscripcion_auto' => $suscripcionAuto,
                'db_name' => $setupResult['db_name'] ?? '',
                'db_source' => $setupResult['db_source'] ?? '',
                'sync_tables_created' => $setupResult['tables_created'] ?? 0,
                'sync_tables_created_list' => $setupResult['tables_created_list'] ?? [],
                'sync_tables_updated_list' => [],
                'sync_columns_added_list' => [],
                'setup_errors' => $setupResult['errors'] ?? [],
                'sync_errors' => $setupResult['errors'] ?? []
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" x-data="empresaForm()" @load="init()" class="h-full">

<head>
    <meta charset="utf-8">
    <title><?php echo $isEdit ? 'Editar Empresa' : 'Nueva Empresa'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: {
                            850: '#1e293b'
                        }
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/js/intlTelInput.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/css/intlTelInput.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        (function() {
            function applyTheme(mode) {
                document.documentElement.classList.toggle('dark', mode === 'dark');
            }
            const stored = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            applyTheme(stored || (systemDark ? 'dark' : 'light'));

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (!localStorage.getItem('theme')) applyTheme(e.matches ? 'dark' : 'light');
            });

            window.addEventListener('message', (event) => {
                if (event.data && event.data.type === 'theme') applyTheme(event.data.value);
            });
        })();
    </script>
    <style>
        [x-cloak] {
            display: none !important;
        }

        * {
            font-family: 'Inter', system-ui, sans-serif;
        }

        /* Scrollbar minimalista */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.4);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.6);
        }

        .dark ::-webkit-scrollbar-thumb {
            background: rgba(71, 85, 105, 0.5);
        }

        /* Animaciones suaves */
        .fade-in {
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-4px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Botones estilo chip */
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
            box-shadow: none;
            transition: all 0.2s;
            font-size: 13px;
        }

        .btn-chip:hover {
            background: rgba(59, 130, 246, 0.05);
            transform: translateY(-1px);
        }

        .btn-chip--primary {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .btn-chip--primary:hover {
            background: rgba(59, 130, 246, 0.08);
        }

        .btn-chip--success {
            border-color: #22c55e;
            color: #22c55e;
        }

        .btn-chip--success:hover {
            background: rgba(34, 197, 94, 0.08);
        }

        .btn-chip--danger {
            border-color: #ef4444;
            color: #ef4444;
        }

        .btn-chip--danger:hover {
            background: rgba(239, 68, 68, 0.08);
        }

        .btn-chip--warning {
            border-color: #f59e0b;
            color: #f59e0b;
        }

        .btn-chip--warning:hover {
            background: rgba(245, 158, 11, 0.08);
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800 h-full">
    <!-- Modal Notificación -->
    <div x-data="notifModal()" x-show="show" x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @keydown.escape.window="close()">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden fade-in"
            @click.outside="close()">
            <div class="p-8 text-center">
                <div class="mx-auto mb-5 w-16 h-16 rounded-full flex items-center justify-center"
                    :class="{
                        'bg-emerald-100 dark:bg-emerald-900/40': type === 'success',
                        'bg-red-100 dark:bg-red-900/40': type === 'error',
                        'bg-amber-100 dark:bg-amber-900/40': type === 'warning',
                        'bg-blue-100 dark:bg-blue-900/40': type === 'info'
                    }">
                    <i x-show="type === 'success'" class="fas fa-check text-2xl text-emerald-600 dark:text-emerald-400"></i>
                    <i x-show="type === 'error'" class="fas fa-times text-2xl text-red-600 dark:text-red-400"></i>
                    <i x-show="type === 'warning'" class="fas fa-exclamation text-2xl text-amber-600 dark:text-amber-400"></i>
                    <i x-show="type === 'info'" class="fas fa-info text-2xl text-blue-600 dark:text-blue-400"></i>
                </div>
                <h3 class="text-xl font-semibold text-slate-800 dark:text-white mb-2" x-text="title"></h3>
                <p class="text-slate-500 dark:text-slate-400" x-text="message"></p>
            </div>
            <div class="px-8 pb-8">
                <button @click="close()" class="w-full py-3 rounded-xl font-medium text-white transition-all duration-200 transform hover:scale-[1.02] active:scale-[0.98]"
                    :class="{
                        'bg-emerald-500 hover:bg-emerald-600': type === 'success',
                        'bg-red-500 hover:bg-red-600': type === 'error',
                        'bg-amber-500 hover:bg-amber-600': type === 'warning',
                        'bg-blue-500 hover:bg-blue-600': type === 'info'
                    }">Aceptar</button>
            </div>
        </div>
    </div>

    <!-- Modal Progreso Sincronización -->
    <div x-data="progressModal()" x-show="show" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @show-progress.window="open($event.detail)"
        @update-progress.window="update($event.detail)"
        @finish-progress.window="finish($event.detail)">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden fade-in">
            <div class="p-6">
                <!-- Header -->
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center"
                        :class="finished ? 'bg-emerald-100 dark:bg-emerald-900/40' : 'bg-blue-100 dark:bg-blue-900/40'">
                        <i x-show="!finished" class="fas fa-database text-xl text-blue-600 dark:text-blue-400 animate-pulse"></i>
                        <i x-show="finished && !hasErrors" class="fas fa-check text-xl text-emerald-600 dark:text-emerald-400"></i>
                        <i x-show="finished && hasErrors" class="fas fa-exclamation-triangle text-xl text-amber-600 dark:text-amber-400"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800 dark:text-white" x-text="title"></h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400" x-text="subtitle"></p>
                    </div>
                </div>

                <!-- Barra de progreso -->
                <div class="mb-4" x-show="!finished">
                    <div class="flex justify-between text-xs text-slate-500 dark:text-slate-400 mb-1">
                        <span x-text="currentStep"></span>
                        <span x-text="progress + '%'"></span>
                    </div>
                    <div class="h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-blue-500 to-blue-600 rounded-full transition-all duration-300"
                            :style="'width: ' + progress + '%'"></div>
                    </div>
                </div>

                <!-- Log de pasos -->
                <div class="max-h-48 overflow-y-auto mb-4 bg-slate-50 dark:bg-slate-900/50 rounded-lg p-3 text-sm font-mono">
                    <template x-for="(log, index) in logs" :key="index">
                        <div class="flex items-start gap-2 py-1" :class="log.type === 'error' ? 'text-red-500' : log.type === 'success' ? 'text-emerald-500' : 'text-slate-600 dark:text-slate-400'">
                            <i :class="{
                                'fas fa-check-circle': log.type === 'success',
                                'fas fa-times-circle': log.type === 'error',
                                'fas fa-spinner fa-spin': log.type === 'loading',
                                'fas fa-info-circle': log.type === 'info'
                            }" class="mt-0.5 text-xs"></i>
                            <span x-text="log.message" class="text-xs"></span>
                        </div>
                    </template>
                </div>

                <!-- Resumen final -->
                <div x-show="finished" class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 mb-4">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="text-center p-2 bg-white dark:bg-slate-800 rounded-lg">
                            <div class="text-2xl font-bold text-blue-600" x-text="tablesCreated"></div>
                            <div class="text-xs text-slate-500">Tablas creadas</div>
                        </div>
                        <div class="text-center p-2 bg-white dark:bg-slate-800 rounded-lg">
                            <div class="text-2xl font-bold text-emerald-600" x-text="columnsAdded"></div>
                            <div class="text-xs text-slate-500">Columnas agregadas</div>
                        </div>
                    </div>
                    <div x-show="errorsCount > 0" class="mt-3 text-center text-amber-600 dark:text-amber-400 text-sm">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <span x-text="errorsCount + ' errores durante el proceso'"></span>
                    </div>
                </div>

                <!-- Botón Aceptar -->
                <button x-show="finished" @click="close()"
                    class="w-full py-3 rounded-xl font-medium text-white transition-all duration-200 transform hover:scale-[1.02] active:scale-[0.98]"
                    :class="hasErrors ? 'bg-amber-500 hover:bg-amber-600' : 'bg-emerald-500 hover:bg-emerald-600'">
                    <i class="fas fa-check mr-2"></i>Aceptar
                </button>
            </div>
        </div>
    </div>

    <div class="w-full h-full p-4 overflow-auto">
        <div class="w-full h-full">
            <!-- Card principal -->
            <div class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl shadow-xl shadow-slate-200/50 dark:shadow-slate-900/50 rounded-2xl border border-slate-200/50 dark:border-slate-700/50 overflow-hidden h-full flex flex-col">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-slate-200/50 dark:border-slate-700/50 bg-gradient-to-r from-slate-50 to-transparent dark:from-slate-800/50 flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/30">
                            <i class="fas fa-building text-white"></i>
                        </div>
                        <div>
                            <h1 class="text-lg font-bold text-slate-800 dark:text-white" x-text="isEdit ? 'Editar Empresa' : 'Nueva Empresa'"></h1>
                            <p class="text-xs text-slate-500 dark:text-slate-400" x-show="isEdit" x-text="'ID: ' + form.id_empresa"></p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button x-show="isEdit"
                            class="btn-chip btn-chip--danger"
                            @click="confirmarEliminar()">
                            <i class="fas fa-trash-alt"></i>Eliminar
                        </button>
                        <button class="btn-chip" @click="resetForm()">
                            <i class="fas fa-undo"></i>Restaurar
                        </button>
                        <button class="btn-chip btn-chip--success"
                            @click="guardar()" :disabled="loading">
                            <i class="fas fa-save" x-show="!loading"></i>
                            <i class="fas fa-spinner fa-spin" x-show="loading"></i>
                            <span x-text="loading ? 'Guardando...' : 'Guardar'"></span>
                        </button>
                        <button class="btn-chip btn-chip--warning"
                            @click="cerrarModal()">
                            <i class="fas fa-sign-out-alt"></i>Salir
                        </button>
                    </div>
                </div>

                <div class="p-6 space-y-6 overflow-y-auto flex-1">
                    <!-- Sección: Datos Principales -->
                    <section class="fade-in">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-7 h-7 rounded-lg bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                                    <i class="fas fa-id-card text-blue-600 dark:text-blue-400 text-xs"></i>
                                </div>
                                <h2 class="text-base font-semibold text-slate-800 dark:text-white">Datos Principales</h2>
                            </div>
                            <button type="button"
                                class="btn-chip btn-chip--primary text-xs"
                                @click="extraerConOCR()"
                                :disabled="ocrProcessing">
                                <i class="fas fa-camera" x-show="!ocrProcessing"></i>
                                <i class="fas fa-spinner fa-spin" x-show="ocrProcessing"></i>
                                <span x-text="ocrProcessing ? 'Procesando OCR...' : 'OCR'"></span>
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                            <div class="md:col-span-3 relative">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1.5">RUC <span class="text-red-500">*</span></label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.ruc"
                                    @input.debounce.600ms="buscarRucSifen()"
                                    @blur="buscarRucSifen()"
                                    placeholder="80012345"
                                    x-init="$nextTick(() => { if (!isEdit) $el.focus(); })">
                                <div x-show="loadingRuc" class="absolute inset-0 flex items-center justify-center bg-white/90 dark:bg-slate-900/90 rounded-xl mt-8">
                                    <span class="text-sm text-blue-600 dark:text-blue-400 font-medium flex items-center gap-2">
                                        <i class="fas fa-circle-notch fa-spin"></i> Consultando SET...
                                    </span>
                                </div>
                            </div>
                            <div class="md:col-span-1">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">DV <span class="text-red-500">*</span></label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white text-center font-bold text-lg focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.dv" maxlength="1">
                            </div>
                            <div class="md:col-span-5">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Razón Social <span class="text-red-500">*</span></label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.empresa"
                                    @input="form.nombre_fantasia = form.empresa"
                                    placeholder="Nombre de la empresa">
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Rubro</label>
                                <select class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none cursor-pointer"
                                    x-model="form.rubro">
                                    <option value="tienda">Tienda</option>
                                    <option value="estacion">Estación de Servicio</option>
                                    <option value="restaurant">Restaurant</option>
                                    <option value="servicios">Servicios</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Nombre Fantasía</label>
                                <input type="text"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.nombre_fantasia"
                                    placeholder="Nombre comercial">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Estado</label>
                                <div class="flex gap-4 mt-3">
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" x-model="form.activo" value="1" class="w-5 h-5 text-emerald-500 border-2 border-slate-300 focus:ring-emerald-500">
                                        <span class="flex items-center gap-2 text-slate-700 dark:text-slate-300 group-hover:text-emerald-600 transition">
                                            <i class="fas fa-check-circle text-emerald-500"></i> Activo
                                        </span>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer group">
                                        <input type="radio" x-model="form.activo" value="0" class="w-5 h-5 text-red-500 border-2 border-slate-300 focus:ring-red-500">
                                        <span class="flex items-center gap-2 text-slate-700 dark:text-slate-300 group-hover:text-red-600 transition">
                                            <i class="fas fa-times-circle text-red-500"></i> Inactivo
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <!-- Campo Software -->
                        <div class="mt-4">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Software / Tipo de Sistema</label>
                            <select x-model="form.software"
                                class="w-full md:w-1/2 px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none">
                                <option value="1">📦 Stock - Sistema de Inventario/Ventas</option>
                                <option value="2">🚚 Transportadora - Sistema de Flota</option>
                            </select>
                            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                                Define la estructura de base de datos a utilizar
                            </p>
                        </div>
                    </section>

                    <!-- Divisor -->
                    <div class="border-t border-slate-200 dark:border-slate-700"></div>

                    <!-- Sección: Contacto -->
                    <section class="fade-in">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                                <i class="fas fa-phone text-emerald-600 dark:text-emerald-400 text-sm"></i>
                            </div>
                            <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Contacto</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Teléfono Principal</label>
                                <input id="tel1" type="tel"
                                    class="w-full px-3 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.telefono"
                                    placeholder="+595 981 123456">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Teléfono Secundario</label>
                                <input id="tel2" type="tel"
                                    class="w-full px-3 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                    x-model="form.telefono2"
                                    placeholder="+595 21 123456">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Email Principal</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-envelope"></i></span>
                                    <input type="email"
                                        class="w-full pl-11 pr-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.email"
                                        placeholder="contacto@empresa.com">
                                </div>
                            </div>
                        </div>

                        <!-- Divisor -->
                        <div class="border-t border-slate-200 dark:border-slate-700"></div>

                        <!-- Sección: Ubicación -->
                        <section class="fade-in">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center">
                                    <i class="fas fa-map-marker-alt text-purple-600 dark:text-purple-400 text-sm"></i>
                                </div>
                                <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Ubicación</h2>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                                <div class="md:col-span-6">
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Dirección</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.direccion"
                                        placeholder="Av. Principal c/ Calle Secundaria">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Nro. Casa</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.numero_casa"
                                        placeholder="123">
                                </div>
                                <div class="md:col-span-4">
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Ciudad</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.ciudad"
                                        placeholder="Asunción">
                                </div>
                            </div>
                        </section>

                        <!-- Divisor -->
                        <div class="border-t border-slate-200 dark:border-slate-700"></div>

                        <!-- Sección: Facturación Electrónica -->
                        <section class="fade-in">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                                    <i class="fas fa-file-invoice text-amber-600 dark:text-amber-400 text-sm"></i>
                                </div>
                                <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Facturación Electrónica</h2>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">SIFEN Activo</label>
                                    <div class="flex gap-4 mt-3">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="radio" x-model="form.factura_electronica" value="1" class="w-5 h-5 text-blue-500">
                                            <span class="text-slate-700 dark:text-slate-300">Sí</span>
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="radio" x-model="form.factura_electronica" value="0" class="w-5 h-5 text-blue-500">
                                            <span class="text-slate-700 dark:text-slate-300">No</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Timbrado</label>
                                    <input type="text"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                        x-model="form.timbrado"
                                        placeholder="12345678">
                                </div>
                            </div>
                        </section>

                        <!-- Divisor -->
                        <div class="border-t border-slate-200 dark:border-slate-700"></div>

                        <!-- Sección: Sistema (colapsable) -->
                        <section class="fade-in" x-data="{ open: false }">
                            <button @click="open = !open" class="w-full flex items-center justify-between group">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center">
                                        <i class="fas fa-cog text-slate-600 dark:text-slate-400 text-sm"></i>
                                    </div>
                                    <h2 class="text-lg font-semibold text-slate-800 dark:text-white">Sistema & Configuración</h2>
                                </div>
                                <i class="fas fa-chevron-down text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                            </button>
                            <div x-show="open" x-collapse class="mt-6">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Base de Datos</label>
                                        <input type="text"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                            x-model="form.dbase"
                                            placeholder="nombre_base_datos">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Logo</label>
                                        <div class="flex items-center gap-4">
                                            <!-- Preview del logo -->
                                            <div class="flex-shrink-0">
                                                <template x-if="logoPreview || form.logos">
                                                    <img :src="logoPreview || ('/_lib/file/img/empresa/' + form.logos)"
                                                        class="w-[120px] h-[120px] object-contain rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-800"
                                                        alt="Logo preview">
                                                </template>
                                                <template x-if="!logoPreview && !form.logos">
                                                    <div class="w-[120px] h-[120px] rounded-lg border-2 border-dashed border-slate-300 dark:border-slate-600 flex items-center justify-center bg-slate-50 dark:bg-slate-800">
                                                        <i class="fas fa-image text-3xl text-slate-300 dark:text-slate-600"></i>
                                                    </div>
                                                </template>
                                            </div>
                                            <!-- Input file y nombre -->
                                            <div class="flex-1 space-y-2">
                                                <input type="text"
                                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 text-sm"
                                                    x-model="form.logos"
                                                    placeholder="Nombre del archivo"
                                                    readonly>
                                                <label class="flex items-center justify-center gap-2 px-4 py-2 rounded-lg border border-blue-500 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors">
                                                    <i class="fas fa-upload"></i>
                                                    <span class="text-sm font-medium">Subir Logo</span>
                                                    <input type="file"
                                                        class="hidden"
                                                        accept="image/*"
                                                        @change="subirLogo($event)">
                                                </label>
                                                <p class="text-xs text-slate-400">PNG, JPG o GIF. Máx 2MB</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">WooCommerce</label>
                                        <select class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none cursor-pointer"
                                            x-model="form.web">
                                            <option value="0">Deshabilitado</option>
                                            <option value="1">Habilitado</option>
                                        </select>
                                    </div>
                                </div>
                                <div x-show="form.web == 1" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">URL Tienda</label>
                                        <input type="text"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white placeholder-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                            x-model="form.web_url"
                                            placeholder="https://mitienda.com">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Consumer Key</label>
                                        <input type="text"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-mono text-sm focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                            x-model="form.web_ck">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Consumer Secret</label>
                                        <input type="password"
                                            class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-mono text-sm focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none"
                                            x-model="form.web_cs">
                                    </div>
                                </div>
                            </div>
                        </section>
                </div>
            </div>
        </div>
    </div>

    <script>
        function notifModal() {
            return {
                show: false,
                title: '',
                message: '',
                type: 'info',
                open(title, message, type = 'info') {
                    this.title = title;
                    this.message = message;
                    this.type = type;
                    this.show = true;
                },
                close() {
                    this.show = false;
                }
            };
        }

        function progressModal() {
            return {
                show: false,
                title: '',
                subtitle: '',
                currentStep: '',
                progress: 0,
                logs: [],
                finished: false,
                hasErrors: false,
                tablesCreated: 0,
                columnsAdded: 0,
                errorsCount: 0,
                open(detail) {
                    this.show = true;
                    this.title = detail.title || 'Procesando...';
                    this.subtitle = detail.subtitle || '';
                    this.currentStep = detail.step || 'Iniciando...';
                    this.progress = 0;
                    this.logs = [];
                    this.finished = false;
                    this.hasErrors = false;
                    this.tablesCreated = 0;
                    this.columnsAdded = 0;
                    this.errorsCount = 0;
                    if (detail.log) {
                        this.logs.push({
                            message: detail.log,
                            type: 'loading'
                        });
                    }
                },
                update(detail) {
                    if (detail.step) this.currentStep = detail.step;
                    if (detail.progress !== undefined) this.progress = detail.progress;
                    if (detail.log) {
                        // Actualizar último log si era loading
                        if (this.logs.length > 0 && this.logs[this.logs.length - 1].type === 'loading') {
                            this.logs[this.logs.length - 1] = {
                                message: detail.log,
                                type: detail.logType || 'success'
                            };
                        } else {
                            this.logs.push({
                                message: detail.log,
                                type: detail.logType || 'info'
                            });
                        }
                    }
                    if (detail.addLog) {
                        this.logs.push({
                            message: detail.addLog,
                            type: detail.logType || 'loading'
                        });
                    }
                },
                finish(detail) {
                    this.finished = true;
                    this.progress = 100;
                    this.currentStep = 'Completado';
                    this.tablesCreated = detail.tablesCreated || 0;
                    this.columnsAdded = detail.columnsAdded || 0;
                    this.errorsCount = detail.errorsCount || 0;
                    this.hasErrors = this.errorsCount > 0;
                    if (detail.log) {
                        this.logs.push({
                            message: detail.log,
                            type: detail.logType || 'success'
                        });
                    }
                },
                close() {
                    this.show = false;
                }
            };
        }

        window.showProgress = function(detail) {
            window.dispatchEvent(new CustomEvent('show-progress', {
                detail
            }));
        };
        window.updateProgress = function(detail) {
            window.dispatchEvent(new CustomEvent('update-progress', {
                detail
            }));
        };
        window.finishProgress = function(detail) {
            window.dispatchEvent(new CustomEvent('finish-progress', {
                detail
            }));
        };

        window.showNotif = function(title, message, type = 'info') {
            window.dispatchEvent(new CustomEvent('show-notif', {
                detail: {
                    title,
                    message,
                    type
                }
            }));
        };

        // Inicializar intl-tel-input después de que cargue el DOM
        document.addEventListener('DOMContentLoaded', () => {
            // Campo teléfono principal
            const tel1 = document.querySelector('#tel1');
            if (tel1) {
                const iti1 = intlTelInput(tel1, {
                    initialCountry: 'py',
                    preferredCountries: ['py', 'ar', 'br', 'cl', 'co'],
                    separateDialCode: true,
                    utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/js/utils.js'
                });
                tel1.addEventListener('change', () => {
                    const fullNumber = iti1.getNumber();
                    document.querySelector('[x-data*="empresaForm"]').__x.$data.form.telefono = fullNumber;
                });
            }

            // Campo teléfono secundario
            const tel2 = document.querySelector('#tel2');
            if (tel2) {
                const iti2 = intlTelInput(tel2, {
                    initialCountry: 'py',
                    preferredCountries: ['py', 'ar', 'br', 'cl', 'co'],
                    separateDialCode: true,
                    utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/js/utils.js'
                });
                tel2.addEventListener('change', () => {
                    const fullNumber = iti2.getNumber();
                    document.querySelector('[x-data*="empresaForm"]').__x.$data.form.telefono2 = fullNumber;
                });
            }
        });

        document.addEventListener('alpine:init', () => {
            window.addEventListener('show-notif', (e) => {
                const el = document.querySelector('[x-data="notifModal()"]');
                if (el && el.__x) {
                    el.__x.$data.open(e.detail.title, e.detail.message, e.detail.type);
                }
            });
        });

        function empresaForm() {
            const idEmpresa = <?php echo json_encode($idEmpresa); ?>;
            const isEditMode = <?php echo json_encode($isEdit); ?>;

            return {
                loading: false,
                loadingRuc: false,
                ocrProcessing: false,
                isEdit: isEditMode,
                logoPreview: null,
                _nombreFantasiaAnterior: '',
                form: {
                    id_empresa: idEmpresa,
                    empresa: '',
                    nombre_fantasia: '',
                    ruc: '',
                    dv: '',
                    telefono: '',
                    telefono2: '',
                    email: '',
                    email2: '',
                    direccion: 'Mariscal López',
                    numero_casa: '123',
                    ciudad: 'Asunción',
                    pais: 'Paraguay',
                    activo: 1,
                    software: 1,
                    dbase: '',
                    logos: '',
                    rubro: 'tienda',
                    web: 0,
                    web_url: '',
                    web_ck: '',
                    web_cs: '',
                    factura_electronica: 0,
                    ambiente_sifen: '',
                    timbrado: '',
                    vigencia_ini: '',
                    vigencia_fin: ''
                },
                init() {
                    if (this.isEdit && this.form.id_empresa > 0) {
                        this.cargarEmpresa();
                    }
                },
                async cargarEmpresa() {
                    this.loading = true;
                    try {
                        const res = await fetch(`editar_empresa.php?action=get&id=${this.form.id_empresa}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            // Mapear campos
                            Object.keys(this.form).forEach(key => {
                                if (data.data[key] !== undefined && data.data[key] !== null) {
                                    this.form[key] = data.data[key];
                                }
                            });
                        } else {
                            window.showNotif('Error', data.error || 'No se pudo cargar la empresa', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        window.showNotif('Error', 'Error de conexión', 'error');
                    } finally {
                        this.loading = false;
                    }
                },
                async buscarRucSifen() {
                    const ruc = (this.form.ruc || '').trim();
                    if (this.loadingRuc || ruc.length < 5 || this.isEdit) return;
                    this.loadingRuc = true;
                    try {
                        const res = await fetch(`editar_empresa.php?action=sifen_lookup&ruc=${encodeURIComponent(ruc)}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            if (data.data.ruc_base) this.form.ruc = data.data.ruc_base;
                            if (data.data.dv) this.form.dv = data.data.dv;
                            if (data.data.razon_social) this.form.empresa = data.data.razon_social;
                        } else if (data.error) {
                            window.showNotif('SIFEN', data.error, 'info');
                        }
                    } catch (e) {
                        console.error('SIFEN lookup error', e);
                    } finally {
                        this.loadingRuc = false;
                    }
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
                                        window.showNotif('OCR', 'Procesando PDF, por favor espere...', 'info');
                                        await this.procesarPDF(event.target.result);
                                    } else {
                                        window.showNotif('OCR', 'Procesando imagen, por favor espere...', 'info');
                                        const result = await Tesseract.recognize(event.target.result, 'spa+eng', {
                                            logger: m => console.log('OCR Progress:', m)
                                        });
                                        const text = result.data.text;
                                        console.log('Texto extraído:', text);
                                        this.extraerDatosDelOCR(text);
                                    }
                                    window.showNotif('OCR', 'Datos extraídos exitosamente', 'success');
                                } catch (err) {
                                    console.error('Error OCR:', err);
                                    window.showNotif('Error OCR', 'No se pudo procesar archivo: ' + err.message, 'error');
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
                        window.showNotif('Error', 'No se pudo abrir el selector de archivo', 'error');
                        this.ocrProcessing = false;
                    }
                },
                extraerDatosDelOCR(texto) {
                    // Limpiar texto
                    texto = texto.toUpperCase();

                    // Buscar RUC (8 dígitos)
                    const rucMatch = texto.match(/\b(\d{8})\b/);
                    if (rucMatch) {
                        this.form.ruc = rucMatch[1];
                        // Calcular DV
                        this.form.dv = this.calcularDVRUC(rucMatch[1]);
                        // Buscar en SIFEN después de un tiempo
                        if (!this.isEdit) {
                            setTimeout(() => this.buscarRucSifen(), 500);
                        }
                    }

                    // Buscar razón social (palabras después de "RAZÓN" o "EMPRESA")
                    const razonMatch = texto.match(/(?:RAZÓN\s+SOCIAL|EMPRESA|NOMBRE)[\s:]*([A-ZÁÉÍÓÚÑ\s&,.'-]+)/i);
                    if (razonMatch && razonMatch[1]) {
                        this.form.empresa = razonMatch[1].trim().slice(0, 100);
                    }

                    // Buscar teléfono (formato: +595 9XX XXXXXX, +595 2X XXXXXX, 0XXX XXXXXX)
                    // Primero busca código entre paréntesis: (+595) 981 123456 o (595) 981 123456
                    let telMatch = texto.match(/\(\+?595\)[\s\-]?9\d{1}[\s\-]?\d{3}[\s\-]?\d{4,5}/);
                    // Si encuentra código entre paréntesis, convertir a formato estándar
                    if (telMatch) {
                        const cleanTel = telMatch[0].replace(/[\(\)\s\-]/g, '');
                        if (!cleanTel.startsWith('+')) {
                            this.form.telefono = '+' + cleanTel;
                        } else {
                            this.form.telefono = cleanTel;
                        }
                    } else {
                        // Si no encuentra con paréntesis, intenta con +595 seguido de espacio y dígitos
                        telMatch = texto.match(/\+595\s*9\d{1}\s*\d{3}\s*\d{4,5}/);
                        // Si no encuentra, intenta con +595 2
                        if (!telMatch) telMatch = texto.match(/\+595\s*2\d{1}\s*\d{4,5}/);
                        // Si no encuentra, intenta con 0 local
                        if (!telMatch) telMatch = texto.match(/0(?:9|2)\d{1}\s*\d{3,4}\s*\d{3,4}/);
                        // Fallback: busca cualquier secuencia de números con guiones/espacios
                        if (!telMatch) telMatch = texto.match(/(?:\+595|0)[0-9\s\-()]{8,}/);

                        if (telMatch) {
                            // Limpiar espacios, guiones y paréntesis innecesarios
                            let cleanTel = telMatch[0].replace(/[\s\-()]/g, '');
                            // Asegurar que tenga longitud válida (9-12 dígitos)
                            if (cleanTel.length >= 9) {
                                if (!cleanTel.startsWith('+')) {
                                    // Si empieza con 0, reemplazar por +595
                                    if (cleanTel.startsWith('0')) {
                                        cleanTel = '+595' + cleanTel.slice(1);
                                    } else {
                                        cleanTel = '+' + cleanTel;
                                    }
                                }
                                this.form.telefono = cleanTel;
                            }
                        }
                    }

                    // Buscar email
                    const emailMatch = texto.match(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/);
                    if (emailMatch) {
                        this.form.email = emailMatch[0].toLowerCase();
                    }

                    // Buscar ciudad
                    const ciudades = ['ASUNCIÓN', 'ENCARNACIÓN', 'CIUDAD DEL ESTE', 'VILLARRICA', 'CONCEPCIÓN', 'CAAGUAZÚ', 'CORONEL OVIEDO'];
                    for (const ciudad of ciudades) {
                        if (texto.includes(ciudad)) {
                            this.form.ciudad = ciudad.charAt(0) + ciudad.slice(1).toLowerCase();
                            break;
                        }
                    }

                    // Buscar Timbrado (típicamente 8 dígitos después de "TIMBRADO" o "N°")
                    const timbradoMatch = texto.match(/(?:TIMBRADO|N°|No\s)[:=\s]*([0-9]{8})/i);
                    if (timbradoMatch && timbradoMatch[1]) {
                        this.form.timbrado = timbradoMatch[1];
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
                },
                resetForm() {
                    if (this.isEdit) {
                        this.cargarEmpresa();
                    } else {
                        Object.assign(this.form, {
                            id_empresa: 0,
                            empresa: '',
                            nombre_fantasia: '',
                            ruc: '',
                            dv: '',
                            telefono: '',
                            telefono2: '',
                            email: '',
                            email2: '',
                            direccion: 'Mariscal López',
                            numero_casa: '123',
                            ciudad: 'Asunción',
                            pais: 'Paraguay',
                            activo: 1,
                            dbase: '',
                            logos: '',
                            rubro: 'tienda',
                            web: 0,
                            web_url: '',
                            web_ck: '',
                            web_cs: '',
                            factura_electronica: 0,
                            ambiente_sifen: '',
                            timbrado: '',
                            vigencia_ini: '',
                            vigencia_fin: ''
                        });
                    }
                },
                async confirmarEliminar() {
                    if (!this.isEdit || !this.form.id_empresa) return;

                    // Construir RUC completo
                    const rucCompleto = this.form.ruc + '-' + this.form.dv;

                    // Modal de confirmación crítica
                    const modalHtml = `
                        <div id="deleteConfirmModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.8)">
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-lg w-full p-6 border-2 border-red-500">
                                <div class="text-center mb-6">
                                    <div class="w-20 h-20 mx-auto rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mb-4">
                                        <i class="fas fa-exclamation-triangle text-4xl text-red-600"></i>
                                    </div>
                                    <h2 class="text-2xl font-bold text-red-600 mb-2">⚠️ OPERACIÓN CRÍTICA</h2>
                                    <p class="text-slate-600 dark:text-slate-300 font-medium">Estás a punto de eliminar permanentemente:</p>
                                </div>
                                
                                <div class="bg-slate-100 dark:bg-slate-700 rounded-lg p-4 mb-4">
                                    <p class="font-bold text-slate-800 dark:text-white text-lg">${this.form.empresa}</p>
                                    <p class="text-slate-600 dark:text-slate-400">RUC: ${rucCompleto}</p>
                                    <p class="text-slate-600 dark:text-slate-400">Base de datos: ${this.form.dbase || 'empresa_' + this.form.id_empresa}</p>
                                </div>

                                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-4">
                                    <p class="text-red-700 dark:text-red-300 text-sm font-medium mb-2">
                                        <i class="fas fa-info-circle mr-1"></i> Esta acción:
                                    </p>
                                    <ul class="text-red-600 dark:text-red-400 text-sm space-y-1 ml-4">
                                        <li>• Eliminará la base de datos completa</li>
                                        <li>• Borrará todas las facturas, clientes y datos</li>
                                        <li>• Se creará un backup antes de eliminar</li>
                                        <li>• <strong>NO SE PUEDE DESHACER</strong></li>
                                    </ul>
                                </div>

                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                        Para confirmar, escribe el RUC: <strong class="text-red-600">${rucCompleto}</strong>
                                    </label>
                                    <input type="text" id="deleteConfirmInput" 
                                        class="w-full px-4 py-3 rounded-lg border-2 border-red-300 dark:border-red-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-white focus:border-red-500 focus:ring-4 focus:ring-red-500/20 outline-none font-mono text-lg"
                                        placeholder="Escribe el RUC aquí..." autocomplete="off">
                                </div>

                                <div class="flex gap-3">
                                    <button id="cancelDeleteBtn" class="flex-1 px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-medium hover:bg-slate-100 dark:hover:bg-slate-700 transition-all">
                                        <i class="fas fa-times mr-2"></i>Cancelar
                                    </button>
                                    <button id="confirmDeleteBtn" class="flex-1 px-4 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                        <i class="fas fa-trash-alt mr-2"></i>Eliminar Empresa
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;

                    document.body.insertAdjacentHTML('beforeend', modalHtml);
                    const modal = document.getElementById('deleteConfirmModal');
                    const input = document.getElementById('deleteConfirmInput');
                    const confirmBtn = document.getElementById('confirmDeleteBtn');
                    const cancelBtn = document.getElementById('cancelDeleteBtn');
                    // Aceptar tanto RUC solo como RUC-DV
                    const rucToMatchFull = rucCompleto;
                    const rucToMatchBase = this.form.ruc;

                    // Capturar referencia a this para usarla en el event listener
                    const self = this;

                    input.focus();

                    input.addEventListener('input', () => {
                        const inputVal = input.value.trim().toUpperCase();
                        const isValid = inputVal === rucToMatchFull.toUpperCase() || inputVal === rucToMatchBase.toUpperCase();
                        confirmBtn.disabled = !isValid;
                    });

                    cancelBtn.addEventListener('click', () => modal.remove());

                    confirmBtn.addEventListener('click', async () => {
                        // Validar que el RUC ingresado es correcto
                        const inputVal = input.value.trim().toUpperCase();
                        const isValid = inputVal === rucToMatchFull.toUpperCase() || inputVal === rucToMatchBase.toUpperCase();

                        if (!isValid) {
                            window.showNotif('RUC Inválido', 'Por favor ingrese el RUC correcto', 'error');
                            return;
                        }

                        confirmBtn.disabled = true;
                        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Eliminando...';
                        modal.remove();

                        // Mostrar modal de progreso para eliminación
                        const dbName = self.form.dbase || 'empresa_' + self.form.id_empresa;
                        window.showProgress({
                            title: 'Eliminando Empresa',
                            subtitle: `🗑️ ${self.form.empresa}`,
                            step: 'Iniciando proceso de eliminación...',
                            log: `Base de datos: ${dbName}`
                        });

                        await self.sleep(300);

                        try {
                            const res = await fetch('editar_empresa.php?action=delete', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    id_empresa: self.form.id_empresa,
                                    confirmacion: input.value
                                })
                            });

                            // Verificar si la respuesta es OK
                            if (!res.ok) {
                                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                            }

                            // Obtener texto de la respuesta primero
                            const responseText = await res.text();
                            console.log('Response text:', responseText);

                            // Intentar parsear como JSON
                            let data;
                            try {
                                data = JSON.parse(responseText);
                            } catch (parseError) {
                                console.error('Error parsing JSON:', parseError);
                                throw new Error('Respuesta inválida del servidor: ' + responseText.substring(0, 200));
                            }

                            // Procesar las operaciones realizadas
                            if (data.operations && Array.isArray(data.operations)) {
                                const totalOps = data.operations.length;
                                let progressStep = 20;

                                for (let i = 0; i < data.operations.length; i++) {
                                    const op = data.operations[i];
                                    const statusIcon = op.status === 'success' ? '✓' :
                                        op.status === 'error' ? '✗' :
                                        '⚠';
                                    const logText = `${statusIcon} ${op.step}: ${op.details}`;

                                    window.updateProgress({
                                        step: op.step,
                                        progress: Math.min(progressStep, 90),
                                        addLog: true,
                                        log: logText,
                                        logType: op.status
                                    });

                                    progressStep += Math.floor((70 / totalOps));
                                    await self.sleep(250);
                                }
                            }

                            if (data.success) {
                                window.finishProgress({
                                    log: '✅ Empresa eliminada correctamente',
                                    logType: 'success',
                                    tablesCreated: 0,
                                    columnsAdded: 0,
                                    errorsCount: 0
                                });

                                await self.sleep(2000);
                                window.location.href = 'empresa.php';
                            } else {
                                window.finishProgress({
                                    log: `❌ Error: ${data.error || 'Error desconocido'}`,
                                    logType: 'error',
                                    tablesCreated: 0,
                                    columnsAdded: 0,
                                    errorsCount: 1
                                });
                            }
                        } catch (e) {
                            console.error('Error en eliminación:', e);
                            window.finishProgress({
                                log: `❌ Error: ${e.message}`,
                                logType: 'error',
                                tablesCreated: 0,
                                columnsAdded: 0,
                                errorsCount: 1
                            });
                        }
                    });
                },
                async guardar() {
                    if (!this.form.ruc) {
                        window.showNotif('Falta RUC', 'El RUC es obligatorio', 'warning');
                        return;
                    }
                    if (!this.form.empresa) {
                        window.showNotif('Falta empresa', 'El nombre de empresa es obligatorio', 'warning');
                        return;
                    }

                    this.loading = true;

                    // Mostrar modal de progreso
                    // Forzar siempre empresa_{id} como destino
                    const dbName = 'empresa_' + (this.form.id_empresa || 'nueva');
                    const isNew = !this.isEdit;
                    const dbSource = this.form.software == 2 ? 'flota_ovetense' : 'tienda_169';
                    const softwareName = this.form.software == 2 ? 'Transportadora' : 'Stock';
                    const oldDbName = this.form.dbase || '';
                    const needsMigration = isNew ? false : (oldDbName && oldDbName !== dbName);

                    let subtitleText = `📥 Origen: ${dbSource} → 📤 Destino: ${dbName}`;
                    if (needsMigration) {
                        subtitleText = `⚠️ Migración: ${oldDbName} → ${dbName}`;
                    }

                    window.showProgress({
                        title: isNew ? 'Creando Empresa' : 'Actualizando Empresa',
                        subtitle: subtitleText,
                        step: 'Guardando datos de empresa...',
                        log: `Software: ${softwareName}${needsMigration ? ' | Migración pendiente' : ''}`
                    });

                    // Simular progreso inicial
                    await this.sleep(300);
                    window.updateProgress({
                        progress: 10,
                        log: 'Datos de empresa guardados',
                        logType: 'success'
                    });

                    try {
                        window.updateProgress({
                            progress: 15,
                            addLog: 'Conectando al servidor...',
                            logType: 'loading'
                        });
                        await this.sleep(200);

                        const res = await fetch('editar_empresa.php?action=save', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.form)
                        });

                        window.updateProgress({
                            progress: 30,
                            log: 'Conexión establecida',
                            logType: 'success'
                        });
                        await this.sleep(200);

                        window.updateProgress({
                            progress: 40,
                            addLog: 'Procesando respuesta...',
                            logType: 'loading'
                        });
                        const data = await res.json();

                        if (data.success) {
                            // Actualizar progreso con info de sincronización
                            window.updateProgress({
                                progress: 50,
                                log: data.message || 'Empresa guardada',
                                logType: 'success'
                            });
                            await this.sleep(200);

                            // Si hubo migración de datos
                            if (data.migration && data.migration.migrated) {
                                window.updateProgress({
                                    progress: 52,
                                    addLog: `⚠️ DB anterior detectada: ${data.migration.old_db}`,
                                    logType: 'warning'
                                });
                                await this.sleep(300);

                                window.updateProgress({
                                    progress: 54,
                                    addLog: `📦 Migrando datos a ${data.db_name}...`,
                                    logType: 'loading'
                                });
                                await this.sleep(300);

                                window.updateProgress({
                                    progress: 56,
                                    addLog: `✓ ${data.migration.tables_migrated} tablas migradas`,
                                    logType: 'success'
                                });
                                await this.sleep(200);

                                if (data.migration.backup_file) {
                                    window.updateProgress({
                                        progress: 58,
                                        addLog: `💾 Backup creado: ${data.migration.backup_file}`,
                                        logType: 'success'
                                    });
                                    await this.sleep(200);
                                }

                                if (data.migration.migration_errors && data.migration.migration_errors.length > 0) {
                                    data.migration.migration_errors.slice(0, 3).forEach(err => {
                                        window.updateProgress({
                                            addLog: `⚠️ ${err}`,
                                            logType: 'warning'
                                        });
                                    });
                                    await this.sleep(200);
                                }
                            }

                            if (data.db_created) {
                                window.updateProgress({
                                    progress: 60,
                                    addLog: `Base de datos ${data.db_name} creada`,
                                    logType: 'success'
                                });
                                await this.sleep(200);

                                // Mostrar origen y destino
                                if (data.db_source) {
                                    window.updateProgress({
                                        addLog: `📥 Origen: ${data.db_source}`,
                                        logType: 'info'
                                    });
                                    window.updateProgress({
                                        addLog: `📤 Destino: ${data.db_name}`,
                                        logType: 'info'
                                    });
                                    await this.sleep(150);
                                }
                            } else if (data.db_name) {
                                window.updateProgress({
                                    progress: 55,
                                    addLog: `Base de datos: ${data.db_name}`,
                                    logType: 'info'
                                });

                                // Mostrar origen para sincronización
                                if (data.db_source) {
                                    window.updateProgress({
                                        addLog: `📥 Sincronizando desde: ${data.db_source}`,
                                        logType: 'info'
                                    });
                                    await this.sleep(150);
                                }
                            }
                            this.form.dbase = data.db_name || this.form.dbase;
                            await this.sleep(150);

                            // Mostrar tablas creadas
                            const tablasCreadas = data.sync_tables_created_list || [];
                            if (tablasCreadas.length > 0) {
                                window.updateProgress({
                                    progress: 65,
                                    addLog: `Creando ${tablasCreadas.length} tablas...`,
                                    logType: 'loading'
                                });
                                await this.sleep(300);

                                // Mostrar las primeras 10 tablas creadas
                                const maxShow = Math.min(tablasCreadas.length, 10);
                                for (let i = 0; i < maxShow; i++) {
                                    window.updateProgress({
                                        progress: 65 + Math.round((i / maxShow) * 15),
                                        addLog: `✓ Tabla: ${tablasCreadas[i]}`,
                                        logType: 'success'
                                    });
                                    await this.sleep(50);
                                }
                                if (tablasCreadas.length > 10) {
                                    window.updateProgress({
                                        addLog: `... y ${tablasCreadas.length - 10} tablas más`,
                                        logType: 'info'
                                    });
                                }
                            }

                            // Mostrar tablas sincronizadas (columnas agregadas)
                            const tablasSincronizadas = data.sync_tables_updated_list || [];
                            const columnasAgregadas = data.sync_columns_added_list || [];
                            if (tablasSincronizadas.length > 0) {
                                window.updateProgress({
                                    progress: 85,
                                    addLog: `Sincronizando ${tablasSincronizadas.length} tablas existentes...`,
                                    logType: 'loading'
                                });
                                await this.sleep(200);

                                // Mostrar columnas agregadas (máx 10)
                                const maxCols = Math.min(columnasAgregadas.length, 10);
                                for (let i = 0; i < maxCols; i++) {
                                    window.updateProgress({
                                        progress: 85 + Math.round((i / maxCols) * 10),
                                        addLog: `+ Columna: ${columnasAgregadas[i]}`,
                                        logType: 'success'
                                    });
                                    await this.sleep(30);
                                }
                                if (columnasAgregadas.length > 10) {
                                    window.updateProgress({
                                        addLog: `... y ${columnasAgregadas.length - 10} columnas más`,
                                        logType: 'info'
                                    });
                                }
                            }

                            // Mostrar errores de sync si hay
                            const syncErrors = data.sync_errors || data.setup_errors || [];
                            if (syncErrors.length > 0) {
                                syncErrors.slice(0, 5).forEach(err => {
                                    window.updateProgress({
                                        addLog: err,
                                        logType: 'error'
                                    });
                                });
                            }

                            // Para empresa nueva (usuario soporte)
                            if (data.user_created) {
                                window.updateProgress({
                                    progress: 95,
                                    addLog: 'Usuario soporte creado',
                                    logType: 'success'
                                });
                            }

                            await this.sleep(300);

                            // Finalizar con resumen (reutilizando variables ya declaradas)
                            window.finishProgress({
                                tablesCreated: tablasCreadas.length || data.sync_tables_created || 0,
                                columnsAdded: columnasAgregadas.length || 0,
                                errorsCount: syncErrors.length,
                                log: 'Proceso completado exitosamente',
                                logType: 'success'
                            });

                            if (!this.isEdit && data.id) {
                                this.form.id_empresa = data.id;
                                this.isEdit = true;
                            }
                        } else {
                            window.finishProgress({
                                tablesCreated: 0,
                                columnsAdded: 0,
                                errorsCount: 1,
                                log: data.error || 'Error al guardar',
                                logType: 'error'
                            });
                        }
                    } catch (e) {
                        window.finishProgress({
                            tablesCreated: 0,
                            columnsAdded: 0,
                            errorsCount: 1,
                            log: 'Error de conexión: ' + e.message,
                            logType: 'error'
                        });
                    } finally {
                        this.loading = false;
                    }
                },
                sleep(ms) {
                    return new Promise(resolve => setTimeout(resolve, ms));
                },
                cerrarModal() {
                    // Cerrar solo la app actual (volver al menú o cerrar modal)
                    if (window.parent && window.parent !== window) {
                        // Comunicar al padre que cierre el modal
                        window.parent.postMessage({
                            type: 'closeModal'
                        }, '*');

                        // Intentar cerrar el iframe del modal de empresa.php
                        try {
                            const empresaModal = window.parent.document.getElementById('empresaModal');
                            if (empresaModal) {
                                const frame = window.parent.document.getElementById('empresaModalFrame');
                                if (frame) frame.src = '';
                                empresaModal.style.display = 'none';
                                // Recargar datos en empresa.php
                                if (typeof window.parent.loadData === 'function') {
                                    window.parent.loadData();
                                }
                                return;
                            }
                            // Fallback: intentar con appFrame del menú
                            const appFrame = window.parent.document.getElementById('appFrame');
                            if (appFrame) {
                                appFrame.src = 'about:blank';
                            }
                        } catch (e) {
                            console.log('Error cerrando modal:', e);
                        }
                    } else {
                        window.history.back();
                    }
                },
                async subirLogo(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    // Validar tipo
                    const allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(file.type)) {
                        window.showNotif('Error', 'Formato no válido. Use PNG, JPG o GIF', 'error');
                        return;
                    }

                    // Validar tamaño (2MB max)
                    if (file.size > 2 * 1024 * 1024) {
                        window.showNotif('Error', 'El archivo excede 2MB', 'error');
                        return;
                    }

                    // Preview local
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        this.logoPreview = e.target.result;
                    };
                    reader.readAsDataURL(file);

                    // Subir al servidor
                    const formData = new FormData();
                    formData.append('logo', file);
                    formData.append('id_empresa', this.form.id_empresa || 0);

                    try {
                        const res = await fetch('editar_empresa.php?action=upload_logo', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.form.logos = data.filename;
                            window.showNotif('Éxito', 'Logo subido correctamente', 'success');
                        } else {
                            window.showNotif('Error', data.error || 'No se pudo subir el logo', 'error');
                            this.logoPreview = null;
                        }
                    } catch (e) {
                        console.error(e);
                        window.showNotif('Error', 'Error al subir el logo', 'error');
                        this.logoPreview = null;
                    }
                }
            };
        }
    </script>
</body>

</html>
