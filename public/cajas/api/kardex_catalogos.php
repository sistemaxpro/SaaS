<?php
/**
 * Cajas API - Catálogos para operaciones de Kardex
 * GET: Retorna clientes, bancos, cuentas y cajas para los selects
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_caja');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $tipo = $_GET['tipo'] ?? 'all';

    $result = [];

    // Clientes (contactos)
    if ($tipo === 'all' || $tipo === 'clientes') {
        $stmt = $pdo->query("SELECT id, nombre, numero AS ruc FROM {$db}.clientes ORDER BY nombre LIMIT 500");
        $result['clientes'] = $stmt->fetchAll();
    }

    // Bancos
    if ($tipo === 'all' || $tipo === 'bancos') {
        $stmt = $pdo->query("SELECT id_banco, banco, numero_cuenta FROM {$db}.bancos ORDER BY banco");
        $result['bancos'] = $stmt->fetchAll();
    }

    // Cuentas
    if ($tipo === 'all' || $tipo === 'cuentas') {
        $stmt = $pdo->query("SELECT id, cuenta FROM {$db}.cuentas ORDER BY cuenta");
        $result['cuentas'] = $stmt->fetchAll();
    }

    // Cajas (para transferencia caja a caja)
    if ($tipo === 'all' || $tipo === 'cajas') {
        $stmt = $pdo->query("SELECT id_caja, caja FROM {$db}.cajas ORDER BY caja");
        $result['cajas'] = $stmt->fetchAll();
    }

    echo json_encode(['ok' => true] + $result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
