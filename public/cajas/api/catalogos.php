<?php
/**
 * Cajas API - Catálogos (sucursales, usuarios disponibles)
 * GET: ?tipo=sucursales|usuarios
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

// Verificar que el usuario esté logueado
if (!Session::isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['ok' => false, 'error' => 'No autenticado']));
}

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$tipo = $_GET['tipo'] ?? 'all';

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = Database::getMasterConnection();

    $result = [];

    if ($tipo === 'sucursales' || $tipo === 'all') {
        $stmt = $pdo->query("SELECT SUC as id_sucursal, NOMBRE as nombre FROM {$db}.sucursal ORDER BY SUC");
        $result['sucursales'] = $stmt->fetchAll();
    }

    if ($tipo === 'usuarios' || $tipo === 'all') {
        $stmt = $masterPdo->prepare("SELECT id_login, login, name FROM sec_users WHERE id_empresa = ? AND active = 'Y' ORDER BY name");
        $stmt->execute([$id_empresa]);
        $result['usuarios'] = $stmt->fetchAll();
    }

    echo json_encode(array_merge(['ok' => true], $result));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
