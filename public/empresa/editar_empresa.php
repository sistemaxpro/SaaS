<?php

/**
 * API Backend - Gestión de Empresas
 * Adaptado para arquitectura v1 (bootstrap.php + Database class)
 * 
 * Acciones (via GET ?action=...):
 *   get             → Obtener datos de empresa
 *   save            → Crear/Actualizar empresa
 *   delete          → Eliminar empresa (con backup)
 *   sifen_lookup    → Consultar RUC en SIFEN
 *   upload_logo     → Subir logo de empresa
 */

// Cargar bootstrap del proyecto (Database, Session, etc.)
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/Modules/Empresas/SuscripcionController.php';

$masterDb = 'serproc1';
$action = $_GET['action'] ?? '';

// Si no hay acción API, no hacer nada (el formulario es index.php)
if (empty($action)) {
    header('Location: index.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
// Evitar que warnings/notices rompan respuestas JSON en frontend.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// ======================= Funciones auxiliares =======================

/**
 * Resolver base plantilla/origen disponible para clonar/sincronizar.
 */
function resolverDbSourceDisponible($pdo, $software = 1, $prefer = '')
{
    $candidatos = [];
    if (!empty($prefer)) {
        $candidatos[] = $prefer;
    }

    $defaultBySoftware = ($software == 2) ? 'flota_ovetense' : 'tienda_169';
    $candidatos[] = $defaultBySoftware;
    $candidatos[] = 'empresa_169';
    $candidatos[] = 'tienda_169';
    $candidatos[] = 'flota_ovetense';

    $candidatos = array_values(array_unique(array_filter($candidatos)));

    foreach ($candidatos as $dbName) {
        $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($dbName));
        if ($stmt && $stmt->fetch()) {
            return $dbName;
        }
    }

    return '';
}

/**
 * Crear base de datos para nueva empresa y usuario soporte
 */
function crearBaseDatosEmpresa($pdo, $idEmpresa, $empresaData, $software = 1)
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

    $dbSource = resolverDbSourceDisponible($pdo, $software);
    $result['db_source'] = $dbSource;

    try {
        $dbName = 'empresa_' . $idEmpresa;
        $result['db_name'] = $dbName;

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $result['db_created'] = true;

        if (empty($dbSource)) {
            $result['errors'][] = "No se encontró base plantilla para software={$software}";
            return $result;
        }

        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = '{$dbSource}' AND TABLE_TYPE = 'BASE TABLE'");
        $tablas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tablas)) {
            $result['errors'][] = "No se encontraron tablas en {$dbSource}";
            return $result;
        }

        foreach ($tablas as $table) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `{$dbSource}`.`{$table}`");
                $result['tables_created']++;
                $result['tables_created_list'][] = $table;
            } catch (Exception $e) {
                $result['errors'][] = "Error en tabla {$table}: " . $e->getMessage();
            }
        }

        // Crear usuario soporte
        $loginSoporte = 'soporte' . $idEmpresa;
        $passwordHash = md5('Armagedon123');
        $nombreEmpresa = $empresaData['empresa'] ?? 'Empresa ' . $idEmpresa;
        $emailEmpresa = $empresaData['email'] ?? '';
        $telefonoEmpresa = $empresaData['telefono'] ?? '';

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
 */
function sincronizarEstructuraEmpresa($pdo, $dbName, $software = 1, $preferSource = '')
{
    $dbSource = resolverDbSourceDisponible($pdo, $software, $preferSource);

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
        $checkDb = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'")->fetch();
        if (!$checkDb) {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $result['db_created'] = true;
        }

        if (empty($dbSource)) {
            $result['errors'][] = "No se encontró base plantilla para sincronizar (software={$software})";
            return $result;
        }

        $stmtDest = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                                 WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_TYPE = 'BASE TABLE'");
        $tablesExisting = $stmtDest->fetchAll(PDO::FETCH_COLUMN);
        $existingSet = array_flip($tablesExisting);

        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = '{$dbSource}' AND TABLE_TYPE = 'BASE TABLE'");
        $tablesSource = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tablesSource)) {
            $result['errors'][] = "No se encontraron tablas en {$dbSource}";
            return $result;
        }

        foreach ($tablesSource as $table) {
            if (!isset($existingSet[$table])) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `{$dbSource}`.`{$table}`");
                    $result['tables_created']++;
                    $result['tables_created_list'][] = $table;
                } catch (Exception $e) {
                    $result['errors'][] = "Error creando {$table}: " . $e->getMessage();
                }
            } else {
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

/**
 * Sincronizar permisos de grupo desde sec_apps
 */
function sincronizarPermisosGrupo($pdo, $masterDb)
{
    $result = [
        'grupos_procesados' => 0,
        'permisos_creados' => 0,
        'errors' => []
    ];

    try {
        $checkTable = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$masterDb}' AND TABLE_NAME = 'sec_groups_app'")->fetch();
        if (!$checkTable) {
            $result['errors'][] = 'Tabla sec_groups_app no existe';
            return $result;
        }

        if (!isset($_SESSION['id_login']) || $_SESSION['id_login'] <= 0) {
            return $result;
        }

        $stmtUser = $pdo->prepare("SELECT id_grupo FROM {$masterDb}.sec_users WHERE id = ?");
        $stmtUser->execute([$_SESSION['id_login']]);
        $userGroup = $stmtUser->fetchColumn();

        if (!$userGroup || $userGroup <= 0) {
            return $result;
        }

        $checkAppTable = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$masterDb}' AND TABLE_NAME = 'sec_apps'")->fetch();
        if (!$checkAppTable) {
            return $result;
        }

        $appsStmt = $pdo->query("SELECT id FROM {$masterDb}.sec_apps WHERE active = 'Y'");
        $apps = $appsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($apps)) {
            return $result;
        }

        foreach ($apps as $appId) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM {$masterDb}.sec_groups_app WHERE id_grupo = ? AND id_app = ?");
            $checkStmt->execute([$userGroup, $appId]);
            $exists = $checkStmt->fetchColumn();

            if (!$exists) {
                try {
                    $insertStmt = $pdo->prepare("INSERT INTO {$masterDb}.sec_groups_app (id_grupo, id_app, access) VALUES (?, ?, 'on')");
                    $insertStmt->execute([$userGroup, $appId]);
                    $result['permisos_creados']++;
                } catch (Exception $e) {
                    // Ignorar errores de inserción individual
                }
            }
        }

        $result['grupos_procesados'] = 1;
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

function smxResolverDirectorioBackupEmpresa(): string
{
    $candidatos = [
        dirname(__DIR__, 2) . '/backups/empresa',
        '/var/www/html/backups/empresa',
        rtrim(sys_get_temp_dir(), '/') . '/sistemax_backups/empresa',
    ];

    foreach ($candidatos as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            continue;
        }
        if (is_writable($dir)) {
            return $dir;
        }
    }

    throw new RuntimeException('No se encontró un directorio escribible para backups');
}

function smxNormalizarDbHost(?string $host): string
{
    $normalized = strtolower(trim((string)$host));
    if ($normalized === '' || in_array($normalized, ['168.231.95.50', '127.0.0.1', 'localhost'], true)) {
        return 'localhost';
    }

    return trim((string)$host);
}

/**
 * Calcular dígito verificador (módulo 11)
 */
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

// ======================= Obtener conexión master =======================
try {
    $pdo = Database::getMasterConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

// ======================= API: Consulta RUC SIFEN =======================
if ($action === 'sifen_lookup') {
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
            if (file_exists($cand) && filesize($cand) > 0) {
                $certPath = $cand;
                break;
            }
        }

        if (!$certPath) {
            throw new Exception('Certificado de consulta no encontrado o vacío');
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
function smxGuardarLogoEmpresa(PDO $pdo, string $tmpPath, string $originalName, int $sizeBytes, int $idEmp): array
{
    $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new Exception('Tipo de archivo no permitido');
    }

    if ($sizeBytes > 2 * 1024 * 1024) {
        throw new Exception('El archivo excede 2MB');
    }

    $dirCandidates = [
        [dirname(__DIR__) . '/_lib/file/empresa/', '/public/_lib/file/empresa/'],
        [dirname(__DIR__) . '/_lib/file/img/empresa/', '/public/_lib/file/img/empresa/'],
    ];
    $uploadDir = null;
    $publicBase = null;
    foreach ($dirCandidates as [$candidateDir, $candidateUrl]) {
        if (!is_dir($candidateDir)) {
            @mkdir($candidateDir, 0775, true);
        }
        if (is_dir($candidateDir) && is_writable($candidateDir)) {
            $uploadDir = $candidateDir;
            $publicBase = $candidateUrl;
            break;
        }
    }

    if ($uploadDir === null || $publicBase === null) {
        throw new Exception('No hay un directorio escribible para logos');
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        $ext = 'png';
    }

    $filename = ($idEmp > 0 ? 'empresa_' . $idEmp : 'logo_' . time()) . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (!@copy($tmpPath, $destPath) && !@rename($tmpPath, $destPath)) {
        if (!@move_uploaded_file($tmpPath, $destPath)) {
            throw new Exception('Error al guardar el archivo');
        }
    }

    if ($idEmp > 0) {
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".empresa SET logos = ? WHERE id_empresa = ?");
        $stmt->execute([$filename, $idEmp]);
    }

    return [
        'success' => true,
        'filename' => $filename,
        'path' => $publicBase . $filename
    ];
}

if ($action === 'upload_logo') {
    try {
        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió el archivo');
        }

        $file = $_FILES['logo'];
        $idEmp = (int)($_POST['id_empresa'] ?? 0);
        echo json_encode(smxGuardarLogoEmpresa($pdo, $file['tmp_name'], (string)$file['name'], (int)$file['size'], $idEmp));
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'upload_logo_url') {
    try {
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $imageUrl = trim((string)($payload['image_url'] ?? ''));
        $idEmp = (int)($payload['id_empresa'] ?? 0);
        if ($imageUrl === '' || !preg_match('#^https?://#i', $imageUrl)) {
            throw new Exception('URL de imagen inválida');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'SistemaX Empresa Logo Bot/1.0',
        ]);
        $binary = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string)curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || $binary === '') {
            throw new Exception('No se pudo descargar la imagen' . ($curlErr !== '' ? ': ' . $curlErr : ''));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'logo_emp_');
        if ($tmp === false) {
            throw new Exception('No se pudo preparar archivo temporal');
        }
        file_put_contents($tmp, $binary);
        $result = smxGuardarLogoEmpresa($pdo, $tmp, basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'logo_remoto.png'), strlen($binary), $idEmp);
        @unlink($tmp);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Eliminar empresa (con backup) =======================
if ($action === 'delete') {
    try {
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['id_empresa'] ?? 0);
        $idSuscripcion = (int)($payload['id_suscripcion'] ?? 0);
        $confirmacion = trim($payload['confirmacion'] ?? '');

        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de empresa inválido']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = ?");
        $stmt->execute([$id]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        $empresaEncontrada = (bool)$empresa;
        if ($empresaEncontrada && $confirmacion !== $empresa['ruc']) {
            echo json_encode(['success' => false, 'error' => 'Confirmación incorrecta. Debe escribir el RUC exacto.']);
            exit;
        }

        $dbName = ($empresa['dbase'] ?? '') ?: 'empresa_' . $id;
        $backupDir = smxResolverDirectorioBackupEmpresa();
        $backupFile = '';
        $backupSuccess = false;

        $checkDb = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'")->fetch();

        if ($checkDb) {
            $timestamp = date('Ymd_His');
            $backupFile = "{$backupDir}/{$dbName}_{$timestamp}.sql";

            $dbHost = 'localhost';
            $dbUser = 'sistemax';
            $dbPass = 'Armagedon123';

            $cmd = sprintf(
                'mysqldump -h%s -u%s -p%s %s > %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($backupFile)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 100) {
                $backupSuccess = true;
            } else {
                // Backup alternativo con PHP
                $backupContent = "-- Backup de {$dbName} - " . date('Y-m-d H:i:s') . "\n";
                $backupContent .= "-- Empresa: " . ($empresa['empresa'] ?? ('Empresa #' . $id)) . "\n";
                $backupContent .= "-- RUC: " . ($empresa['ruc'] ?? 'Sin dato') . "\n\n";

                $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$dbName}'")->fetchAll(PDO::FETCH_COLUMN);

                foreach ($tables as $table) {
                    try {
                        $createStmt = $pdo->query("SHOW CREATE TABLE `{$dbName}`.`{$table}`")->fetch(PDO::FETCH_ASSOC);
                        $createSql = $createStmt['Create Table'] ?? $createStmt['Create View'] ?? ($createStmt[array_key_last($createStmt)] ?? '');
                        if ($createSql !== '') {
                            $backupContent .= $createSql . ";\n\n";
                        }
                    } catch (Exception $e) {
                        $backupContent .= "-- Error en tabla {$table}: " . $e->getMessage() . "\n\n";
                    }
                }

                if (file_put_contents($backupFile, $backupContent) !== false && filesize($backupFile) > 100) {
                    $backupSuccess = true;
                }
            }

            if ($backupSuccess) {
                $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No se pudo crear el backup. La base de datos NO fue eliminada por seguridad.'
                ]);
                exit;
            }
        }

        try {
            SuscripcionController::prepararEmpresasSuprimidasSchema();
            $pdo->beginTransaction();
            SuscripcionController::marcarEmpresaSuprimida($id, 'suprimida_desde_gestion');
            $susIds = [];
            if ($idSuscripcion > 0) {
                $susIds[] = $idSuscripcion;
            }

            $susIdsStmt = $pdo->prepare("SELECT id_suscripcion FROM " . MASTER_DB . ".saas_suscripcion WHERE id_empresa = ?");
            $susIdsStmt->execute([$id]);
            $susIds = array_values(array_unique(array_merge(
                $susIds,
                array_map('intval', $susIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])
            )));

            if (!empty($susIds)) {
                $in = implode(',', array_fill(0, count($susIds), '?'));
                $pdo->prepare("DELETE FROM " . MASTER_DB . ".saas_suscripcion_auditoria WHERE id_suscripcion IN ({$in})")->execute($susIds);
                $pdo->prepare("DELETE FROM " . MASTER_DB . ".saas_pagos_historial WHERE id_suscripcion IN ({$in})")->execute($susIds);
                $pdo->prepare("DELETE FROM " . MASTER_DB . ".saas_suscripcion_apps WHERE id_suscripcion IN ({$in})")->execute($susIds);
                $pdo->prepare("DELETE FROM " . MASTER_DB . ".saas_suscripcion WHERE id_suscripcion IN ({$in})")->execute($susIds);
            }
            $pdo->prepare("DELETE FROM " . MASTER_DB . ".saas_suscripcion_auditoria WHERE id_empresa = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM " . MASTER_DB . ".saas_suscripcion WHERE id_empresa = ?")->execute([$id]);
            if ($empresaEncontrada) {
                $pdo->prepare("DELETE FROM " . MASTER_DB . ".habilitacion_sifen WHERE id_empresa = ?")->execute([$id]);
                $stmtDel = $pdo->prepare("DELETE FROM " . MASTER_DB . ".empresa WHERE id_empresa = ?");
                $stmtDel->execute([$id]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'message' => $empresaEncontrada
                ? 'Empresa eliminada correctamente'
                : 'Suscripción eliminada. La empresa ya no existía en maestro.',
            'backup_file' => $backupSuccess ? basename($backupFile) : null,
            'backup_path' => $backupSuccess ? $backupFile : null,
            'db_deleted' => $checkDb ? true : false,
            'empresa_encontrada' => $empresaEncontrada,
            'suscripciones_eliminadas' => count($susIds)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Obtener empresa =======================
if ($action === 'get') {
    try {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = ?");
        $stmt->execute([$id]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empresa) {
            echo json_encode(['success' => false, 'error' => 'Empresa no encontrada']);
            exit;
        }

        $empresa['server'] = smxNormalizarDbHost($empresa['server'] ?? null);

        $empresa['caja_principal'] = 'N/D';
        $dbName = trim((string)($empresa['dbase'] ?? ''));
        if ($dbName !== '') {
            try {
                $pdoEmpresa = Database::getEmpresaConnection($id);
                $stmtCaja = $pdoEmpresa->query("SELECT id_caja, caja FROM cajas ORDER BY id_caja ASC LIMIT 1");
                $caja = $stmtCaja ? $stmtCaja->fetch(PDO::FETCH_ASSOC) : false;
                if ($caja) {
                    $nombreCaja = trim((string)($caja['caja'] ?? ''));
                    $idCaja = (int)($caja['id_caja'] ?? 0);
                    $empresa['caja_principal'] = $nombreCaja !== ''
                        ? ($nombreCaja . ($idCaja > 0 ? ' (#' . $idCaja . ')' : ''))
                        : ($idCaja > 0 ? ('#' . $idCaja) : 'N/D');
                }
            } catch (Throwable $e) {
                $empresa['caja_principal'] = 'N/D';
            }
        }

        echo json_encode(['success' => true, 'data' => $empresa]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Guardar empresa =======================
if ($action === 'save') {
    try {
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['id_empresa'] ?? 0);
        $isUpdate = $id > 0;

        // Obtener columnas permitidas
        $colsStmt = $pdo->query("SHOW COLUMNS FROM " . MASTER_DB . ".empresa");
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
            'moneda_principal' => max(1, (int)($payload['moneda_principal'] ?? 1)),
            'web' => (int)($payload['web'] ?? 0),
            'web_url' => trim($payload['web_url'] ?? ''),
            'web_ck' => trim($payload['web_ck'] ?? ''),
            'web_cs' => trim($payload['web_cs'] ?? ''),
            'factura_electronica' => (int)($payload['factura_electronica'] ?? 0),
            'fe' => (int)($payload['factura_electronica'] ?? 0),
            'ambiente_sifen' => trim($payload['ambiente_sifen'] ?? ''),
            'timbrado' => trim($payload['timbrado'] ?? ''),
            'vigencia_ini' => (($payload['vigencia_ini'] ?? null) ?: null),
            'vigencia_fin' => (($payload['vigencia_fin'] ?? null) ?: null),
        ];

        // Validaciones
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
            $dbName = 'empresa_' . $id;

            // Obtener configuración actual
            $stmtOld = $pdo->prepare("SELECT dbase, moneda_principal FROM " . MASTER_DB . ".empresa WHERE id_empresa = ?");
            $stmtOld->execute([$id]);
            $empresaActual = $stmtOld->fetch(PDO::FETCH_ASSOC) ?: [];
            $oldDbName = (string)($empresaActual['dbase'] ?? '');
            $monedaPrincipalActual = max(1, (int)($empresaActual['moneda_principal'] ?? 1));
            $filtered['moneda_principal'] = $monedaPrincipalActual;

            $migrationResult = [
                'migrated' => false,
                'old_db' => $oldDbName,
                'backup_file' => '',
                'tables_migrated' => 0,
                'migration_errors' => []
            ];

            // Migrar datos si la DB anterior es diferente
            if (!empty($oldDbName) && $oldDbName !== $dbName) {
                $checkOldDb = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$oldDbName}'")->fetch();

                if ($checkOldDb) {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                    $tablesOld = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
                                              WHERE TABLE_SCHEMA = '{$oldDbName}' AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($tablesOld as $table) {
                        try {
                            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `{$oldDbName}`.`{$table}`");
                            $pdo->exec("INSERT IGNORE INTO `{$dbName}`.`{$table}` SELECT * FROM `{$oldDbName}`.`{$table}`");
                            $migrationResult['tables_migrated']++;
                        } catch (Exception $e) {
                            $migrationResult['migration_errors'][] = "Error migrando {$table}: " . $e->getMessage();
                        }
                    }

                    $backupDir = dirname(__DIR__, 2) . '/backups/empresa';
                    if (!is_dir($backupDir)) {
                        mkdir($backupDir, 0755, true);
                    }

                    $timestamp = date('Ymd_His');
                    $backupFile = "{$backupDir}/{$oldDbName}_{$timestamp}.sql";

                    $dbHost = 'localhost';
                    $dbUser = 'sistemax';
                    $dbPass = 'Armagedon123';

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
            $sql = "UPDATE " . MASTER_DB . ".empresa SET " . implode(', ', $sets) . " WHERE id_empresa = :id_empresa";
            $stmt = $pdo->prepare($sql);
            foreach ($filtered as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':id_empresa', $id);
            $stmt->execute();

            // Si origen y destino son iguales, no ejecutar migración/sincronización.
            $sameDb = (!empty($oldDbName) && $oldDbName === $dbName);
            $syncResult = [
                'db_created' => false,
                'tables_created' => 0,
                'tables_updated' => 0,
                'tables_created_list' => [],
                'tables_updated_list' => [],
                'columns_added_list' => [],
                'errors' => [],
                'db_source' => $oldDbName
            ];
            if (!$sameDb) {
                $software = isset($filtered['software']) ? (int)$filtered['software'] : 1;
                $syncResult = sincronizarEstructuraEmpresa($pdo, $dbName, $software, $oldDbName);
            }

            // Sincronizar permisos
            $permisosResult = sincronizarPermisosGrupo($pdo, $masterDb);

            echo json_encode([
                'success' => true,
                'id' => $id,
                'db_name' => $dbName,
                'db_source' => $syncResult['db_source'] ?? '',
                'db_created' => $syncResult['db_created'] ?? false,
                'old_db_name' => $migrationResult['old_db'],
                'migration' => $migrationResult,
                'message' => $sameDb
                    ? 'Empresa actualizada (sin migración ni sincronización: origen y destino iguales)'
                    : ($migrationResult['migrated']
                    ? "Empresa actualizada. Datos migrados desde {$oldDbName}"
                    : 'Empresa actualizada correctamente'),
                'sync_tables_created' => $syncResult['tables_created'] ?? 0,
                'sync_tables_updated' => $syncResult['tables_updated'] ?? 0,
                'sync_tables_created_list' => $syncResult['tables_created_list'] ?? [],
                'sync_tables_updated_list' => $syncResult['tables_updated_list'] ?? [],
                'sync_columns_added_list' => $syncResult['columns_added_list'] ?? [],
                'sync_errors' => $syncResult['errors'] ?? [],
                'permisos_creados' => $permisosResult['permisos_creados'] ?? 0,
                'permisos_errors' => $permisosResult['errors'] ?? []
            ]);
        } else {
            // INSERT
            $columns = array_keys($filtered);
            $placeholders = array_map(fn($c) => ':' . $c, $columns);
            $sql = "INSERT INTO " . MASTER_DB . ".empresa (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            foreach ($filtered as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->execute();

            $newIdEmpresa = $pdo->lastInsertId();

            $software = isset($filtered['software']) ? (int)$filtered['software'] : 1;
            $setupResult = crearBaseDatosEmpresa($pdo, $newIdEmpresa, $filtered, $software);
            $suscripcionAuto = SuscripcionController::asegurarSuscripcionEmpresa((int)$newIdEmpresa, [
                'created_by' => (int)($_SESSION['id_login'] ?? 0),
            ]);

            $pdo->prepare("UPDATE " . MASTER_DB . ".empresa SET dbase = ? WHERE id_empresa = ?")->execute(['empresa_' . $newIdEmpresa, $newIdEmpresa]);

            $permisosResult = sincronizarPermisosGrupo($pdo, $masterDb);

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
                'sync_errors' => $setupResult['errors'] ?? [],
                'permisos_creados' => $permisosResult['permisos_creados'] ?? 0,
                'permisos_errors' => $permisosResult['errors'] ?? []
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Obtener configuración de video =======================
if ($action === 'get_video_config') {
    try {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'disable_video' => false, 'error' => 'ID inválido']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT disable_background_video FROM " . MASTER_DB . ".empresa WHERE id_empresa = ?");
        $stmt->execute([$id]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empresa) {
            echo json_encode(['success' => false, 'disable_video' => false, 'error' => 'Empresa no encontrada']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'disable_video' => (int)($empresa['disable_background_video'] ?? 0) === 1
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'disable_video' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Acción no reconocida
echo json_encode(['success' => false, 'error' => 'Acción no válida: ' . $action]);
