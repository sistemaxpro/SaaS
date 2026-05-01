<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../pos/api/sifen_queue.php';

header('Content-Type: application/json; charset=utf-8');

if (!Session::isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
    exit;
}

try {
    $action = $_GET['action'] ?? 'summary';
    $idEmpresa = (int)($_GET['id_empresa'] ?? Session::getIdEmpresa());
    if ($idEmpresa <= 0) {
        throw new Exception('Empresa inválida');
    }

    $master = Database::getMasterConnection();
    $stmtDb = $master->prepare("SELECT dbase, empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
    $stmtDb->execute([':id' => $idEmpresa]);
    $empresa = $stmtDb->fetch(PDO::FETCH_ASSOC);
    if (!$empresa || empty($empresa['dbase'])) {
        throw new Exception('No se encontró base de datos de la empresa');
    }

    $dbName = (string)$empresa['dbase'];
    $pdoEmp = Database::getEmpresaConnection($idEmpresa);

    $q = trim((string)($_GET['q'] ?? ''));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }

    if ($action === 'process_queue') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('Método no permitido');
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $limit = (int)($input['limit'] ?? 8);
        if ($limit < 1) $limit = 1;
        if ($limit > 50) $limit = 50;

        $processed = [];
        for ($i = 0; $i < $limit; $i++) {
            $r = sifenQueueProcessOne($master, null);
            if (!(bool)($r['processed'] ?? false)) {
                break;
            }
            if ((int)($r['id_empresa'] ?? $idEmpresa) !== $idEmpresa && isset($r['id_empresa'])) {
                continue;
            }
            $processed[] = $r;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Cola procesada',
            'data' => [
                'processed' => count($processed),
                'items' => $processed
            ]
        ]);
        exit;
    }

    $whereFeBase = " WHERE tipo_documento = 3";
    $paramsKpi = [];
    if ($dateFrom !== '') {
        $whereFeBase .= " AND DATE(fecha) >= :date_from";
        $paramsKpi[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereFeBase .= " AND DATE(fecha) <= :date_to";
        $paramsKpi[':date_to'] = $dateTo;
    }

    $sqlKpi = "SELECT
        COUNT(*) AS total_fe,
        SUM(CASE WHEN COALESCE(estado_sifen, '') = 'Aprobado' THEN 1 ELSE 0 END) AS aprobadas,
        SUM(CASE WHEN COALESCE(estado_sifen, '') = 'Pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN COALESCE(estado_sifen, '') = 'Rechazado' THEN 1 ELSE 0 END) AS rechazadas,
        SUM(CASE WHEN COALESCE(estado_sifen, '') = 'Guardado Local' THEN 1 ELSE 0 END) AS guardado_local,
        SUM(CASE WHEN TRIM(COALESCE(cdc, '')) = '' THEN 1 ELSE 0 END) AS sin_cdc,
        SUM(CASE WHEN DATE(fecha) = CURDATE() THEN 1 ELSE 0 END) AS hoy
    FROM {$dbName}.factura_ventas
    {$whereFeBase}";
    $stmtKpi = $pdoEmp->prepare($sqlKpi);
    $stmtKpi->execute($paramsKpi);
    $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];

    $whereFeList = $whereFeBase;
    $paramsLast = $paramsKpi;
    if ($q !== '') {
        $whereFeList .= " AND (
            CAST(id_factura AS CHAR) LIKE :q
            OR COALESCE(nro_factura, '') LIKE :q
            OR COALESCE(estado_sifen, '') LIKE :q
            OR COALESCE(mensaje_sifen, '') LIKE :q
            OR COALESCE(cdc, '') LIKE :q
            OR COALESCE(prot_cons_lote_sifen, '') LIKE :q
        )";
        $paramsLast[':q'] = '%' . $q . '%';
    }

    $sqlLast = "SELECT
        id_factura, nro_factura, fecha, tipo_documento, forma_pago,
        estado_sifen, mensaje_sifen, cdc, prot_cons_lote_sifen, protocolo_autorizacion
    FROM {$dbName}.factura_ventas
    {$whereFeList}
    ORDER BY id_factura DESC
    LIMIT 80";
    $stmtLast = $pdoEmp->prepare($sqlLast);
    $stmtLast->execute($paramsLast);
    $last = $stmtLast->fetchAll(PDO::FETCH_ASSOC);

    sifenQueueEnsureTable($master);
    $whereQueue = " WHERE id_empresa = :id_empresa";
    $paramsQueue = [':id_empresa' => $idEmpresa];
    if ($dateFrom !== '') {
        $whereQueue .= " AND DATE(created_at) >= :q_date_from";
        $paramsQueue[':q_date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereQueue .= " AND DATE(created_at) <= :q_date_to";
        $paramsQueue[':q_date_to'] = $dateTo;
    }
    if ($q !== '') {
        $whereQueue .= " AND (
            CAST(id AS CHAR) LIKE :q_queue
            OR CAST(id_factura AS CHAR) LIKE :q_queue
            OR COALESCE(accion, '') LIKE :q_queue
            OR COALESCE(estado, '') LIKE :q_queue
            OR COALESCE(last_message, '') LIKE :q_queue
            OR COALESCE(last_error, '') LIKE :q_queue
        )";
        $paramsQueue[':q_queue'] = '%' . $q . '%';
    }

    $stmtQueue = $master->prepare("SELECT
        id, id_factura, accion, estado, intentos, max_intentos,
        next_retry_at, last_error, last_message, created_at, updated_at
    FROM " . MASTER_DB . ".fe_queue
    {$whereQueue}
    ORDER BY id DESC
    LIMIT 80");
    $stmtQueue->execute($paramsQueue);
    $queue = $stmtQueue->fetchAll(PDO::FETCH_ASSOC);

    $whereQueueSummary = " WHERE id_empresa = :id_empresa";
    $paramsQSum = [':id_empresa' => $idEmpresa];
    if ($dateFrom !== '') {
        $whereQueueSummary .= " AND DATE(created_at) >= :s_date_from";
        $paramsQSum[':s_date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereQueueSummary .= " AND DATE(created_at) <= :s_date_to";
        $paramsQSum[':s_date_to'] = $dateTo;
    }
    if ($q !== '') {
        $whereQueueSummary .= " AND (
            CAST(id AS CHAR) LIKE :s_q
            OR CAST(id_factura AS CHAR) LIKE :s_q
            OR COALESCE(accion, '') LIKE :s_q
            OR COALESCE(estado, '') LIKE :s_q
            OR COALESCE(last_message, '') LIKE :s_q
            OR COALESCE(last_error, '') LIKE :s_q
        )";
        $paramsQSum[':s_q'] = '%' . $q . '%';
    }

    $stmtQSum = $master->prepare("SELECT estado, COUNT(*) as cantidad
        FROM " . MASTER_DB . ".fe_queue
        {$whereQueueSummary}
        GROUP BY estado");
    $stmtQSum->execute($paramsQSum);
    $queueSummary = $stmtQSum->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'empresa' => [
                'id_empresa' => $idEmpresa,
                'nombre' => $empresa['empresa'] ?? '',
                'dbase' => $dbName
            ],
            'kpi' => $kpi,
            'ultimas_fe' => $last,
            'queue' => $queue,
            'queue_summary' => $queueSummary,
            'filters' => [
                'q' => $q,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
