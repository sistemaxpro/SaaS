<?php
/**
 * API Usuarios - Eliminar / Desactivar usuario
 * Tabla: serproc1.sec_users
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';

$id_empresa = $_SESSION['id_empresa'] ?? null;
if (!$id_empresa) { echo json_encode(['ok'=>false,'error'=>'Sin sesión']); exit; }

$pdo = getMasterPdo();

Permission::requirePermission('usuarios', 'priv_delete');

$input = json_decode(file_get_contents('php://input'), true);
$id_login = intval($input['id_login'] ?? 0);
$accion   = $input['accion'] ?? 'toggle'; // toggle | delete

if (!$id_login) { echo json_encode(['ok'=>false,'error'=>'ID requerido']); exit; }

try {
    // Verificar pertenencia
    $check = $pdo->prepare("SELECT id_login, login, active FROM " . MASTER_DB . ".sec_users WHERE id_login = :id AND id_empresa = :emp");
    $check->execute([':id'=>$id_login, ':emp'=>$id_empresa]);
    $user = $check->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['ok'=>false,'error'=>'Usuario no encontrado']); exit; }

    if ($accion === 'delete') {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM " . MASTER_DB . ".sec_users_groups WHERE login = :login AND id_grupo = :ig")
            ->execute([':login'=>$user['login'], ':ig'=>$id_empresa]);
        $pdo->prepare("DELETE FROM " . MASTER_DB . ".sec_users WHERE id_login = :id AND id_empresa = :emp")
            ->execute([':id'=>$id_login, ':emp'=>$id_empresa]);
        $pdo->commit();
        echo json_encode(['ok'=>true, 'msg'=>'Usuario eliminado']);
    } else {
        $newState = $user['active'] === 'Y' ? 'N' : 'Y';
        $pdo->prepare("UPDATE " . MASTER_DB . ".sec_users SET active = :st WHERE id_login = :id AND id_empresa = :emp")
            ->execute([':st'=>$newState, ':id'=>$id_login, ':emp'=>$id_empresa]);
        echo json_encode(['ok'=>true, 'msg'=>$newState === 'Y' ? 'Usuario activado' : 'Usuario desactivado', 'active'=>$newState]);
    }
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
