<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../balanzas/config/db_config.php';
require_once __DIR__ . '/../balanzas/api/balanza_service.php';

$codigo = trim((string)($_GET['codigo'] ?? ''));

if (!function_exists('smxResolveEmpresaIdFromSession')) {
    function smxResolveEmpresaIdFromSession(int $fallback = 169): int
    {
        $idLogin = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
        if ($idLogin > 0) {
            try {
                $master = Database::getMasterConnection();
                $stmt = $master->prepare("SELECT id_empresa FROM sec_users WHERE id_login = :id LIMIT 1");
                $stmt->execute([':id' => $idLogin]);
                $idEmp = (int)($stmt->fetchColumn() ?: 0);
                if ($idEmp > 0) return $idEmp;
            } catch (Exception $e) {
                // ignore
            }
        }
        $idSesion = (int)($_SESSION['id_empresa'] ?? 0);
        if ($idSesion > 0) return $idSesion;
        return $fallback;
    }
}

$idEmpresaParam = (int)($_GET['id_empresa'] ?? 0);
$idEmpresa = $idEmpresaParam > 0
    ? $idEmpresaParam
    : smxResolveEmpresaIdFromSession(169);

if ($codigo === '') {
    echo json_encode(['es_balanza' => false, 'error' => 'Código requerido']);
    exit;
}

try {
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    smxEnsureBalanzasTable($pdo, $db);
    $result = smxDecodeBalanza($pdo, $db, $idEmpresa, $codigo);
    if (is_array($result)) {
        $result['_ctx_id_empresa'] = $idEmpresa;
        $result['_ctx_db'] = $db;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['es_balanza' => false, 'error' => $e->getMessage()]);
}
