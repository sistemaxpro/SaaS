<?php

/**
 * AI Chat API - Sistemax Internal Assistant
 * Endpoint con acceso real a DB de la empresa activa.
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

// Cargar librería Gemini desde rutas compatibles
$geminiLoaded = false;
$geminiCandidates = [
    __DIR__ . '/../../../modelos/gemini_lib.php',
    __DIR__ . '/../../modelos/gemini_lib.php',
    __DIR__ . '/../../gemini_lib.php',
];
foreach ($geminiCandidates as $p) {
    if (is_file($p)) {
        require_once $p;
        $geminiLoaded = true;
        break;
    }
}

function assertIdentifier($name, $label = 'identificador')
{
    if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new Exception("$label inválido");
    }
    return $name;
}

function formatGs($value)
{
    return number_format((float)$value, 0, ',', '.');
}

$idEmpresa = (int)($_SESSION['id_empresa'] ?? 169);
if ($idEmpresa <= 0) {
    throw new Exception('Sesión inválida: id_empresa no definido');
}

$conn = getEmpresaConnection($idEmpresa);
$pdo = $conn['pdo'];
$dbName = assertIdentifier($conn['dbName'] ?? ($_SESSION['db'] ?? ''), 'base de datos');
$dbQ = "`$dbName`";
$authorizedDbs = [$dbName, 'serproc1'];
$extraDbsEnv = trim((string)getenv('VCORTA_EXTRA_DBS'));
if ($extraDbsEnv !== '') {
    foreach (preg_split('/[,\s;]+/', $extraDbsEnv) as $dbx) {
        $dbx = trim((string)$dbx);
        if ($dbx !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $dbx)) $authorizedDbs[] = $dbx;
    }
}
$authorizedDbs = array_values(array_unique(array_map(function ($x) {
    return assertIdentifier($x, 'base');
}, $authorizedDbs)));
$useGemini = false;
$useOllamaEnv = strtolower(trim((string)getenv('VCORTA_USE_OLLAMA')));
$useOllama = !in_array($useOllamaEnv, ['0', 'false', 'no', 'off'], true);
$localOnlyEnv = strtolower(trim((string)getenv('VCORTA_LOCAL_ONLY')));
$localOnly = in_array($localOnlyEnv, ['1', 'true', 'yes', 'on'], true);
$ollamaUrl = trim((string)getenv('OLLAMA_URL'));
if ($ollamaUrl === '') $ollamaUrl = 'http://127.0.0.1:11434/api/chat';
$defaultOllamaModel = 'qwen2.5:3b';
$defaultFallbackModel = '';
$ollamaModel = trim((string)getenv('OLLAMA_MODEL'));
if ($ollamaModel === '') $ollamaModel = $defaultOllamaModel;
$ollamaFallbackModel = trim((string)getenv('OLLAMA_FALLBACK_MODEL'));
if ($ollamaFallbackModel === '') $ollamaFallbackModel = $defaultFallbackModel;
$ollamaTimeout = (int)(getenv('OLLAMA_TIMEOUT') ?: 12);
if ($ollamaTimeout < 8) $ollamaTimeout = 8;
$routingMode = strtolower(trim((string)getenv('VCORTA_ROUTING')));
if ($routingMode === '') $routingMode = 'openai_first'; // smart | openai_first | ollama_first

$pdoMaster = null;
try {
    $pdoMaster = getMasterConnection();
    $stmtGem = $pdoMaster->prepare("SELECT gemini_flash FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id");
    $stmtGem->execute([':id' => $idEmpresa]);
    $useGemini = (bool)$stmtGem->fetchColumn();
} catch (Throwable $e) {
    $useGemini = false;
}

// API Key OpenAI
$apiKey = getenv('OPENAI_API_KEY') ?: '';
$speedProfile = strtolower(trim((string)getenv('VCORTA_SPEED_PROFILE')));
if ($speedProfile === '') $speedProfile = 'fast';
$isFastProfile = $speedProfile !== 'quality';

$openAiModel = trim((string)getenv('OPENAI_MODEL'));
if ($openAiModel === '') $openAiModel = $isFastProfile ? 'gpt-4.1-mini' : 'gpt-4.1';
$openAiMaxTokens = (int)(getenv('OPENAI_MAX_TOKENS') ?: ($isFastProfile ? 260 : 700));
if ($openAiMaxTokens < 120) $openAiMaxTokens = 120;
$openAiTemperature = (float)(getenv('OPENAI_TEMPERATURE') ?: ($isFastProfile ? 0.35 : 0.7));
$historyWindow = (int)(getenv('VCORTA_HISTORY_WINDOW') ?: ($isFastProfile ? 4 : 8));
if ($historyWindow < 2) $historyWindow = 2;
$dbContextMaxChars = (int)(getenv('VCORTA_DB_CONTEXT_MAX_CHARS') ?: ($isFastProfile ? 3200 : 9000));
if ($dbContextMaxChars < 800) $dbContextMaxChars = 800;
$enableGeminiFallbackEnv = strtolower(trim((string)getenv('VCORTA_ENABLE_GEMINI_FALLBACK')));
$enableGeminiFallback = in_array($enableGeminiFallbackEnv, ['1', 'true', 'yes', 'on'], true);
$enableOpenAiRetryEnv = strtolower(trim((string)getenv('VCORTA_ENABLE_OPENAI_RETRY')));
$enableOpenAiRetry = in_array($enableOpenAiRetryEnv, ['1', 'true', 'yes', 'on'], true);

if ($apiKey === '') {
    $openAiConfigCandidates = [
        __DIR__ . '/../../../config/openai.php',
        __DIR__ . '/../../config/openai.php',
    ];
    foreach ($openAiConfigCandidates as $cfgPath) {
        if (is_file($cfgPath)) {
            $cfg = include $cfgPath;
            $apiKey = $cfg['api_key'] ?? '';
            if ($apiKey !== '') break;
        }
    }
}

function detectClientesPkColumn(PDO $pdo, $dbName)
{
    $sql = "SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = :db
              AND table_name = 'clientes'
              AND column_name IN ('id', 'id_cliente')
            ORDER BY FIELD(column_name, 'id', 'id_cliente')
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName]);
    $col = (string)($stmt->fetchColumn() ?: '');
    return ($col === 'id' || $col === 'id_cliente') ? $col : 'id';
}

function detectClientesRucColumn(PDO $pdo, $dbName)
{
    $sql = "SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = :db
              AND table_name = 'clientes'
              AND column_name IN ('ruc', 'ruc_cliente', 'ruc_ci', 'documento')
            ORDER BY FIELD(column_name, 'ruc', 'ruc_cliente', 'ruc_ci', 'documento')
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName]);
    $col = (string)($stmt->fetchColumn() ?: '');
    return $col !== '' ? $col : '';
}

function getClientesRucExpr($alias, $clientesRucCol = '')
{
    if ($clientesRucCol !== '') {
        $col = '`' . assertIdentifier($clientesRucCol, 'columna ruc cliente') . '`';
        return "COALESCE($alias.$col, '-') AS ruc";
    }
    return "'-' AS ruc";
}

function buscarCliente(PDO $pdo, $dbQ, $nombre, $clientesIdCol = 'id', $clientesRucCol = '')
{
    $like = '%' . trim((string)$nombre) . '%';
    $idCol = '`' . assertIdentifier($clientesIdCol, 'columna cliente') . '`';
    $rucExpr = getClientesRucExpr('c', $clientesRucCol);
    $rucWhere = '';
    if ($clientesRucCol !== '') {
        $rucCol = '`' . assertIdentifier($clientesRucCol, 'columna ruc cliente') . '`';
        $rucWhere = " OR c.$rucCol LIKE :q ";
    }

    $sql = "SELECT c.$idCol AS id_cliente, c.nombre, $rucExpr, c.telefono, c.direccion,
                   COALESCE((SELECT SUM(saldo) FROM $dbQ.factura_ventas WHERE id_cliente = c.$idCol AND saldo > 0 AND estado = 1), 0) AS saldo_pendiente
            FROM $dbQ.clientes c
            WHERE c.nombre LIKE :q $rucWhere
            LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q' => $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getFacturasPendientesCliente(PDO $pdo, $dbQ, $idCliente)
{
    $sql = "SELECT f.id_factura, f.nro_factura, f.fecha, f.total, f.saldo
            FROM $dbQ.factura_ventas f
            WHERE f.id_cliente = :id AND f.saldo > 0 AND f.estado = 1
            ORDER BY f.fecha DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => (int)$idCliente]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getProducto(PDO $pdo, $dbQ, $nombre)
{
    $like = '%' . trim((string)$nombre) . '%';
    $sql = "SELECT p.id_producto, p.descripcion, p.codigo, p.stock,
                   (SELECT precio FROM $dbQ.mercaderia_precio WHERE id_producto = p.id_producto AND id_tipo_precio = 1 LIMIT 1) AS precio
            FROM $dbQ.tblproductos p
            WHERE p.descripcion LIKE :q OR p.codigo LIKE :q
            LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q' => $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getVentasHoy(PDO $pdo, $dbQ)
{
    $sql = "SELECT COUNT(*) AS cantidad, SUM(total) AS total
            FROM $dbQ.factura_ventas
            WHERE DATE(fecha) = CURDATE() AND estado = 1";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['cantidad' => 0, 'total' => 0];
}

function getVentasFecha(PDO $pdo, $dbQ, $fecha)
{
    $sql = "SELECT COUNT(*) AS cantidad, SUM(total) AS total
            FROM $dbQ.factura_ventas
            WHERE DATE(fecha) = :f AND estado = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':f' => $fecha]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['cantidad' => 0, 'total' => 0];
}

function getVentasRango(PDO $pdo, $dbQ, $fechaIni, $fechaFin)
{
    $sql = "SELECT COUNT(*) AS cantidad, SUM(total) AS total
            FROM $dbQ.factura_ventas
            WHERE DATE(fecha) BETWEEN :fi AND :ff AND estado = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fi' => $fechaIni, ':ff' => $fechaFin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['cantidad' => 0, 'total' => 0];
}

function getVentasUltimosMeses(PDO $pdo, $dbQ, $meses = 6)
{
    $meses = max(1, min((int)$meses, 24));
    $hoy = new DateTime('today');
    $inicioGlobal = (clone $hoy)->modify('first day of this month')->modify('-' . ($meses - 1) . ' months')->format('Y-m-d');
    $finGlobal = (clone $hoy)->modify('last day of this month')->format('Y-m-d');

    $sql = "SELECT DATE_FORMAT(fecha, '%Y-%m') AS ym,
                   COUNT(*) AS cantidad,
                   SUM(total) AS total
            FROM $dbQ.factura_ventas
            WHERE DATE(fecha) BETWEEN :fi AND :ff
              AND estado = 1
            GROUP BY DATE_FORMAT(fecha, '%Y-%m')
            ORDER BY ym ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fi' => $inicioGlobal, ':ff' => $finGlobal]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $r) {
        $map[(string)$r['ym']] = [
            'cantidad' => (int)($r['cantidad'] ?? 0),
            'total' => (float)($r['total'] ?? 0)
        ];
    }

    $out = [];
    for ($i = $meses - 1; $i >= 0; $i--) {
        $ym = (clone $hoy)->modify('first day of this month')->modify("-{$i} months")->format('Y-m');
        $out[] = [
            'ym' => $ym,
            'cantidad' => (int)($map[$ym]['cantidad'] ?? 0),
            'total' => (float)($map[$ym]['total'] ?? 0)
        ];
    }

    return $out;
}

function getTopClientesCompras(PDO $pdo, $dbQ, $clientesIdCol = 'id', $limit = 10, $fechaIni = null, $fechaFin = null, $clientesRucCol = '')
{
    $limit = max(1, min((int)$limit, 30));
    $idCol = '`' . assertIdentifier($clientesIdCol, 'columna cliente') . '`';
    $rucExpr = getClientesRucExpr('c', $clientesRucCol);
    $whereFecha = '';
    $params = [];
    if ($fechaIni && $fechaFin) {
        $whereFecha = " AND DATE(f.fecha) BETWEEN :fi AND :ff ";
        $params[':fi'] = $fechaIni;
        $params[':ff'] = $fechaFin;
    }

    $sql = "SELECT f.id_cliente,
                   COALESCE(NULLIF(TRIM(c.nombre), ''), CONCAT('Cliente ', f.id_cliente)) AS nombre,
                   $rucExpr,
                   COUNT(*) AS cantidad_facturas,
                   SUM(f.total) AS total_comprado,
                   MAX(f.fecha) AS ultima_compra
            FROM $dbQ.factura_ventas f
            LEFT JOIN $dbQ.clientes c ON c.$idCol = f.id_cliente
            WHERE f.estado = 1
              AND f.id_cliente IS NOT NULL
              AND f.id_cliente > 0
              $whereFecha
            GROUP BY f.id_cliente
            ORDER BY total_comprado DESC
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getClientesMorosos(PDO $pdo, $dbQ, $clientesIdCol = 'id', $limit = 20, $clientesRucCol = '')
{
    $limit = max(1, min((int)$limit, 50));
    $idCol = '`' . assertIdentifier($clientesIdCol, 'columna cliente') . '`';
    $rucExpr = getClientesRucExpr('c', $clientesRucCol);
    $sql = "SELECT f.id_cliente,
                   COALESCE(NULLIF(TRIM(c.nombre), ''), CONCAT('Cliente ', f.id_cliente)) AS nombre,
                   $rucExpr,
                   SUM(f.saldo) AS deuda_total,
                   COUNT(*) AS facturas_pendientes,
                   MAX(f.fecha) AS ultima_factura
            FROM $dbQ.factura_ventas f
            LEFT JOIN $dbQ.clientes c ON c.$idCol = f.id_cliente
            WHERE f.estado = 1
              AND f.saldo > 0
              AND f.id_cliente IS NOT NULL
              AND f.id_cliente > 0
            GROUP BY f.id_cliente
            ORDER BY deuda_total DESC
            LIMIT $limit";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getTopProductosVentas(PDO $pdo, $dbQ, $limit = 10, $fechaIni = null, $fechaFin = null)
{
    $limit = max(1, min((int)$limit, 30));
    $whereFecha = '';
    $params = [];
    if ($fechaIni && $fechaFin) {
        $whereFecha = " AND DATE(f.fecha) BETWEEN :fi AND :ff ";
        $params[':fi'] = $fechaIni;
        $params[':ff'] = $fechaFin;
    }

    $sql = "SELECT d.id_producto,
                   COALESCE(NULLIF(TRIM(d.descripcion), ''), CONCAT('Producto ', d.id_producto)) AS producto,
                   SUM(d.cantidad) AS cantidad_total,
                   SUM(d.total) AS total_vendido
            FROM $dbQ.factura_ventas f
            JOIN $dbQ.factura_ventas_det d ON d.id_factura = f.id_factura
            WHERE f.estado = 1
              $whereFecha
            GROUP BY d.id_producto, producto
            ORDER BY total_vendido DESC
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getClientesSinCompra(PDO $pdo, $dbQ, $clientesIdCol = 'id', $dias = 30, $limit = 50, $clientesRucCol = '')
{
    $dias = max(1, min((int)$dias, 365));
    $limit = max(1, min((int)$limit, 100));
    $idCol = '`' . assertIdentifier($clientesIdCol, 'columna cliente') . '`';
    $rucExpr = getClientesRucExpr('c', $clientesRucCol);
    $sql = "SELECT c.$idCol AS id_cliente,
                   COALESCE(NULLIF(TRIM(c.nombre), ''), CONCAT('Cliente ', c.$idCol)) AS nombre,
                   $rucExpr,
                   MAX(f.fecha) AS ultima_compra
            FROM $dbQ.clientes c
            LEFT JOIN $dbQ.factura_ventas f
                   ON f.id_cliente = c.$idCol
                  AND f.estado = 1
            GROUP BY c.$idCol
            HAVING (ultima_compra IS NULL OR DATE(ultima_compra) < DATE_SUB(CURDATE(), INTERVAL :dias DAY))
            ORDER BY ultima_compra IS NULL DESC, ultima_compra ASC
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getTopClientesDeudores(PDO $pdo, $dbQ, $clientesIdCol = 'id', $limit = 5, $clientesRucCol = '')
{
    $limit = max(1, min((int)$limit, 20));
    $idCol = '`' . assertIdentifier($clientesIdCol, 'columna cliente') . '`';
    $rucExpr = getClientesRucExpr('c', $clientesRucCol);
    $sql = "SELECT c.nombre, $rucExpr, SUM(f.saldo) AS deuda
            FROM $dbQ.factura_ventas f
            JOIN $dbQ.clientes c ON f.id_cliente = c.$idCol
            WHERE f.saldo > 0 AND f.estado = 1
            GROUP BY f.id_cliente
            ORDER BY deuda DESC
            LIMIT $limit";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tableExistsInDb(PDO $pdo, $dbName, $tableName)
{
    assertIdentifier($tableName, 'tabla');
    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = :db AND table_name = :tb LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName, ':tb' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function listTablesInDb(PDO $pdo, $dbName, $limit = 120)
{
    $limit = max(1, min((int)$limit, 500));
    $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = :db ORDER BY table_name LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function describeTableInDb(PDO $pdo, $dbName, $tableName, $limit = 200)
{
    if (!tableExistsInDb($pdo, $dbName, $tableName)) return [];
    $limit = max(1, min((int)$limit, 500));
    $sql = "SELECT column_name, data_type, is_nullable, column_key, column_default
            FROM information_schema.columns
            WHERE table_schema = :db AND table_name = :tb
            ORDER BY ordinal_position
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':db' => $dbName, ':tb' => $tableName]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function listRowsFromTable(PDO $pdo, $dbName, $dbQ, $tableName, $limit = 20)
{
    if (!tableExistsInDb($pdo, $dbName, $tableName)) {
        return ['ok' => false, 'error' => "La tabla '$tableName' no existe en la base '$dbName'."];
    }
    $limit = max(1, min((int)$limit, 100));
    $tbQ = '`' . $tableName . '`';
    $sql = "SELECT * FROM $dbQ.$tbQ LIMIT $limit";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'rows' => $rows];
}

function sanitizeReadOnlySql($sql)
{
    $q = trim((string)$sql);
    $q = preg_replace('/;\s*$/', '', $q);
    if ($q === '') return ['ok' => false, 'error' => 'SQL vacío'];
    if (strpos($q, ';') !== false) return ['ok' => false, 'error' => 'Solo se permite una consulta SQL por vez'];

    if (!preg_match('/^\s*(select|show|describe|desc|explain)\b/i', $q)) {
        return ['ok' => false, 'error' => 'Solo se permiten consultas de lectura (SELECT/SHOW/DESCRIBE/EXPLAIN)'];
    }

    if (preg_match('/\b(insert|update|delete|drop|alter|truncate|create|replace|grant|revoke|into\s+outfile|load_file|dumpfile|call)\b/i', strtolower($q))) {
        return ['ok' => false, 'error' => 'Consulta bloqueada por seguridad: solo lectura'];
    }

    if (preg_match('/^\s*select\b/i', $q) && !preg_match('/\blimit\s+\d+\b/i', $q)) {
        $q .= ' LIMIT 50';
    }

    return ['ok' => true, 'sql' => $q];
}

function isAuthorizedDbName($dbName, array $authorizedDbs)
{
    return in_array((string)$dbName, $authorizedDbs, true);
}

function pickPdoForDb($targetDb, $empresaDb, PDO $pdoEmpresa, ?PDO $pdoMaster)
{
    if ($targetDb === 'serproc1' && $pdoMaster instanceof PDO) return $pdoMaster;
    return $pdoEmpresa;
}

function parseDbAndTableToken($token, $defaultDb, array $authorizedDbs)
{
    $token = trim((string)$token);
    if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $token)) return ['db' => $defaultDb, 'table' => $token];
    if (strpos($token, '.') === false) return ['db' => $defaultDb, 'table' => $token];
    [$db, $tb] = explode('.', $token, 2);
    $db = assertIdentifier($db, 'base');
    $tb = assertIdentifier($tb, 'tabla');
    if (!isAuthorizedDbName($db, $authorizedDbs)) {
        throw new Exception("Base '$db' no autorizada para Vcorta.");
    }
    return ['db' => $db, 'table' => $tb];
}

function runReadOnlySql(PDO $pdo, $sql)
{
    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if (count($rows) > 80) $rows = array_slice($rows, 0, 80);
        return ['ok' => true, 'rows' => $rows, 'count' => count($rows)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function getBeginnerHelpText()
{
    return "[DATOS DEL SISTEMA]\n" .
        "Guia rapida para usuario principiante:\n" .
        "1) Abrir POS y elegir cliente.\n" .
        "2) Cargar productos (codigo o descripcion).\n" .
        "3) Revisar total y confirmar medio de pago.\n" .
        "4) Emitir documento (Control o Factura Electronica).\n" .
        "5) Reimprimir desde Mis Ventas si hace falta.\n" .
        "6) Controlar caja del dia en modulo Caja.\n" .
        "7) Verificar clientes con saldo pendiente cuando corresponda.\n\n" .
        "Consultas utiles que podes hacerle a Vcorta:\n" .
        "- Cuanto vendimos hoy\n" .
        "- Top clientes con deuda\n" .
        "- Listar tablas\n" .
        "- Columnas de factura_ventas\n" .
        "- Abrir POS / Caja / Facturas / Clientes / Productos / Compras\n\n" .
        "Si queres, decime tu tarea y te guio paso a paso dentro del sistema.";
}

function clampText($text, $maxChars = 5000)
{
    $s = (string)$text;
    if ($maxChars <= 0 || strlen($s) <= $maxChars) return $s;
    return substr($s, 0, $maxChars) . "\n[DATOS RECORTADOS POR RENDIMIENTO]";
}

function cleanDbContextForReply($dbContext)
{
    $txt = trim((string)$dbContext);
    if ($txt === '') return '';
    $txt = preg_replace('/^\[DATOS DEL SISTEMA\]\s*/i', '', $txt);
    $txt = preg_replace('/^\s*\[DATOS DEL SISTEMA\]\s*/im', '', $txt);
    return trim($txt);
}

function isVentasHoyIntent($text)
{
    $txt = trim((string)$text);
    if ($txt === '') return false;
    $hasHoy = (bool)preg_match('/\b(hoy|de\s+hoy|del?\s*d[ií]a|dia)\b/iu', $txt);
    $hasVentas = (bool)preg_match('/\b(venta|ventas|vendimos|vendi[oó]|facturamos|facturado|total vendido|recaudaci[oó]n|ingresos?)\b/iu', $txt);
    return $hasHoy && $hasVentas;
}

function getVentasDiaIntent($text)
{
    $txt = trim((string)$text);
    if ($txt === '') return null;

    $hasVentas = (bool)preg_match('/\b(venta|ventas|vendimos|vendi[oó]|facturamos|facturado|total vendido|recaudaci[oó]n|ingresos?)\b/iu', $txt);
    if (!$hasVentas) return null;

    $base = new DateTime('today');

    if (preg_match('/\b(hoy|de\s+hoy|del?\s*d[ií]a|dia)\b/iu', $txt)) {
        return ['label' => 'hoy', 'fecha' => $base->format('Y-m-d')];
    }
    if (preg_match('/\b(de\s+ayer|ayer)\b/iu', $txt)) {
        return ['label' => 'ayer', 'fecha' => (clone $base)->modify('-1 day')->format('Y-m-d')];
    }
    if (preg_match('/\b(anteayer|antes\s+de\s+ayer)\b/iu', $txt)) {
        return ['label' => 'anteayer', 'fecha' => (clone $base)->modify('-2 day')->format('Y-m-d')];
    }

    return null;
}

function isVentasMesPasadoIntent($text)
{
    $txt = trim((string)$text);
    if ($txt === '') return false;
    $hasVentas = (bool)preg_match('/\b(venta|ventas|vendimos|vendi[oó]|facturamos|facturado|total vendido|recaudaci[oó]n|ingresos?)\b/iu', $txt);
    if (!$hasVentas) return false;
    return (bool)preg_match('/\b(mes\s+pasado|mes\s+anterior|del\s+mes\s+pasado|del\s+mes\s+anterior)\b/iu', $txt);
}

function buildDirectReplyFromContext($userMessage, $dbContext)
{
    $ctx = cleanDbContextForReply($dbContext);
    if ($ctx === '') return '';

    $u = mb_strtolower(trim((string)$userMessage), 'UTF-8');

    // Respuesta determinística para ventas del mes pasado
    if (isVentasMesPasadoIntent($u)) {
        $cant = null;
        $total = null;
        if (preg_match('/Facturas:\s*([0-9\.\,]+)/iu', $ctx, $m1)) $cant = $m1[1];
        if (preg_match('/Total:\s*Gs\.\s*([0-9\.\,]+)/iu', $ctx, $m2)) $total = $m2[1];
        if ($cant !== null || $total !== null) {
            $parts = [];
            if ($cant !== null) $parts[] = "Facturas mes pasado: $cant";
            if ($total !== null) $parts[] = "Total vendido: Gs. $total";
            return implode(" | ", $parts);
        }
    }

    // Respuesta determinística para ventas por día relativo (hoy/ayer/anteayer)
    $ventasDiaIntent = getVentasDiaIntent($u);
    if ($ventasDiaIntent) {
        $cant = null;
        $total = null;
        if (preg_match('/Cantidad de facturas:\s*([0-9\.\,]+)/iu', $ctx, $m1)) $cant = $m1[1];
        if (preg_match('/Total vendido:\s*Gs\.\s*([0-9\.\,]+)/iu', $ctx, $m2)) $total = $m2[1];
        if ($cant !== null || $total !== null) {
            $label = (string)($ventasDiaIntent['label'] ?? 'del dia');
            $parts = [];
            if ($cant !== null) $parts[] = "Facturas $label: $cant";
            if ($total !== null) $parts[] = "Total vendido: Gs. $total";
            return implode(" | ", $parts);
        }
    }

    return $ctx;
}

function shouldForceContextFallback($aiResponse)
{
    $r = mb_strtolower(trim((string)$aiResponse), 'UTF-8');
    if ($r === '') return true;
    if (preg_match('/\[datos del sistema\]/iu', $r)) return true;
    if (preg_match('/(necesitar[ií]a consultar|podr[ií]as esperar|consultando la base|un momento mientras lo consulto)/iu', $r)) return true;
    return false;
}

function askOpenAI(array $messages, $apiKey, $model = 'gpt-4.1', $maxTokens = 420, $temperature = 0.35)
{
    if (trim((string)$apiKey) === '') {
        throw new Exception('API Key no configurada. Contacte al administrador.');
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => (int)$maxTokens,
            'temperature' => (float)$temperature
        ]),
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception('Error de conexión con OpenAI: ' . $error);

    $data = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? ('Error OpenAI HTTP ' . $httpCode);
        throw new Exception($errorMsg);
    }

    return (string)($data['choices'][0]['message']['content'] ?? '');
}

function askOllama(array $messages, $ollamaUrl, $model, $timeoutSec = 45)
{
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'stream' => false,
        'keep_alive' => '30m',
        'options' => [
            'temperature' => 0.7,
            'num_ctx' => 2048
        ]
    ];

    $ch = curl_init($ollamaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => (int)$timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 4
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception('Error de conexión con Ollama: ' . $error);
    if ($httpCode !== 200) throw new Exception('Ollama HTTP ' . $httpCode);

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new Exception('Respuesta inválida de Ollama');
    }

    $content = (string)($data['message']['content'] ?? '');
    if (trim($content) === '') {
        throw new Exception('Ollama devolvió respuesta vacía');
    }
    return $content;
}

function processarConsultaDB(PDO $pdoEmpresa, ?PDO $pdoMaster, $dbName, $dbQ, array $authorizedDbs, $mensaje)
{
    $mensajeRaw = trim((string)$mensaje);
    $mensaje = strtolower($mensajeRaw);
    $contexto = "";
    $clientesIdCol = detectClientesPkColumn($pdoEmpresa, $dbName);
    $clientesRucCol = detectClientesRucColumn($pdoEmpresa, $dbName);

    if (preg_match('/\b(ayuda|guia|tutorial|soy nuevo|principiante|como empiezo|como usar)\b/iu', $mensajeRaw)) {
        return "\n\n" . getBeginnerHelpText();
    }

    // Ventas por dia relativo (hoy/ayer/anteayer) via DB local
    $ventasDiaIntent = getVentasDiaIntent($mensajeRaw);
    if ($ventasDiaIntent) {
        $ventas = getVentasFecha($pdoEmpresa, $dbQ, $ventasDiaIntent['fecha']);
        $label = (string)($ventasDiaIntent['label'] ?? 'del dia');
        $fecha = (string)($ventasDiaIntent['fecha'] ?? '');
        $contexto = "\n\n[DATOS DEL SISTEMA]\nVentas de $label" . ($fecha !== '' ? " ($fecha)" : '') . ":\n";
        $contexto .= "- Cantidad de facturas: {$ventas['cantidad']}\n";
        $contexto .= "- Total vendido: Gs. " . formatGs($ventas['total'] ?? 0) . "\n";
        return $contexto;
    }

    // Ventas del mes pasado
    if (isVentasMesPasadoIntent($mensajeRaw)) {
        $base = new DateTime('today');
        $inicioMesPasado = (clone $base)->modify('first day of last month')->format('Y-m-d');
        $finMesPasado = (clone $base)->modify('last day of last month')->format('Y-m-d');
        $v = getVentasRango($pdoEmpresa, $dbQ, $inicioMesPasado, $finMesPasado);
        $contexto = "\n\n[DATOS DEL SISTEMA]\nVentas mes pasado ($inicioMesPasado a $finMesPasado):\n";
        $contexto .= "- Facturas: " . (int)($v['cantidad'] ?? 0) . "\n";
        $contexto .= "- Total: Gs. " . formatGs($v['total'] ?? 0) . "\n";
        return $contexto;
    }

    // Ventas de los últimos 6 meses (mes por mes)
    if (preg_match('/\b(ventas?|facturado|total vendido)\b/iu', $mensajeRaw) &&
        preg_match('/\b([uú]ltimos?|ultimos?)\b.*\b6\b.*\bmes(es)?\b/iu', $mensajeRaw)) {
        $rows = getVentasUltimosMeses($pdoEmpresa, $dbQ, 6);
        $acumCant = 0;
        $acumTotal = 0.0;
        $contexto = "\n\n[DATOS DEL SISTEMA]\nVentas últimos 6 meses:\n";
        foreach ($rows as $r) {
            $acumCant += (int)$r['cantidad'];
            $acumTotal += (float)$r['total'];
            $contexto .= "- {$r['ym']}: Facturas {$r['cantidad']} | Total Gs. " . formatGs($r['total']) . "\n";
        }
        $contexto .= "Acumulado 6 meses: Facturas {$acumCant} | Total Gs. " . formatGs($acumTotal) . "\n";
        return $contexto;
    }

    // Ventas por periodo: semana / mes / año (actuales)
    if (preg_match('/\b(ventas?|vendimos|facturado|total vendido)\b/iu', $mensajeRaw) &&
        preg_match('/\b(semana|semanal|mes|mensual|a[nñ]o|anho|anual)\b/iu', $mensajeRaw) &&
        !preg_match('/\b(comparativo|comparar|vs)\b/iu', $mensajeRaw)) {

        $hoy = new DateTime('today');
        $inicioSemana = (clone $hoy)->modify('monday this week')->format('Y-m-d');
        $finSemana = (clone $hoy)->modify('sunday this week')->format('Y-m-d');
        $inicioMes = (clone $hoy)->modify('first day of this month')->format('Y-m-d');
        $finMes = (clone $hoy)->modify('last day of this month')->format('Y-m-d');
        $inicioAnho = (clone $hoy)->modify('first day of january this year')->format('Y-m-d');
        $finAnho = (clone $hoy)->modify('last day of december this year')->format('Y-m-d');

        $pideSemana = (bool)preg_match('/\b(semana|semanal)\b/iu', $mensajeRaw);
        $pideMes = (bool)preg_match('/\b(mes|mensual)\b/iu', $mensajeRaw);
        $pideAnho = (bool)preg_match('/\b(a[nñ]o|anho|anual)\b/iu', $mensajeRaw);

        $contexto = "\n\n[DATOS DEL SISTEMA]\n";
        if ($pideSemana) {
            $v = getVentasRango($pdoEmpresa, $dbQ, $inicioSemana, $finSemana);
            $contexto .= "Ventas semana actual ($inicioSemana a $finSemana):\n";
            $contexto .= "- Facturas: " . (int)($v['cantidad'] ?? 0) . "\n";
            $contexto .= "- Total: Gs. " . formatGs($v['total'] ?? 0) . "\n";
        }
        if ($pideMes) {
            $v = getVentasRango($pdoEmpresa, $dbQ, $inicioMes, $finMes);
            $contexto .= "Ventas mes actual ($inicioMes a $finMes):\n";
            $contexto .= "- Facturas: " . (int)($v['cantidad'] ?? 0) . "\n";
            $contexto .= "- Total: Gs. " . formatGs($v['total'] ?? 0) . "\n";
        }
        if ($pideAnho) {
            $v = getVentasRango($pdoEmpresa, $dbQ, $inicioAnho, $finAnho);
            $contexto .= "Ventas año actual ($inicioAnho a $finAnho):\n";
            $contexto .= "- Facturas: " . (int)($v['cantidad'] ?? 0) . "\n";
            $contexto .= "- Total: Gs. " . formatGs($v['total'] ?? 0) . "\n";
        }
        return $contexto;
    }

    // Top clientes por compras
    if (preg_match('/\b(top|mejores?|mas|m[aá]s)\b.*\b(clientes?)\b.*\b(compran|compras|compra)\b/iu', $mensajeRaw) ||
        preg_match('/\b(clientes?)\b.*\b(mejores?|mas|m[aá]s)\b.*\b(compran|compras|compra)\b/iu', $mensajeRaw)) {
        $top = getTopClientesCompras($pdoEmpresa, $dbQ, $clientesIdCol, 10, null, null, $clientesRucCol);
        $contexto = "\n\n[DATOS DEL SISTEMA]\nTop clientes por compras (total vendido):\n";
        if (empty($top)) {
            $contexto .= "- Sin datos.\n";
        } else {
            foreach ($top as $i => $c) {
                $contexto .= ($i + 1) . ". {$c['nombre']} (RUC: {$c['ruc']}) - Gs. " . formatGs($c['total_comprado'] ?? 0) .
                    " | Facturas: " . (int)($c['cantidad_facturas'] ?? 0) .
                    " | Última: " . (string)($c['ultima_compra'] ?? '-') . "\n";
            }
        }
        return $contexto;
    }

    // Lista de morosos
    if (preg_match('/\b(morosos?|moroso|deudores?)\b/iu', $mensajeRaw) ||
        preg_match('/\b(lista)\b.*\b(cl(i|e)?entes?)\b.*\b(morosos?)\b/iu', $mensajeRaw)) {
        $morosos = getClientesMorosos($pdoEmpresa, $dbQ, $clientesIdCol, 20, $clientesRucCol);
        $contexto = "\n\n[DATOS DEL SISTEMA]\nLista de clientes morosos:\n";
        if (empty($morosos)) {
            $contexto .= "- No hay clientes morosos.\n";
        } else {
            foreach ($morosos as $i => $m) {
                $contexto .= ($i + 1) . ". {$m['nombre']} (RUC: {$m['ruc']}) - Deuda: Gs. " . formatGs($m['deuda_total'] ?? 0) .
                    " | Pendientes: " . (int)($m['facturas_pendientes'] ?? 0) .
                    " | Última factura: " . (string)($m['ultima_factura'] ?? '-') . "\n";
            }
        }
        return $contexto;
    }

    // Top productos por ventas
    if (preg_match('/\b(top|mejores?|mas|m[aá]s)\b.*\b(productos?)\b.*\b(ventas?|vendidos?)\b/iu', $mensajeRaw) ||
        preg_match('/\b(productos?)\b.*\b(top|mejores?|mas|m[aá]s)\b.*\b(ventas?|vendidos?)\b/iu', $mensajeRaw)) {
        $topP = getTopProductosVentas($pdoEmpresa, $dbQ, 10);
        $contexto = "\n\n[DATOS DEL SISTEMA]\nTop productos por ventas:\n";
        if (empty($topP)) {
            $contexto .= "- Sin datos.\n";
        } else {
            foreach ($topP as $i => $p) {
                $contexto .= ($i + 1) . ". {$p['producto']} - Cantidad: " . formatGs($p['cantidad_total'] ?? 0) .
                    " | Total: Gs. " . formatGs($p['total_vendido'] ?? 0) . "\n";
            }
        }
        return $contexto;
    }

    // Clientes sin compra en 30 días
    if (preg_match('/\b(clientes?)\b.*\b(sin compra|inactivos?|no compran)\b/iu', $mensajeRaw) ||
        preg_match('/\b(sin compra|inactivos?)\b.*\b(30|treinta)\b.*\b(d[ií]as)\b/iu', $mensajeRaw)) {
        $sin = getClientesSinCompra($pdoEmpresa, $dbQ, $clientesIdCol, 30, 50, $clientesRucCol);
        $contexto = "\n\n[DATOS DEL SISTEMA]\nClientes sin compra en 30 días:\n";
        if (empty($sin)) {
            $contexto .= "- No hay clientes en esta condición.\n";
        } else {
            foreach ($sin as $i => $c) {
                $ult = $c['ultima_compra'] ? (string)$c['ultima_compra'] : 'Sin compras';
                $contexto .= ($i + 1) . ". {$c['nombre']} (RUC: {$c['ruc']}) - Última compra: {$ult}\n";
            }
        }
        return $contexto;
    }

    // Comparativo semana actual vs anterior
    if (preg_match('/\b(comparativo|comparar|vs)\b.*\b(semana)\b/iu', $mensajeRaw) ||
        preg_match('/\b(semana actual)\b.*\b(semana anterior)\b/iu', $mensajeRaw)) {
        $hoy = new DateTime('today');
        $iniActual = (clone $hoy)->modify('monday this week')->format('Y-m-d');
        $finActual = (clone $hoy)->modify('sunday this week')->format('Y-m-d');
        $iniAnt = (clone $hoy)->modify('monday last week')->format('Y-m-d');
        $finAnt = (clone $hoy)->modify('sunday last week')->format('Y-m-d');

        $act = getVentasRango($pdoEmpresa, $dbQ, $iniActual, $finActual);
        $ant = getVentasRango($pdoEmpresa, $dbQ, $iniAnt, $finAnt);

        $totalAct = (float)($act['total'] ?? 0);
        $totalAnt = (float)($ant['total'] ?? 0);
        $dif = $totalAct - $totalAnt;
        $pct = ($totalAnt > 0) ? (($dif / $totalAnt) * 100.0) : 0;

        $contexto = "\n\n[DATOS DEL SISTEMA]\nComparativo semanal:\n";
        $contexto .= "- Semana actual ($iniActual a $finActual): Facturas " . (int)($act['cantidad'] ?? 0) .
            " | Total Gs. " . formatGs($totalAct) . "\n";
        $contexto .= "- Semana anterior ($iniAnt a $finAnt): Facturas " . (int)($ant['cantidad'] ?? 0) .
            " | Total Gs. " . formatGs($totalAnt) . "\n";
        $contexto .= "- Diferencia: Gs. " . formatGs($dif) . " (" . number_format($pct, 2, ',', '.') . "%)\n";
        return $contexto;
    }

    // Comparativo año actual vs año anterior
    if (
        (preg_match('/\b(comparativo|comparar|vs)\b/iu', $mensajeRaw) &&
            preg_match('/\b(a[nñ]o|anho)\b/iu', $mensajeRaw) &&
            preg_match('/\b(anterior|pasado)\b/iu', $mensajeRaw)) ||
        preg_match('/\b(este\s+a[nñ]o|a[nñ]o\s+actual)\b.*\b(a[nñ]o\s+anterior|a[nñ]o\s+pasado)\b/iu', $mensajeRaw) ||
        preg_match('/\b(a[nñ]o\s+anterior|a[nñ]o\s+pasado)\b.*\b(este\s+a[nñ]o|a[nñ]o\s+actual)\b/iu', $mensajeRaw)
    ) {
        $hoy = new DateTime('today');
        $iniActual = (clone $hoy)->modify('first day of january this year')->format('Y-m-d');
        $finActual = (clone $hoy)->modify('last day of december this year')->format('Y-m-d');
        $iniAnterior = (clone $hoy)->modify('first day of january last year')->format('Y-m-d');
        $finAnterior = (clone $hoy)->modify('last day of december last year')->format('Y-m-d');

        $act = getVentasRango($pdoEmpresa, $dbQ, $iniActual, $finActual);
        $ant = getVentasRango($pdoEmpresa, $dbQ, $iniAnterior, $finAnterior);

        $totalAct = (float)($act['total'] ?? 0);
        $totalAnt = (float)($ant['total'] ?? 0);
        $dif = $totalAct - $totalAnt;
        $pct = ($totalAnt > 0) ? (($dif / $totalAnt) * 100.0) : 0;

        $contexto = "\n\n[DATOS DEL SISTEMA]\nComparativo anual:\n";
        $contexto .= "- Año actual ($iniActual a $finActual): Facturas " . (int)($act['cantidad'] ?? 0) .
            " | Total Gs. " . formatGs($totalAct) . "\n";
        $contexto .= "- Año anterior ($iniAnterior a $finAnterior): Facturas " . (int)($ant['cantidad'] ?? 0) .
            " | Total Gs. " . formatGs($totalAnt) . "\n";
        $contexto .= "- Diferencia: Gs. " . formatGs($dif) . " (" . number_format($pct, 2, ',', '.') . "%)\n";
        return $contexto;
    }

    if (preg_match('/\b(listar|mostrar|ver)\b.*\b(bases|bases de datos|dbs?)\b/i', $mensajeRaw)) {
        $contexto = "\n\n[DATOS DEL SISTEMA]\nBases autorizadas para Vcorta:\n";
        foreach ($authorizedDbs as $d) $contexto .= "- $d\n";
        return $contexto;
    }

    if (preg_match('/\b(listar|mostrar|ver)\b.*\b(tablas|tabla)\b(?:\s+(de|del)\s+([a-zA-Z0-9_]+))?/i', $mensajeRaw, $mTabs)) {
        $targetDb = $dbName;
        if (!empty($mTabs[4])) {
            $targetDb = assertIdentifier($mTabs[4], 'base');
            if (!isAuthorizedDbName($targetDb, $authorizedDbs)) {
                return "\n\n[DATOS DEL SISTEMA]\nBase '$targetDb' no autorizada para Vcorta.";
            }
        }
        $pdoForDb = pickPdoForDb($targetDb, $dbName, $pdoEmpresa, $pdoMaster);
        $tables = listTablesInDb($pdoForDb, $targetDb, 200);
        $contexto = "\n\n[DATOS DEL SISTEMA]\nBase activa: $targetDb\nTablas disponibles (" . count($tables) . "):\n";
        foreach ($tables as $t) $contexto .= "- $t\n";
        return $contexto;
    }

    if (preg_match('/\b(columnas|campos|estructura|describe|descripcion)\b.*\b(de|del)\b\s+([a-zA-Z0-9_]+)/i', $mensajeRaw, $mCols)) {
        $parsed = parseDbAndTableToken($mCols[3], $dbName, $authorizedDbs);
        $targetDb = $parsed['db'];
        $tableName = $parsed['table'];
        $pdoForDb = pickPdoForDb($targetDb, $dbName, $pdoEmpresa, $pdoMaster);
        $cols = describeTableInDb($pdoForDb, $targetDb, $tableName, 250);
        if (empty($cols)) return "\n\n[DATOS DEL SISTEMA]\nNo se encontró la tabla '$tableName' en '$targetDb'.";
        $contexto = "\n\n[DATOS DEL SISTEMA]\nEstructura de `$targetDb.$tableName`:\n";
        foreach ($cols as $c) {
            $nullable = strtoupper((string)$c['is_nullable']) === 'YES' ? 'NULL' : 'NOT NULL';
            $key = $c['column_key'] ? " KEY:{$c['column_key']}" : '';
            $def = $c['column_default'] !== null ? " DEFAULT:" . $c['column_default'] : '';
            $contexto .= "- {$c['column_name']} ({$c['data_type']}) $nullable$key$def\n";
        }
        return $contexto;
    }

    if (preg_match('/\b(listar|mostrar|ver)\b.*\b(registros|filas|datos)\b.*\b(de|del)\b\s+([a-zA-Z0-9_]+)/i', $mensajeRaw, $mRows)) {
        $parsed = parseDbAndTableToken($mRows[4], $dbName, $authorizedDbs);
        $targetDb = $parsed['db'];
        $tableName = $parsed['table'];
        $pdoForDb = pickPdoForDb($targetDb, $dbName, $pdoEmpresa, $pdoMaster);
        $targetDbQ = "`$targetDb`";
        $rowsRes = listRowsFromTable($pdoForDb, $targetDb, $targetDbQ, $tableName, 20);
        if (!$rowsRes['ok']) return "\n\n[DATOS DEL SISTEMA]\n" . $rowsRes['error'];

        $rows = $rowsRes['rows'];
        $contexto = "\n\n[DATOS DEL SISTEMA]\nPrimeros registros de `$targetDb.$tableName` (máx 20):\n";
        if (empty($rows)) {
            $contexto .= "- Sin registros.\n";
        } else {
            foreach ($rows as $i => $r) $contexto .= ($i + 1) . ". " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
        return $contexto;
    }

    if (preg_match('/^\s*(sql|consulta|query)(?:@([a-zA-Z0-9_]+))?\s*:\s*(.+)$/is', $mensajeRaw, $mSql)) {
        $dbHint = '';
        if (!empty($mSql[2])) {
            $dbHint = assertIdentifier($mSql[2], 'base');
            if (!isAuthorizedDbName($dbHint, $authorizedDbs)) {
                return "\n\n[DATOS DEL SISTEMA]\nBase '$dbHint' no autorizada para SQL.";
            }
        }
        $san = sanitizeReadOnlySql($mSql[3]);
        if (!$san['ok']) return "\n\n[DATOS DEL SISTEMA]\n" . $san['error'];
        $sqlRaw = $san['sql'];
        $pdoSql = $dbHint !== '' ? pickPdoForDb($dbHint, $dbName, $pdoEmpresa, $pdoMaster) : $pdoEmpresa;
        $run = runReadOnlySql($pdoSql, $sqlRaw);
        if (!$run['ok']) return "\n\n[DATOS DEL SISTEMA]\nError SQL: " . $run['error'];

        $contexto = "\n\n[DATOS DEL SISTEMA]\nResultado SQL (solo lectura): {$sqlRaw}\n";
        if (empty($run['rows'])) {
            $contexto .= "- Sin filas.\n";
        } else {
            foreach ($run['rows'] as $i => $r) $contexto .= ($i + 1) . ". " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
        return $contexto;
    }

    if (preg_match('/(saldo|deuda|debe|cuenta).*(de|del|cliente)?\s+([a-záéíóúñ\s]+)/i', $mensaje, $matches)) {
        $nombreCliente = trim($matches[3]);
        $clientes = buscarCliente($pdoEmpresa, $dbQ, $nombreCliente, $clientesIdCol, $clientesRucCol);
        if (!empty($clientes)) {
            $contexto = "\n\n[DATOS DEL SISTEMA]\n";
            foreach ($clientes as $c) {
                $saldo = formatGs($c['saldo_pendiente']);
                $contexto .= "- Cliente: {$c['nombre']} (RUC: {$c['ruc']})\n";
                $contexto .= "  Saldo pendiente: Gs. $saldo\n";
                if ((float)$c['saldo_pendiente'] > 0) {
                    $facturas = getFacturasPendientesCliente($pdoEmpresa, $dbQ, $c['id_cliente']);
                    if (!empty($facturas)) {
                        $contexto .= "  Facturas pendientes:\n";
                        foreach ($facturas as $f) {
                            $contexto .= "    • {$f['nro_factura']} - Fecha: {$f['fecha']} - Saldo: Gs. " . formatGs($f['saldo']) . "\n";
                        }
                    }
                }
            }
        } else {
            $contexto = "\n\n[DATOS DEL SISTEMA]\nNo se encontró ningún cliente con el nombre '$nombreCliente'.";
        }
    } elseif (preg_match('/(precio|stock|producto).*(de|del)?\s+([a-záéíóúñ0-9\s]+)/i', $mensaje, $matches)) {
        $nombreProducto = trim($matches[3]);
        $productos = getProducto($pdoEmpresa, $dbQ, $nombreProducto);
        if (!empty($productos)) {
            $contexto = "\n\n[DATOS DEL SISTEMA]\n";
            foreach ($productos as $p) {
                $precio = formatGs($p['precio'] ?? 0);
                $contexto .= "- Producto: {$p['descripcion']} (Código: {$p['codigo']})\n";
                $contexto .= "  Stock: {$p['stock']} | Precio: Gs. $precio\n";
            }
        } else {
            $contexto = "\n\n[DATOS DEL SISTEMA]\nNo se encontró ningún producto con el nombre '$nombreProducto'.";
        }
    } elseif (($ventasDiaIntent = getVentasDiaIntent($mensaje))) {
        $ventas = getVentasFecha($pdoEmpresa, $dbQ, $ventasDiaIntent['fecha']);
        $label = (string)($ventasDiaIntent['label'] ?? 'del dia');
        $fecha = (string)($ventasDiaIntent['fecha'] ?? '');
        $contexto = "\n\n[DATOS DEL SISTEMA]\nVentas de $label" . ($fecha !== '' ? " ($fecha)" : '') . ":\n";
        $contexto .= "- Cantidad de facturas: {$ventas['cantidad']}\n";
        $contexto .= "- Total vendido: Gs. " . formatGs($ventas['total'] ?? 0) . "\n";
    } elseif (preg_match('/(deudores|morosos|quien(es)? debe|clientes que deben)/i', $mensaje)) {
        $deudores = getTopClientesDeudores($pdoEmpresa, $dbQ, $clientesIdCol, 5, $clientesRucCol);
        if (!empty($deudores)) {
            $contexto = "\n\n[DATOS DEL SISTEMA]\nTop clientes con mayor deuda:\n";
            foreach ($deudores as $i => $d) $contexto .= ($i + 1) . ". {$d['nombre']} - Gs. " . formatGs($d['deuda']) . "\n";
        }
    }

    return $contexto;
}

function classifyVcortaQuery($userMessage, $dbContext)
{
    $msg = trim((string)$userMessage);
    $db = trim((string)$dbContext);
    $len = mb_strlen($msg, 'UTF-8');
    $words = preg_split('/\s+/u', $msg, -1, PREG_SPLIT_NO_EMPTY);
    $wordCount = is_array($words) ? count($words) : 0;
    $isShort = $len <= 90 || $wordCount <= 12;
    $isDbHeavy = $db !== '' || preg_match('/\b(sql|tabla|tablas|columnas|campos|ventas|saldo|deuda|stock|factura)\b/iu', $msg);
    if ($isDbHeavy) return 'db_heavy';
    if ($isShort) return 'short';
    return 'standard';
}

$systemPrompt = <<<PROMPT
Sos vcorta, la inteligencia artificial interna de SISTEMAX. Tu nombre es vcorta.

IMPORTANTE: Tenés acceso directo a la base de datos del sistema. Cuando el usuario pregunte por datos específicos (saldos, facturas, productos, ventas), los datos reales aparecerán marcados como [DATOS DEL SISTEMA] al final del mensaje. Usá esos datos para responder de forma precisa.

Contexto del sistema:
- Nombre: Sistemax
- Tipo: ERP modular (ventas, compras, clientes, proveedores, productos, stock, precios, POS, caja)
- País: Paraguay | Moneda: Guaraní (Gs.)
- Normativa: facturación paraguaya (SIFEN)

NAVEGACIÓN POR VOZ - APPS DISPONIBLES:
Cuando el usuario pida abrir una aplicación (ej: "abrir POS", "llevame a facturas", "ir a clientes"), respondé EXACTAMENTE con:
- "Abriendo pos..." para punto de venta
- "Abriendo caja..." para control de caja
- "Abriendo facturas..." para facturación SIFEN
- "Abriendo clientes..." para lista de clientes
- "Abriendo productos..." para mercaderías
- "Abriendo compras..." para módulo de compras
- "Abriendo nc..." para notas de crédito
- "Abriendo nd..." para notas de débito

VIDEO TUTORIAL POS:
- Si el usuario pide mostrar/ver/reproducir video o tutorial de POS, no digas que no podés.
- En ese caso respondé con la ruta del video: /video_out/pos/video.web.mp4

Tu rol:
- Consultar y reportar datos reales del sistema
- Navegar a las apps cuando el usuario lo pida
- Ayudar con consultas de saldos, facturas, productos, stock
- Responder preguntas sobre operaciones del día
- Guiar a usuarios principiantes con pasos claros y cortos

Reglas:
- Tu nombre es vcorta, presentate así si te preguntan
- Respondé siempre en español
- Si hay datos del sistema, usalos para responder
- Podés consultar bases autorizadas (empresa activa y serproc1) para listar tablas, columnas y datos
- Comandos útiles: "listar bases", "tablas de serproc1", "columnas de " . MASTER_DB . ".empresa", "registros de " . MASTER_DB . ".empresa", "sql@serproc1: SELECT ..."
- Para SQL directo, el usuario puede escribir: `sql: SELECT ...` (solo lectura)
- Si el usuario es principiante, respondé en formato paso a paso (maximo 6 pasos)
- Evitá jerga tecnica innecesaria; prioriza acciones concretas de pantalla
- Formateá montos en guaraníes con separador de miles
- Sé conciso y directo
- Si no encontrás datos, decilo claramente
PROMPT;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $userMessage = trim($input['message'] ?? '');
    $history = $input['history'] ?? [];

    if ($userMessage === '') throw new Exception('Mensaje vacío');

    // Atajo determinístico: pedir video tutorial de POS desde Vcorta.
    $msgLower = mb_strtolower($userMessage, 'UTF-8');
    $hasVideoWord = (bool)preg_match('/\b(video|videotutorial|tutorial)\b/iu', $msgLower);
    $hasShowVerb = (bool)preg_match('/\b(muestre|mueste|mu[eé]strame|mu[eé]stre|mostrar|mostra|muestrame|mostrame|dame|ver|reproducir|play|abrir)\b/iu', $msgLower);
    $hasPosHint = (bool)preg_match('/\b(pos|punto de venta|punto venta)\b/iu', $msgLower);
    $hasOtherModuleHint = (bool)preg_match('/\b(caja|cajas|compras|productos|clientes|ventas|mis ventas|usuarios|empresa|panel)\b/iu', $msgLower);
    $asksHowToUsePos = (bool)preg_match('/\b(como|cómo)\b.*\b(usar|uso|se usa|funciona|manejar)\b.*\b(pos|punto de venta)\b/iu', $msgLower)
        || (bool)preg_match('/\b(pos|punto de venta)\b.*\b(como|cómo)\b.*\b(usar|uso|se usa|funciona|manejar)\b/iu', $msgLower)
        || (bool)preg_match('/\bcomofuncion(a)?\b.*\b(pos|punto de venta)\b/iu', $msgLower)
        || (bool)preg_match('/\b(pos|punto de venta)\b.*\bcomofuncion(a)?\b/iu', $msgLower);

    // Regla:
    // - Si pide video + POS => devolver video POS.
    // - Si pide mostrar/reproducir video sin módulo específico => usar POS por defecto.
    $wantsPosVideo = ($hasVideoWord && $hasPosHint)
        || ($hasVideoWord && $hasShowVerb && !$hasOtherModuleHint)
        || $asksHowToUsePos;

    if ($wantsPosVideo) {
        $videoWebFile = realpath(__DIR__ . '/../../../video_out/pos/video.web.mp4');
        if ($videoWebFile && is_file($videoWebFile)) {
            echo json_encode([
                'success' => true,
                'response' => "Aquí tenés el videotutorial de POS:\n/video_out/pos/video.web.mp4"
            ]);
            exit;
        }
        $videoFile = realpath(__DIR__ . '/../../../video_out/pos/video.mp4');
        if ($videoFile && is_file($videoFile)) {
            echo json_encode([
                'success' => true,
                'response' => "Aquí tenés el videotutorial de POS:\n/video_out/pos/video.mp4"
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'response' => 'Todavía no está generado el videotutorial de POS. Solicitá: "crear video tutorial de POS".'
        ]);
        exit;
    }

    $dbContext = processarConsultaDB($pdo, $pdoMaster, $dbName, $dbQ, $authorizedDbs, $userMessage);
    $dbContext = clampText($dbContext, $dbContextMaxChars);
    $mensajeConContexto = $userMessage . $dbContext;
    $queryType = classifyVcortaQuery($userMessage, $dbContext);

    // Fast path local: consultas rápidas/DB se responden sin IA externa.
    if (trim((string)$dbContext) !== '' && $queryType === 'db_heavy') {
        $localReply = buildDirectReplyFromContext($userMessage, $dbContext);
        if (trim((string)$localReply) !== '') {
            echo json_encode([
                'success' => true,
                'response' => $localReply,
                'fallback' => 'local_db_fast'
            ]);
            exit;
        }
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt]
    ];

    $recentHistory = array_slice(is_array($history) ? $history : [], -$historyWindow);
    foreach ($recentHistory as $msg) {
        if (!is_array($msg)) continue;
        $messages[] = [
            'role' => (($msg['role'] ?? '') === 'user') ? 'user' : 'assistant',
            'content' => (string)($msg['content'] ?? '')
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $mensajeConContexto];

    $aiResponse = '';
    $providerErrors = [];
    $openAiMaxTokensEff = $openAiMaxTokens;
    $openAiTempEff = $openAiTemperature;
    if ($queryType === 'short') {
        $openAiMaxTokensEff = min($openAiMaxTokensEff, 220);
        $openAiTempEff = min($openAiTempEff, 0.25);
    } elseif ($queryType === 'db_heavy') {
        $openAiMaxTokensEff = min(max($openAiMaxTokensEff, 320), 520);
        $openAiTempEff = min($openAiTempEff, 0.30);
    }

    $tryOpenAiFirst = ($routingMode === 'openai_first' || $routingMode === 'smart');
    $tryOllamaFirst = ($routingMode === 'ollama_first');

    if ($tryOpenAiFirst && !$localOnly) {
        try {
            $aiResponse = askOpenAI($messages, $apiKey, $openAiModel, $openAiMaxTokensEff, $openAiTempEff);
        } catch (Throwable $openAiErr) {
            $providerErrors[] = 'OpenAI(' . $openAiModel . '): ' . $openAiErr->getMessage();
        }
    }

    if (($tryOllamaFirst || trim($aiResponse) === '') && $useOllama) {
        try {
            $aiResponse = askOllama($messages, $ollamaUrl, $ollamaModel, $ollamaTimeout);
        } catch (Throwable $e) {
            $providerErrors[] = 'Ollama(' . $ollamaModel . '): ' . $e->getMessage();
            if ($ollamaFallbackModel !== '' && $ollamaFallbackModel !== $ollamaModel) {
                try {
                    $aiResponse = askOllama($messages, $ollamaUrl, $ollamaFallbackModel, $ollamaTimeout);
                } catch (Throwable $e2) {
                    $providerErrors[] = 'Ollama(' . $ollamaFallbackModel . '): ' . $e2->getMessage();
                }
            }
        }
    }

    if (!$tryOpenAiFirst && trim($aiResponse) === '' && !$localOnly) {
        try {
            $aiResponse = askOpenAI($messages, $apiKey, $openAiModel, $openAiMaxTokensEff, $openAiTempEff);
        } catch (Throwable $openAiErr) {
            $providerErrors[] = 'OpenAI(' . $openAiModel . '): ' . $openAiErr->getMessage();
        }
    }

    if (trim($aiResponse) === '' && !$localOnly && $enableGeminiFallback && $useGemini && $geminiLoaded && function_exists('askGemini') && function_exists('getGeminiContent')) {
        try {
            $geminiPrompt = "System: $systemPrompt\n\n";
            foreach ($recentHistory as $msg) {
                $role = (($msg['role'] ?? '') === 'user') ? 'User' : 'Assistant';
                $geminiPrompt .= $role . ': ' . (string)($msg['content'] ?? '') . "\n";
            }
            $geminiPrompt .= "User: $mensajeConContexto\nAssistant: ";

            $geminiResult = askGemini($geminiPrompt);
            $aiResponse = (string)getGeminiContent($geminiResult);
        } catch (Throwable $e) {
            $providerErrors[] = 'Gemini: ' . $e->getMessage();
        }
    }

    if (trim($aiResponse) === '' && !$localOnly && $enableOpenAiRetry) {
        // Reintento final con OpenAI si estaba sin clave al primer intento o hubo fallo transitorio.
        try {
            $aiResponse = askOpenAI($messages, $apiKey, $openAiModel, $openAiMaxTokens, $openAiTemperature);
        } catch (Throwable $openAiErr2) {
            $providerErrors[] = 'OpenAI(retry): ' . $openAiErr2->getMessage();
        }
    }

    if (trim((string)$aiResponse) === '') {
        // Fallback local determinístico: si ya tenemos contexto real de DB, responder sin IA externa.
        $forcedFromContext = buildDirectReplyFromContext($userMessage, $dbContext);
        if (trim((string)$forcedFromContext) !== '') {
            echo json_encode([
                'success' => true,
                'response' => $forcedFromContext,
                'fallback' => 'local_db'
            ]);
            exit;
        }

        if (!empty($providerErrors)) {
            throw new Exception('No pude conectarme a los servicios de IA en este momento. Intente nuevamente en unos segundos.');
        }
        throw new Exception('No se pudo obtener respuesta de ningún proveedor IA.');
    }

    // Si el modelo devolvió texto incompleto pese a tener contexto DB, forzar respuesta directa con datos.
    if (trim((string)$dbContext) !== '' && shouldForceContextFallback($aiResponse)) {
        $forced = buildDirectReplyFromContext($userMessage, $dbContext);
        if ($forced !== '') $aiResponse = $forced;
    }

    if (trim((string)$aiResponse) === '') throw new Exception('Respuesta vacía de la IA');

    echo json_encode([
        'success' => true,
        'response' => $aiResponse
    ]);
} catch (Throwable $e) {
    $err = (string)$e->getMessage();
    if (stripos($err, 'quota') !== false || stripos($err, '429') !== false) {
        $err = 'La IA está temporalmente saturada por límite de cuota. Intente nuevamente en unos segundos.';
    } elseif (
        stripos($err, 'API Key') !== false ||
        stripos($err, 'Operation timed out') !== false ||
        stripos($err, 'Ollama') !== false ||
        stripos($err, 'OpenAI') !== false
    ) {
        $err = 'No pude conectarme al asistente en este momento. Probá de nuevo en unos segundos.';
    }
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $err
    ]);
}
