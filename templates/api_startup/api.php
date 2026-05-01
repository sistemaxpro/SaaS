<?php
require_once __DIR__ . '/config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? Session::getIdEmpresa() ?? 0);
    if ($idEmpresa <= 0) {
        throw new RuntimeException('id_empresa requerido');
    }

    $conn = getEmpresaConnection($idEmpresa);

    echo json_encode([
        'ok' => true,
        'message' => 'API template lista',
        'data' => [
            'id_empresa' => $idEmpresa,
            'dbase' => $conn['dbName'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

