<?php

/**
 * POS API - Clientes
 * Búsqueda de clientes
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

$action = $_GET['action'] ?? '';
$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? 0);

function envValuePos(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== null && $v !== '') return (string)$v;
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    return $default;
}

function calcularDVPos(string $rucBase): string
{
    $ruc = preg_replace('/[^0-9]/', '', $rucBase);
    if ($ruc === '') return '';

    $k = 2;
    $total = 0;
    for ($i = strlen($ruc) - 1; $i >= 0; $i--) {
        $total += ((int)$ruc[$i]) * $k;
        $k++;
        if ($k > 11) $k = 2;
    }
    $resto = $total % 11;
    return (string)(($resto > 1) ? (11 - $resto) : 0);
}

function consultarRucSifenPos(string $rucRaw, PDO $pdoMaster): array
{
    $rucSoloNum = preg_replace('/[^0-9]/', '', $rucRaw);
    if (strlen($rucSoloNum) < 5) {
        return ['success' => false, 'error' => 'RUC/Cédula inválida'];
    }

    $baseDir = dirname(__DIR__, 2);
    $certPassFijo = trim(envValuePos('SISTEMAX_SIFEN_CERT_PASS', '3nvcEcwW'));
    $certNameFrom169 = '';
    $certPassFrom169 = '';
    $empresaCertPass169 = '';
    $empresaPassCert169 = '';
    $empresaClaveCert169 = '';

    // Fuente fija solicitada: credenciales/certificado de empresa 169 (solo para consulta SIFEN).
    try {
        $stmtHab169 = $pdoMaster->prepare("
            SELECT cert_path, cert_nombre, cert_pass
            FROM " . MASTER_DB . ".habilitacion_sifen
            WHERE id_empresa = 169 AND activo = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtHab169->execute();
        $hab169 = $stmtHab169->fetch(PDO::FETCH_ASSOC) ?: [];
        $certNameFrom169 = trim((string)basename((string)($hab169['cert_path'] ?? $hab169['cert_nombre'] ?? '')));
        $certPassFrom169 = trim((string)($hab169['cert_pass'] ?? ''));
    } catch (Throwable $e) {
        // fallback ENV/lista
    }
    try {
        $stmtEmp169 = $pdoMaster->prepare("
            SELECT cert_pass, password_certificado, clave_certificado
            FROM " . MASTER_DB . ".empresa
            WHERE id_empresa = 169
            LIMIT 1
        ");
        $stmtEmp169->execute();
        $emp169 = $stmtEmp169->fetch(PDO::FETCH_ASSOC) ?: [];
        $empresaCertPass169 = trim((string)($emp169['cert_pass'] ?? ''));
        $empresaPassCert169 = trim((string)($emp169['password_certificado'] ?? ''));
        $empresaClaveCert169 = trim((string)($emp169['clave_certificado'] ?? ''));
    } catch (Throwable $e) {
        // fallback ENV/lista
    }
    $certNames = [];
    $envCert = trim(envValuePos('SISTEMAX_SIFEN_LOOKUP_CERT', ''));
    if ($certNameFrom169 !== '') {
        $certNames[] = $certNameFrom169;
    }
    if ($envCert !== '') {
        $certNames[] = $envCert;
    }
    $certNames = array_values(array_unique(array_merge(
        $certNames,
        ['80118689.p12', 'sistemax_eas.p12', 'FABIOAUGUSTOVALDEZFRANCO.p12', 'FABIOAUGUSTOVALDEZFRANCO_20260213_174040.p12']
    )));

    $searchDirs = [
        $baseDir . '/_lib/php-sifen3-custom/certificados/',
        $baseDir . '/_lib/php-sifen3/certificados/',
        $baseDir . '/_lib/sifen/certificados/',
        $baseDir . '/_lib/certificados/',
    ];

    $certPath = '';
    $certs = [];
    $passCandidates = array_values(array_filter(array_unique([
        $certPassFrom169,
        $empresaCertPass169,
        $empresaPassCert169,
        $empresaClaveCert169,
        $certPassFijo
    ]), fn($v) => $v !== null && $v !== ''));
    if (empty($passCandidates)) {
        $passCandidates = [''];
    }

    foreach ($searchDirs as $dir) {
        foreach ($certNames as $name) {
            $cand = $dir . $name;
            if (!file_exists($cand) || filesize($cand) <= 0) continue;
            $pkcs12Content = (string)@file_get_contents($cand);
            if ($pkcs12Content === '') continue;
            foreach ($passCandidates as $candidatePass) {
                if (openssl_pkcs12_read($pkcs12Content, $tmpCerts, $candidatePass)) {
                    $certPath = $cand;
                    $certs = $tmpCerts;
                    break 3;
                }
            }
        }
    }

    if ($certPath === '') {
        return ['success' => false, 'error' => 'No se encontró certificado SIFEN válido (archivo faltante/vacío o clave incorrecta)'];
    }

    $pemContent = $certs['pkey'] . "\n" . $certs['cert'] . "\n";
    if (!empty($certs['extracerts']) && is_array($certs['extracerts'])) {
        foreach ($certs['extracerts'] as $extra) {
            $pemContent .= $extra . "\n";
        }
    }

    $pemTmpPath = sys_get_temp_dir() . '/sifen_lookup_pos_' . uniqid('', true) . '.pem';
    file_put_contents($pemTmpPath, $pemContent);

    $soapXml = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
  <soap:Body>
    <xsd:rEnviConsRUC>
      <xsd:dId>1</xsd:dId>
      <xsd:dRUCCons>' . htmlspecialchars($rucSoloNum, ENT_QUOTES, 'UTF-8') . '</xsd:dRUCCons>
    </xsd:rEnviConsRUC>
  </soap:Body>
</soap:Envelope>';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://sifen.set.gov.py/de/ws/consultas/consulta-ruc.wsdl',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $soapXml,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/soap+xml; charset=utf-8',
            'SOAPAction: ""',
        ],
        CURLOPT_SSLCERT => $pemTmpPath,
        CURLOPT_SSLCERTPASSWD => '',
        CURLOPT_SSLKEY => $pemTmpPath,
        CURLOPT_SSLKEYPASSWD => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($pemTmpPath);

    if ($curlError) {
        return ['success' => false, 'error' => 'Error de conexión con SIFEN'];
    }
    if ($httpCode !== 200 || empty($response)) {
        return ['success' => false, 'error' => 'SIFEN no respondió correctamente'];
    }

    try {
        $cleanXml = preg_replace('/<(\/?)\w+:(\w+)/', '<$1$2', (string)$response);
        $xmlRes = new SimpleXMLElement((string)$cleanXml);
        $dCodRes = (string)($xmlRes->xpath('//dCodRes')[0] ?? '');

        if ($dCodRes !== '0502' && $dCodRes !== '0260') {
            $dMsgRes = (string)($xmlRes->xpath('//dMsgRes')[0] ?? 'RUC/Cédula no encontrado en SIFEN');
            return ['success' => false, 'error' => $dMsgRes];
        }

        $xContr = $xmlRes->xpath('//xContRUC')[0] ?? $xmlRes->xpath('//xContr')[0] ?? null;
        if (!$xContr) {
            return ['success' => false, 'error' => 'Respuesta inválida de SIFEN'];
        }

        $foundNombre = trim((string)($xContr->dRazCons ?? $xContr->dRazSoc ?? ''));
        $foundRucBase = trim((string)($xContr->dRUCCons ?? $xContr->dRUC ?? ''));
        $foundDV = calcularDVPos($foundRucBase);
        if ($foundNombre === '' || $foundRucBase === '') {
            return ['success' => false, 'error' => 'No se pudo validar el RUC/Cédula en SIFEN'];
        }

        return [
            'success' => true,
            'data' => [
                'razon_social' => $foundNombre,
                'ruc_base' => $foundRucBase,
                'dv' => $foundDV,
                'ruc' => $foundRucBase . '-' . $foundDV,
                'direccion' => (string)($xContr->dDirEmi ?? ''),
                'telefono' => (string)($xContr->dTelEmi ?? ''),
            ],
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Error procesando respuesta de SIFEN'];
    }
}

function buscarRucPyFallback(PDO $pdoMaster, string $docRaw): array
{
    $docNum = preg_replace('/[^0-9]/', '', $docRaw);
    if (strlen($docNum) < 5) {
        return ['success' => false, 'error' => 'RUC/Cédula inválida para fallback'];
    }

    try {
        $stmtTbl = $pdoMaster->query("SHOW TABLES FROM serproc1 LIKE 'ruc_py'");
        if (!$stmtTbl || !$stmtTbl->fetchColumn()) {
            return ['success' => false, 'error' => 'Tabla ruc_py no disponible'];
        }

        $cols = $pdoMaster->query("SHOW COLUMNS FROM " . MASTER_DB . ".ruc_py")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!is_array($cols) || empty($cols)) {
            return ['success' => false, 'error' => 'No se pudieron leer columnas de ruc_py'];
        }

        $lowerToReal = [];
        foreach ($cols as $c) {
            $lowerToReal[strtolower((string)$c)] = (string)$c;
        }

        $pickCol = static function (array $candidates) use ($lowerToReal): ?string {
            foreach ($candidates as $c) {
                $k = strtolower($c);
                if (isset($lowerToReal[$k])) return $lowerToReal[$k];
            }
            return null;
        };

        $colRuc = $pickCol(['ruc', 'ruc_base', 'nro_ruc', 'numero', 'documento', 'doc', 'ruc_ci']);
        $colDv = $pickCol(['dv', 'digito_verificador', 'dvruc', 'verificador']);
        $colNombre = $pickCol(['razon_social', 'razonsocial', 'nombre', 'contribuyente', 'denominacion', 'razon']);
        $colDir = $pickCol(['direccion', 'domicilio', 'dir']);
        $colTel = $pickCol(['telefono', 'tel', 'celular', 'movil']);

        if ($colRuc === null || $colNombre === null) {
            return ['success' => false, 'error' => 'Estructura ruc_py incompatible'];
        }

        $qi = static function (string $id): string {
            return '`' . str_replace('`', '``', $id) . '`';
        };

        $rucExpr = "REPLACE(REPLACE(REPLACE(COALESCE(" . $qi($colRuc) . ", ''), '.', ''), '-', ''), ' ', '')";
        $where = $rucExpr . " = :doc";
        if ($colDv !== null) {
            $dvExpr = "REPLACE(REPLACE(REPLACE(COALESCE(" . $qi($colDv) . ", ''), '.', ''), '-', ''), ' ', '')";
            $where .= " OR CONCAT($rucExpr, $dvExpr) = :doc";
        }

        $select = [
            $qi($colRuc) . " AS ruc_raw",
            $qi($colNombre) . " AS nombre_raw",
        ];
        if ($colDv !== null) $select[] = $qi($colDv) . " AS dv_raw";
        if ($colDir !== null) $select[] = $qi($colDir) . " AS dir_raw";
        if ($colTel !== null) $select[] = $qi($colTel) . " AS tel_raw";

        $sql = "SELECT " . implode(', ', $select) . " FROM " . MASTER_DB . ".ruc_py WHERE ($where) LIMIT 1";
        $stmt = $pdoMaster->prepare($sql);
        $stmt->execute([':doc' => $docNum]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['success' => false, 'error' => 'No encontrado en ruc_py'];
        }

        $foundNombre = trim((string)($row['nombre_raw'] ?? ''));
        $foundRucBase = preg_replace('/[^0-9]/', '', (string)($row['ruc_raw'] ?? ''));
        $foundDv = preg_replace('/[^0-9]/', '', (string)($row['dv_raw'] ?? ''));
        if ($foundRucBase === '') {
            return ['success' => false, 'error' => 'Registro ruc_py inválido'];
        }
        if ($foundDv === '') {
            $foundDv = calcularDVPos($foundRucBase);
        }

        return [
            'success' => true,
            'data' => [
                'razon_social' => $foundNombre !== '' ? $foundNombre : ('RUC ' . $foundRucBase),
                'ruc_base' => $foundRucBase,
                'dv' => $foundDv,
                'ruc' => $foundRucBase . '-' . $foundDv,
                'direccion' => trim((string)($row['dir_raw'] ?? '')),
                'telefono' => trim((string)($row['tel_raw'] ?? '')),
                'source' => 'ruc_py',
            ],
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Error consultando ruc_py'];
    }
}

function upsertClienteDesdeLookup(PDO $pdo, string $dbName, array $data, int $idLogin): int
{
    $num = trim((string)($data['ruc'] ?? ''));
    $nom = substr(trim((string)($data['razon_social'] ?? '')), 0, 60);
    if ($num === '' || $nom === '') return 0;

    $dir = substr(trim((string)($data['direccion'] ?? '')), 0, 255);
    $tel = substr(trim((string)($data['telefono'] ?? '')), 0, 255);
    $llave = $idLogin . ".1." . $num;

    $cols = $pdo->query("SHOW COLUMNS FROM {$dbName}.clientes")->fetchAll(PDO::FETCH_COLUMN, 0);
    $has = [];
    foreach ((array)$cols as $c) $has[strtolower((string)$c)] = true;
    if (empty($has)) return 0;

    $q = static fn(string $id): string => '`' . str_replace('`', '``', $id) . '`';

    // 1) Buscar existente por numero
    $id = 0;
    try {
        $st = $pdo->prepare("SELECT id FROM {$dbName}.clientes WHERE numero = :num LIMIT 1");
        $st->execute([':num' => $num]);
        $id = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $id = 0;
    }

    if ($id > 0) {
        // 2) Actualizar existente con columnas disponibles
        $set = [];
        $params = [':id' => $id];
        if (!empty($has['nombre'])) { $set[] = $q('nombre') . " = :nom"; $params[':nom'] = $nom; }
        if (!empty($has['direccion'])) { $set[] = $q('direccion') . " = :dir"; $params[':dir'] = $dir; }
        if (!empty($has['telefono'])) { $set[] = $q('telefono') . " = :tel"; $params[':tel'] = $tel; }
        if (!empty($has['origen_sifen'])) { $set[] = $q('origen_sifen') . " = 1"; }
        if (!empty($set)) {
            $sql = "UPDATE {$dbName}.clientes SET " . implode(', ', $set) . " WHERE id = :id";
            $pdo->prepare($sql)->execute($params);
        }
        return $id;
    }

    // 3) Insertar nuevo con columnas existentes
    $insCols = [];
    $insVals = [];
    $params = [];
    $add = function (string $col, string $ph, $val) use (&$insCols, &$insVals, &$params, $has, $q) {
        if (!empty($has[strtolower($col)])) {
            $insCols[] = $q($col);
            $insVals[] = $ph;
            $params[$ph] = $val;
        }
    };

    $add('sucursal', ':sucursal', 1);
    $add('cuenta', ':cuenta', 1);
    $add('fecha', ':fecha', date('Y-m-d'));
    $add('documento', ':documento', '11');
    $add('numero', ':num', $num);
    $add('nombre', ':nom', $nom);
    $add('direccion', ':dir', $dir);
    $add('pais', ':pais', 1);
    $add('ciudad', ':ciudad', 1);
    $add('estado', ':estado', 1);
    $add('llave', ':llave', $llave);
    $add('raiting', ':raiting', 1);
    $add('login', ':login', $idLogin);
    $add('telefono', ':tel', $tel);
    $add('email', ':email', '');
    $add('origen_sifen', ':origen_sifen', 1);

    if (empty($insCols)) return 0;

    $sqlIns = "INSERT INTO {$dbName}.clientes (" . implode(', ', $insCols) . ")
               VALUES (" . implode(', ', $insVals) . ")";
    $pdo->prepare($sqlIns)->execute($params);
    return (int)($pdo->lastInsertId() ?: 0);
}

try {
    // Usar configuración centralizada
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    $empresaConfig = $conn['config'];
    $pdoMaster = $conn['masterPdo'];

    if (!$dbName) {
        throw new Exception("Base de datos no encontrada");
    }

    switch ($action) {
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['error' => 'ID inválido']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    nombre,
                    numero AS ruc,
                    direccion,
                    telefono,
                    email,
                    ciudad,
                    linea_credito,
                    saldo_guaranies,
                    'local' AS source
                FROM $dbName.clientes
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cliente) {
                echo json_encode(['error' => 'Cliente no encontrado']);
                exit;
            }

            echo json_encode(['cliente' => $cliente]);
            break;

        case 'search':
            $q = trim($_GET['q'] ?? '');

            if (strlen($q) < 2) {
                echo json_encode(['clientes' => []]);
                exit;
            }

            // Búsqueda multi-palabra: cada palabra debe coincidir en algún campo
            $words = preg_split('/\s+/', $q);
            $conditions = [];
            $params = [];
            $i = 0;

            foreach ($words as $word) {
                if (strlen($word) >= 1) {
                    $paramKey = ":w{$i}";
                    $conditions[] = "(nombre LIKE $paramKey OR numero LIKE $paramKey OR telefono LIKE $paramKey OR email LIKE $paramKey)";
                    $params[$paramKey] = '%' . $word . '%';
                    $i++;
                }
            }

            if (empty($conditions)) {
                echo json_encode(['clientes' => []]);
                exit;
            }

            $whereClause = implode(" AND ", $conditions);

            $stmt = $pdo->prepare("
                SELECT 
                    id, 
                    nombre, 
                    numero AS ruc, 
                    direccion, 
                    telefono, 
                    email,
                    ciudad,
                    linea_credito,
                    saldo_guaranies,
                    'local' AS source
                FROM $dbName.clientes
                WHERE $whereClause
                ORDER BY nombre
                LIMIT 15
            ");
            $stmt->execute($params);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['clientes' => $clientes]);
            break;

        case 'sifen_lookup':
            $q = trim($_GET['ruc'] ?? $_GET['q'] ?? '');
            if (strlen($q) < 5) {
                echo json_encode(['error' => 'RUC/Cédula muy corta para consulta SIFEN']);
                exit;
            }

            // 1) Buscar local exacto antes de consultar SIFEN.
            $stmt = $pdo->prepare("SELECT id, nombre, numero AS ruc, direccion, telefono, email, ciudad FROM $dbName.clientes WHERE numero = :q");
            $stmt->execute([':q' => $q]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cliente) {
                echo json_encode(['cliente' => $cliente, 'source' => 'local']);
                exit;
            }

            // 2) Primero buscar en serproc1.ruc_py
            $lookup = buscarRucPyFallback($pdoMaster, $q);

            // 3) Si no está en ruc_py, consultar SIFEN (esquema suscribete.php)
            if (empty($lookup['success']) || empty($lookup['data'])) {
                $lookup = consultarRucSifenPos($q, $pdoMaster);
                if (empty($lookup['success']) || empty($lookup['data'])) {
                    echo json_encode(['error' => 'No se encontró en ruc_py ni fue posible obtener respuesta válida de SIFEN']);
                    exit;
                }
            }

            $foundNombre = trim((string)($lookup['data']['razon_social'] ?? ''));
            $foundRuc = trim((string)($lookup['data']['ruc'] ?? ''));
            if ($foundNombre === '' || $foundRuc === '') {
                echo json_encode(['error' => 'Respuesta inválida de SIFEN']);
                exit;
            }

            $newId = upsertClienteDesdeLookup($pdo, $dbName, $lookup['data'], $id_login);
            if ($newId <= 0) {
                $stmtGet = $pdo->prepare("SELECT id FROM $dbName.clientes WHERE numero = :num LIMIT 1");
                $stmtGet->execute([':num' => $foundRuc]);
                $newId = (int)($stmtGet->fetchColumn() ?: 0);
            }

            echo json_encode(['cliente' => [
                'id' => $newId,
                'nombre' => $foundNombre,
                'ruc' => $foundRuc,
                'direccion' => (string)($lookup['data']['direccion'] ?? ''),
                'telefono' => (string)($lookup['data']['telefono'] ?? ''),
                'email' => '',
                'ciudad' => 1,
                'source' => 'sifen'
            ]]);
            exit;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
