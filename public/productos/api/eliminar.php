<?php
/**
 * Productos API - Eliminar/Restaurar (soft-delete)
 * POST: { action: "delete"|"restore"|"delete_permanent", id: 123 }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

if (!function_exists('eliminarProductoAplicacionesTableExists')) {
    function eliminarProductoAplicacionesTableExists(PDO $pdo, string $db, string $table): bool
    {
        static $cache = [];
        $key = $db . '.' . $table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = :db AND table_name = :tbl LIMIT 1");
        $stmt->execute([':db' => $db, ':tbl' => $table]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    }

    function eliminarProductoAplicacionesResolveTable(PDO $pdo, string $db): string
    {
        if (eliminarProductoAplicacionesTableExists($pdo, $db, 'productos_aplicaciones')) {
            return 'productos_aplicaciones';
        }
        if (eliminarProductoAplicacionesTableExists($pdo, $db, 'producto_aplicaciones')) {
            return 'producto_aplicaciones';
        }
        return 'producto_aplicaciones';
    }

    function eliminarProductoEquivalenciasResolveTable(PDO $pdo, string $db): string
    {
        if (eliminarProductoAplicacionesTableExists($pdo, $db, 'productos_equivalencias')) {
            return 'productos_equivalencias';
        }
        if (eliminarProductoAplicacionesTableExists($pdo, $db, 'producto_equivalencias')) {
            return 'producto_equivalencias';
        }
        return 'producto_equivalencias';
    }
}

Permission::requirePermission('app_grid_mercaderias', 'priv_delete');

$input = json_decode(file_get_contents('php://input'), true);
$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$action = $input['action'] ?? 'delete';
$idproducto = (int)($input['id'] ?? $input['idproducto'] ?? 0);

if ($idproducto <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID requerido']);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = $conn['masterPdo'] ?? Database::getMasterConnection();
    $masterDb = $conn['masterDb'] ?? Database::getMasterDbName();
    $tablaAplicaciones = eliminarProductoAplicacionesResolveTable($pdo, $db);
    $tablaEquivalencias = eliminarProductoEquivalenciasResolveTable($pdo, $db);

    $authLogin = trim((string)($input['auth_login'] ?? ''));
    $authPassword = (string)($input['auth_password'] ?? '');
    $criticalActions = ['delete', 'soft_delete', 'anular', 'deactivate'];
    if (in_array($action, $criticalActions, true)) {
        $sessionIsAdmin = Session::isAdmin();
        if (!$sessionIsAdmin) {
            if ($authLogin === '' || $authPassword === '') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Se requiere autorización de admin o supervisor']);
                exit;
            }

            $sqlAuth = "SELECT id_login, login, name, priv_admin, role, active
                FROM {$masterDb}.sec_users
                WHERE id_empresa = :id_empresa
                  AND COALESCE(active, 'Y') = 'Y'
                  AND (login = :login OR email = :login)
                  AND pswd = MD5(:password)
                LIMIT 1";
            $stmtAuth = $masterPdo->prepare($sqlAuth);
            $stmtAuth->execute([
                ':id_empresa' => $id_empresa,
                ':login' => $authLogin,
                ':password' => $authPassword,
            ]);
            $approver = $stmtAuth->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$approver) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Credenciales de autorización inválidas']);
                exit;
            }

            $privAdmin = strtoupper(trim((string)($approver['priv_admin'] ?? 'N')));
            $role = strtolower(trim((string)($approver['role'] ?? '')));
            $isSupervisor = preg_match('/\b(supervisor|supervisora|admin|administrador)\b/i', $role) === 1;
            if ($privAdmin !== 'Y' && !$isSupervisor) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'El usuario autorizado debe ser admin o supervisor']);
                exit;
            }
        }
    }

    $colsStmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'tblproductos'");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasDescontinuado = in_array('descontinuado', $cols, true);
    $hasFechaDescontinuado = in_array('fecha_descontinuado', $cols, true);
    $hasMotivoDescontinuado = in_array('motivo_descontinuado', $cols, true);
    $motivoDescontinuado = trim((string)($input['motivo'] ?? $input['motivo_descontinuado'] ?? ''));

    switch ($action) {
        case 'delete':
        case 'soft_delete':
            $set = ["Estado = 0"];
            if ($hasDescontinuado) $set[] = "descontinuado = 1";
            if ($hasFechaDescontinuado) $set[] = "fecha_descontinuado = NOW()";
            if ($hasMotivoDescontinuado) $set[] = "motivo_descontinuado = :motivo";
            $sql = "UPDATE {$db}.tblproductos SET " . implode(', ', $set) . " WHERE idproducto = :id";
            $stmt = $pdo->prepare($sql);
            if ($hasMotivoDescontinuado) $stmt->bindValue(':motivo', $motivoDescontinuado);
            $stmt->bindValue(':id', $idproducto, PDO::PARAM_INT);
            $stmt->execute();
            $msg = 'Producto descontinuado';
            break;

        case 'anular':
        case 'deactivate':
            $set = ["Estado = 0"];
            if ($hasDescontinuado) $set[] = "descontinuado = 0";
            if ($hasFechaDescontinuado) $set[] = "fecha_descontinuado = NULL";
            if ($hasMotivoDescontinuado) $set[] = "motivo_descontinuado = NULL";
            $sql = "UPDATE {$db}.tblproductos SET " . implode(', ', $set) . " WHERE idproducto = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $idproducto]);
            $msg = 'Producto anulado';
            break;

        case 'restore':
            $set = ["Estado = 1"];
            if ($hasDescontinuado) $set[] = "descontinuado = 0";
            if ($hasFechaDescontinuado) $set[] = "fecha_descontinuado = NULL";
            if ($hasMotivoDescontinuado) $set[] = "motivo_descontinuado = NULL";
            $sql = "UPDATE {$db}.tblproductos SET " . implode(', ', $set) . " WHERE idproducto = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $idproducto]);
            $msg = 'Producto reactivado';
            break;

        case 'delete_permanent':
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT cve_producto FROM {$db}.tblproductos WHERE idproducto = :id");
            $stmt->execute([':id' => $idproducto]);
            $prod = $stmt->fetch();

            if ($prod) {
                // Verificar si tiene movimientos
                $stmtMov = $pdo->prepare("SELECT COUNT(*) as c FROM {$db}.extracto_productos WHERE idproducto = :id");
                $stmtMov->execute([':id' => $idproducto]);
                if ($stmtMov->fetch()['c'] > 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'No se puede eliminar: tiene movimientos de stock. Use desactivar.']);
                    exit;
                }

                $pdo->prepare("DELETE FROM {$db}.codigo_barra WHERE id_producto = :id")->execute([':id' => $idproducto]);
                $pdo->prepare("DELETE FROM {$db}.mercaderia_precio WHERE codigo = :c")->execute([':c' => $prod['cve_producto']]);
                try {
                    $pdo->prepare("DELETE FROM {$db}.producto_series WHERE idproducto = :id")->execute([':id' => $idproducto]);
                } catch (Throwable $e) {}
                try {
                    $pdo->prepare("DELETE FROM {$db}.{$tablaAplicaciones} WHERE idproducto = :id")->execute([':id' => $idproducto]);
                } catch (Throwable $e) {}
                try {
                    $pdo->prepare("DELETE FROM {$db}.{$tablaEquivalencias} WHERE idproducto = :id")->execute([':id' => $idproducto]);
                } catch (Throwable $e) {}
                $pdo->prepare("DELETE FROM {$db}.mercaderia_series WHERE idproducto = :id")->execute([':id' => $idproducto]);
                $pdo->prepare("DELETE FROM {$db}.tblproductos WHERE idproducto = :id")->execute([':id' => $idproducto]);
            }
            $pdo->commit();
            $msg = 'Producto eliminado permanentemente';
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
            exit;
    }

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
