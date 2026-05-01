<?php
/**
 * Productos API - Códigos de Barra CRUD
 * GET: ?action=list&id_producto=123
 * POST: { action: "add"|"remove"|"validate", id_producto: 123, codigo_barra: "..." }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_mercaderias');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? 0);

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';

        if ($action === 'list') {
            $id_producto = (int)($_GET['id_producto'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, codigo_barra FROM {$db}.codigo_barra WHERE id_producto = :id ORDER BY id");
            $stmt->execute([':id' => $id_producto]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);

        } elseif ($action === 'validate') {
            $codigo = trim($_GET['codigo'] ?? '');
            $exclude_product = (int)($_GET['exclude_product'] ?? 0);
            $sql = "SELECT cb.id, cb.codigo_barra, cb.id_producto, p.desproducto
                    FROM {$db}.codigo_barra cb
                    LEFT JOIN {$db}.tblproductos p ON p.idproducto = cb.id_producto
                    WHERE cb.codigo_barra = :cb";
            $params = [':cb' => $codigo];
            if ($exclude_product > 0) {
                $sql .= " AND cb.id_producto != :ep";
                $params[':ep'] = $exclude_product;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $existing = $stmt->fetch();
            echo json_encode([
                'success' => true,
                'available' => !$existing,
                'existing' => $existing ?: null
            ]);

        } elseif ($action === 'search') {
            // Buscar producto por código de barra
            $codigo = trim($_GET['codigo'] ?? '');
            $stmt = $pdo->prepare("SELECT cb.id_producto, p.idproducto, p.cve_producto, p.desproducto, p.precio_venta, p.saldo
                FROM {$db}.codigo_barra cb
                INNER JOIN {$db}.tblproductos p ON p.idproducto = cb.id_producto
                WHERE cb.codigo_barra = :cb AND p.Estado = 1");
            $stmt->execute([':cb' => $codigo]);
            $result = $stmt->fetch();
            echo json_encode(['success' => true, 'data' => $result ?: null]);
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        $id_producto = (int)($input['id_producto'] ?? 0);
        $codigo = trim($input['codigo_barra'] ?? '');

        if ($action === 'add') {
            if (empty($codigo) || $id_producto <= 0) {
                echo json_encode(['success' => false, 'error' => 'Producto y código requeridos']);
                exit;
            }
            // Validar unicidad
            $stmt = $pdo->prepare("SELECT id_producto FROM {$db}.codigo_barra WHERE codigo_barra = :cb");
            $stmt->execute([':cb' => $codigo]);
            $existing = $stmt->fetch();
            if ($existing) {
                echo json_encode(['success' => false, 'error' => "Código ya asignado al producto #{$existing['id_producto']}"]);
                exit;
            }
            $pdo->prepare("INSERT INTO {$db}.codigo_barra (id_producto, codigo_barra, id_login) VALUES (:id, :cb, :login)")
                ->execute([':id' => $id_producto, ':cb' => $codigo, ':login' => $id_login]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Código agregado']);

        } elseif ($action === 'remove') {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID requerido']);
                exit;
            }
            $pdo->prepare("DELETE FROM {$db}.codigo_barra WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Código eliminado']);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
