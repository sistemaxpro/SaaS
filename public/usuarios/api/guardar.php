<?php
/**
 * API Usuarios - Guardar (crear/editar) usuario
 * Tabla: serproc1.sec_users + sec_users_groups
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';

$id_empresa = $_SESSION['id_empresa'] ?? null;
if (!$id_empresa) { echo json_encode(['ok'=>false,'error'=>'Sin sesión']); exit; }

$pdo = getMasterPdo();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }

// Verificar permisos según acción
if (!empty($input['id_login'])) {
    Permission::requirePermission('usuarios', 'priv_update');
} else {
    Permission::requirePermission('usuarios', 'priv_insert');
}

/**
 * Normaliza banderas de checkbox a 'Y'/'N'.
 * Acepta: Y/1/true/on/si/activo => Y
 */
function normalizeYn($value, string $default = 'N'): string
{
    if ($value === null || $value === '') {
        return $default === 'Y' ? 'Y' : 'N';
    }
    if (is_bool($value)) {
        return $value ? 'Y' : 'N';
    }
    if (is_numeric($value)) {
        return ((int)$value) === 1 ? 'Y' : 'N';
    }
    $v = strtolower(trim((string)$value));
    $yes = ['y', 'yes', '1', 'true', 'on', 'si', 'sí', 's', 'a', 'activo'];
    return in_array($v, $yes, true) ? 'Y' : 'N';
}

function ensureTiposPrecioColumn(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='serproc1' AND table_name='sec_users' AND column_name='tipos_precio_asignados' LIMIT 1");
    $stmt->execute();
    if ($stmt->fetchColumn()) return true;
    try {
        $pdo->exec("ALTER TABLE " . MASTER_DB . ".sec_users ADD COLUMN tipos_precio_asignados VARCHAR(255) NULL AFTER caja_def");
    } catch (Throwable $e) {
        error_log('[usuarios/guardar] no se pudo crear tipos_precio_asignados: ' . $e->getMessage());
    }
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

$id_login   = $input['id_login'] ?? null;
$login      = trim($input['login'] ?? '');
$name       = trim($input['name'] ?? '');
$email      = trim($input['email'] ?? '');
$phone      = trim($input['phone'] ?? '');
$documento  = trim($input['documento'] ?? '');
$active     = normalizeYn($input['active'] ?? null, 'Y');
$priv_admin = normalizeYn($input['priv_admin'] ?? null, 'N');
$pswd       = trim($input['pswd'] ?? '');
$group_id   = intval($input['group_id'] ?? 1);
$genero     = intval($input['genero'] ?? 1);
$role       = trim($input['role'] ?? '');
$id_sucursal = max(0, intval($input['id_sucursal'] ?? 0));
$id_caja = max(0, intval($input['id_caja'] ?? 0));
$tiposPrecioRaw = $input['tipos_precio_asignados'] ?? [];
$tiposPrecio = [];
if (is_string($tiposPrecioRaw)) {
    $tiposPrecioRaw = array_filter(array_map('trim', explode(',', $tiposPrecioRaw)));
}
if (is_array($tiposPrecioRaw)) {
    foreach ($tiposPrecioRaw as $tp) {
        $idTp = (int)$tp;
        if ($idTp > 0 && !in_array($idTp, $tiposPrecio, true)) $tiposPrecio[] = $idTp;
    }
}
$tiposPrecioCsv = implode(',', $tiposPrecio);

if ($login === '' || $name === '') {
    echo json_encode(['ok'=>false,'error'=>'Login y nombre son obligatorios']);
    exit;
}

try {
    $hasTiposPrecioCol = ensureTiposPrecioColumn($pdo);
    $pdo->beginTransaction();

    if ($id_login) {
        // Editar - verificar que pertenece a la empresa
        $check = $pdo->prepare("SELECT id_login FROM " . MASTER_DB . ".sec_users WHERE id_login = :id AND id_empresa = :emp");
        $check->execute([':id'=>$id_login, ':emp'=>$id_empresa]);
        if (!$check->fetch()) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'Usuario no encontrado']);
            exit;
        }

        $setCols = "name=:name, email=:email, phone=:phone, documento=:documento, active=:active, 
                    priv_admin=:priv_admin, genero=:genero, role=:role, id_sucursal=:id_sucursal,
                    id_caja=:id_caja, caja_def=:caja_def";
        $params = [
            ':name'=>$name, ':email'=>$email, ':phone'=>$phone, ':documento'=>$documento,
            ':active'=>$active, ':priv_admin'=>$priv_admin, ':genero'=>$genero, ':role'=>$role,
            ':id_sucursal'=>$id_sucursal, ':id_caja'=>$id_caja, ':caja_def'=>$id_caja,
            ':id'=>$id_login, ':emp'=>$id_empresa
        ];
        if ($hasTiposPrecioCol) {
            $setCols .= ", tipos_precio_asignados=:tipos_precio_asignados";
            $params[':tipos_precio_asignados'] = $tiposPrecioCsv;
        }

        if ($pswd !== '') {
            $setCols .= ", pswd=:pswd";
            $params[':pswd'] = md5($pswd);
        }

        $sql = "UPDATE " . MASTER_DB . ".sec_users SET $setCols WHERE id_login=:id AND id_empresa=:emp";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Actualizar grupo
        $pdo->prepare("DELETE FROM " . MASTER_DB . ".sec_users_groups WHERE login=:login AND id_grupo=:ig")
            ->execute([':login'=>$login, ':ig'=>$id_empresa]);
        $pdo->prepare("INSERT INTO " . MASTER_DB . ".sec_users_groups (group_id, id_grupo, login) VALUES (:gid, :ig, :login)")
            ->execute([':gid'=>$group_id, ':ig'=>$id_empresa, ':login'=>$login]);

        $msg = 'Usuario actualizado';
    } else {
        // Crear - verificar login único en la empresa
        $check = $pdo->prepare("SELECT id_login FROM " . MASTER_DB . ".sec_users WHERE login = :login AND id_empresa = :emp");
        $check->execute([':login'=>$login, ':emp'=>$id_empresa]);
        if ($check->fetch()) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>'El login ya existe en esta empresa']);
            exit;
        }

        if ($pswd === '') $pswd = '123456';

        $colsInsert = "login, name, email, phone, documento, pswd, active, priv_admin, id_empresa, id_grupo, id_sucursal, id_caja, caja_def, genero, role, foto";
        $valsInsert = ":login, :name, :email, :phone, :documento, :pswd, :active, :priv_admin, :emp, :ig, :id_sucursal, :id_caja, :caja_def, :genero, :role, 'defaultuser.png'";
        if ($hasTiposPrecioCol) {
            $colsInsert .= ", tipos_precio_asignados";
            $valsInsert .= ", :tipos_precio_asignados";
        }
        $sql = "INSERT INTO " . MASTER_DB . ".sec_users ({$colsInsert}) VALUES ({$valsInsert})";
        $stmt = $pdo->prepare($sql);
        $paramsInsert = [
            ':login'=>$login, ':name'=>$name, ':email'=>$email, ':phone'=>$phone,
            ':documento'=>$documento, ':pswd'=>md5($pswd), ':active'=>$active,
            ':priv_admin'=>$priv_admin, ':emp'=>$id_empresa, ':ig'=>$id_empresa,
            ':id_sucursal'=>$id_sucursal, ':id_caja'=>$id_caja, ':caja_def'=>$id_caja,
            ':genero'=>$genero, ':role'=>$role
        ];
        if ($hasTiposPrecioCol) {
            $paramsInsert[':tipos_precio_asignados'] = $tiposPrecioCsv;
        }
        $stmt->execute($paramsInsert);
        $id_login = $pdo->lastInsertId();

        // Asignar grupo
        $pdo->prepare("INSERT INTO " . MASTER_DB . ".sec_users_groups (group_id, id_grupo, login) VALUES (:gid, :ig, :login)")
            ->execute([':gid'=>$group_id, ':ig'=>$id_empresa, ':login'=>$login]);

        $msg = 'Usuario creado';
    }

    $pdo->commit();
    echo json_encode(['ok'=>true, 'msg'=>$msg, 'id_login'=>$id_login]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
