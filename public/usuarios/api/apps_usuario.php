<?php
/**
 * API Usuarios - Gestión de acceso a apps (permisos por usuario/grupo)
 * Obtiene apps habilitadas de la suscripción de la empresa y permisos del usuario
 * Tablas: saas_suscripcion, saas_suscripcion_apps, saas_apps_catalogo, sec_groups_apps, sec_users_groups
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';

$id_empresa = $_SESSION['id_empresa'] ?? null;
if (!$id_empresa) {
    echo json_encode(['ok' => false, 'error' => 'Sin sesión'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$pdo = getMasterPdo();

Permission::requireAccess('usuarios');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function respond_json(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function normalizePermissionBase(array $row): string {
    $permiso = trim((string)($row['permiso_base'] ?? ''));
    if ($permiso !== '') return $permiso;

    $codigo = trim((string)($row['codigo_app'] ?? ''));
    if ($codigo !== '') return $codigo;

    $ruta = trim((string)($row['ruta_app'] ?? ''));
    if ($ruta !== '') {
        $base = basename($ruta);
        $base = preg_replace('/\.php$/i', '', $base);
        $base = trim((string)$base);
        if ($base !== '') return $base;
    }

    return '';
}

function dedupeAppsByPermisoBase(array $apps): array {
    $pick = [];
    foreach ($apps as $row) {
        $permiso = normalizePermissionBase($row);
        if ($permiso === '') continue;
        $row['permiso_base'] = $permiso;
        $row['cancelar_al_cierre'] = (int)($row['cancelar_al_cierre'] ?? 0);
        $row['id_app'] = (int)($row['id_app'] ?? 0);

        $key = mb_strtolower($permiso);
        if (!isset($pick[$key])) {
            $pick[$key] = $row;
            continue;
        }

        // Preferir app que NO esté marcada para cancelar al cierre.
        $curr = $pick[$key];
        $currCancel = (int)($curr['cancelar_al_cierre'] ?? 0);
        $newCancel = (int)($row['cancelar_al_cierre'] ?? 0);

        if ($currCancel === 1 && $newCancel === 0) {
            $pick[$key] = $row;
            continue;
        }

        // Si están en igual estado, preferir id_app mayor (más reciente en catálogo).
        if ($currCancel === $newCancel && (int)$row['id_app'] > (int)($curr['id_app'] ?? 0)) {
            $pick[$key] = $row;
        }
    }

    $result = array_values($pick);
    usort($result, static function(array $a, array $b): int {
        return strcasecmp((string)($a['nombre_app'] ?? ''), (string)($b['nombre_app'] ?? ''));
    });
    return $result;
}

function resolveCatalogIconSvg(string $iconoSvg): string {
    $icon = trim($iconoSvg);
    if ($icon === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $icon)) {
        return $icon;
    }

    $path = (string)(parse_url($icon, PHP_URL_PATH) ?? '');
    if ($path === '') {
        $path = $icon;
    }
    if (strpos($path, '..') !== false) {
        return '';
    }

    $projectRoot = dirname(__DIR__, 3);

    if (str_starts_with($path, '/public/')) {
        $disk = $projectRoot . $path;
        return is_file($disk) ? $path : '';
    }

    if (str_starts_with($path, '/')) {
        $disk = $projectRoot . $path;
        if (is_file($disk)) {
            return $path;
        }
        $diskPublic = $projectRoot . '/public' . $path;
        if (is_file($diskPublic)) {
            return '/public' . $path;
        }
        return '';
    }

    $rel = ltrim($path, '/');
    $disk = $projectRoot . '/public/' . $rel;
    if (is_file($disk)) {
        return '/public/' . $rel;
    }

    return '';
}

function detectSecGroupsStatusColumn(PDO $pdo): ?string {
    $candidates = ['active', 'estado', 'anulado', 'deleted_at'];
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'serproc1'
          AND table_name = 'sec_groups'
          AND column_name IN ('active','estado','anulado','deleted_at')
    ");
    $stmt->execute();
    $cols = array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name'));
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) {
            return $c;
        }
    }
    return null;
}

function secGroupsAppsHasIdColumn(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'serproc1'
          AND table_name = 'sec_groups_apps'
          AND column_name = 'id'
        LIMIT 1
    ");
    $stmt->execute();
    $cache = (bool)$stmt->fetchColumn();
    return $cache;
}

function secGroupsActiveWhere(string $statusCol): string {
    if ($statusCol === 'active' || $statusCol === 'estado') {
        return "($statusCol IS NULL OR $statusCol IN ('Y','1',1,'A','ACTIVO'))";
    }
    if ($statusCol === 'anulado') {
        return "($statusCol IS NULL OR $statusCol IN ('N','0',0))";
    }
    if ($statusCol === 'deleted_at') {
        return "$statusCol IS NULL";
    }
    return "1=1";
}

function loadAppsEmpresa(PDO $pdo, int $idEmpresa): array {
    $sql = "SELECT DISTINCT sa.id_app, sa.codigo_app, sa.nombre_app,
                   c.icono, c.icono_svg, c.color, c.modulo, c.ruta_app,
                   COALESCE(sa.cancelar_al_cierre,0) AS cancelar_al_cierre,
                   COALESCE(c.permiso_base, sa.codigo_app) AS permiso_base
            FROM " . MASTER_DB . ".saas_suscripcion_apps sa
            INNER JOIN " . MASTER_DB . ".saas_suscripcion s ON sa.id_suscripcion = s.id_suscripcion
            LEFT JOIN " . MASTER_DB . ".saas_apps_catalogo c ON c.id_app = sa.id_app
            WHERE s.id_empresa = :emp
              AND LOWER(TRIM(COALESCE(s.estado, ''))) IN ('activa','gracia','vigente','activo')
              AND COALESCE(sa.activo,1) = 1
              AND COALESCE(sa.cancelar_al_cierre,0) = 0
            ORDER BY sa.nombre_app";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':emp' => $idEmpresa]);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($apps as &$app) {
        $app['icono_svg_resuelto'] = resolveCatalogIconSvg((string)($app['icono_svg'] ?? ''));
    }
    unset($app);
    $apps = dedupeAppsByPermisoBase($apps);
    if (!empty($apps)) return $apps;

    // Fallback: si no hay estado compatible, usar la suscripción más reciente de la empresa
    $sqlFallback = "SELECT DISTINCT sa.id_app, sa.codigo_app, sa.nombre_app,
                           c.icono, c.icono_svg, c.color, c.modulo, c.ruta_app,
                           COALESCE(sa.cancelar_al_cierre,0) AS cancelar_al_cierre,
                           COALESCE(c.permiso_base, sa.codigo_app) AS permiso_base
                    FROM " . MASTER_DB . ".saas_suscripcion_apps sa
                    INNER JOIN " . MASTER_DB . ".saas_suscripcion s ON sa.id_suscripcion = s.id_suscripcion
                    LEFT JOIN " . MASTER_DB . ".saas_apps_catalogo c ON c.id_app = sa.id_app
                    WHERE s.id_empresa = :emp
                      AND COALESCE(sa.activo,1) = 1
                      AND COALESCE(sa.cancelar_al_cierre,0) = 0
                      AND s.id_suscripcion = (
                        SELECT MAX(s2.id_suscripcion)
                        FROM " . MASTER_DB . ".saas_suscripcion s2
                        WHERE s2.id_empresa = :emp2
                      )
                    ORDER BY sa.nombre_app";
    $stmt2 = $pdo->prepare($sqlFallback);
    $stmt2->execute([':emp' => $idEmpresa, ':emp2' => $idEmpresa]);
    $apps = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($apps as &$app) {
        $app['icono_svg_resuelto'] = resolveCatalogIconSvg((string)($app['icono_svg'] ?? ''));
    }
    unset($app);
    return dedupeAppsByPermisoBase($apps);
}

try {
    switch ($action) {

        case 'apps_empresa':
            // Apps habilitadas para la empresa (desde suscripción activa)
            $apps = loadAppsEmpresa($pdo, (int)$id_empresa);
            respond_json(['ok'=>true, 'apps'=>$apps]);
            break;

        case 'permisos_usuario':
            // Obtener permisos de un usuario (basado en su grupo)
            $login = $_GET['login'] ?? '';
            if (!$login) { respond_json(['ok'=>false,'error'=>'Login requerido']); exit; }

            // Obtener grupo del usuario
            // Compatibilidad: sec_users_groups no siempre tiene columna `id`.
            $stmtG = $pdo->prepare("SELECT group_id FROM " . MASTER_DB . ".sec_users_groups WHERE TRIM(login) = TRIM(:login) AND id_grupo = :ig ORDER BY group_id DESC LIMIT 1");
            $stmtG->execute([':login'=>$login, ':ig'=>$id_empresa]);
            $grupo = $stmtG->fetch(PDO::FETCH_ASSOC);
            $group_id = $grupo ? $grupo['group_id'] : 0;

            // Fallback para empresas nuevas: tomar grupo desde sec_users e hidratar sec_users_groups.
            if ($group_id <= 0) {
                $stmtU = $pdo->prepare("SELECT id_grupo FROM " . MASTER_DB . ".sec_users WHERE id_empresa = :ig AND TRIM(login) = TRIM(:login) LIMIT 1");
                $stmtU->execute([':ig'=>$id_empresa, ':login'=>$login]);
                $gidFromUser = (int)$stmtU->fetchColumn();
                if ($gidFromUser > 0) {
                    $group_id = $gidFromUser;
                    $pdo->prepare("INSERT INTO " . MASTER_DB . ".sec_users_groups (login, group_id, id_grupo) VALUES (:login, :gid, :ig)")
                        ->execute([':login'=>$login, ':gid'=>$group_id, ':ig'=>$id_empresa]);
                }
            }

            // Apps habilitadas para la empresa (con permiso_base)
            $apps = loadAppsEmpresa($pdo, (int)$id_empresa);

            // Permisos del grupo (indexados por app_name que es permiso_base)
            $permisos = [];
            if ($group_id > 0) {
                $stmtP = $pdo->prepare("SELECT app_name, priv_access, priv_insert, priv_delete, priv_update, priv_export, priv_print
                    FROM " . MASTER_DB . ".sec_groups_apps WHERE id_grupo = :ig AND group_id = :gid");
                $stmtP->execute([':ig'=>$id_empresa, ':gid'=>$group_id]);
                foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $permisos[$p['app_name']] = $p;
                }
            }

            respond_json([
                'ok'       => true,
                'apps'     => $apps,
                'permisos' => $permisos,
                'group_id' => $group_id
            ]);
            break;

        case 'guardar_permiso':
            // Guardar permiso individual de una app para un grupo
            $input = json_decode(file_get_contents('php://input'), true);
            $group_id  = intval($input['group_id'] ?? 0);
            $app_name  = trim($input['app_name'] ?? '');
            $permiso   = $input['permiso'] ?? 'priv_access';
            $valor     = ($input['valor'] ?? 'Y') === 'Y' ? 'Y' : 'N';

            if (!$group_id || !$app_name) {
                echo json_encode(['ok'=>false,'error'=>'Datos incompletos']);
                exit;
            }

            $allowed = ['priv_access','priv_insert','priv_delete','priv_update','priv_export','priv_print'];
            if (!in_array($permiso, $allowed)) {
                echo json_encode(['ok'=>false,'error'=>'Permiso inválido']);
                exit;
            }

            // Verificar si existe
            $hasSecGroupsAppsId = secGroupsAppsHasIdColumn($pdo);
            $checkSql = $hasSecGroupsAppsId
                ? "SELECT id FROM " . MASTER_DB . ".sec_groups_apps WHERE id_grupo = :ig AND group_id = :gid AND app_name = :app"
                : "SELECT 1 FROM " . MASTER_DB . ".sec_groups_apps WHERE id_grupo = :ig AND group_id = :gid AND app_name = :app";
            $check = $pdo->prepare($checkSql);
            $check->execute([':ig'=>$id_empresa, ':gid'=>$group_id, ':app'=>$app_name]);

            if ($check->fetch()) {
                $pdo->prepare("UPDATE " . MASTER_DB . ".sec_groups_apps SET $permiso = :val WHERE id_grupo = :ig AND group_id = :gid AND app_name = :app")
                    ->execute([':val'=>$valor, ':ig'=>$id_empresa, ':gid'=>$group_id, ':app'=>$app_name]);
            } else {
                $pdo->prepare("INSERT INTO " . MASTER_DB . ".sec_groups_apps (id_grupo, group_id, app_name, $permiso) VALUES (:ig, :gid, :app, :val)")
                    ->execute([':ig'=>$id_empresa, ':gid'=>$group_id, ':app'=>$app_name, ':val'=>$valor]);
            }

            echo json_encode(['ok'=>true, 'msg'=>'Permiso actualizado']);
            break;

        case 'toggle_app_acceso':
            // Toggle rápido de acceso a una app para un grupo
            $input = json_decode(file_get_contents('php://input'), true);
            $group_id = intval($input['group_id'] ?? 0);
            $app_name = trim($input['app_name'] ?? '');

            if (!$group_id || !$app_name) {
                echo json_encode(['ok'=>false,'error'=>'Datos incompletos']);
                exit;
            }

            // Check actual
            $hasSecGroupsAppsId = secGroupsAppsHasIdColumn($pdo);
            $checkSql = $hasSecGroupsAppsId
                ? "SELECT id, priv_access FROM " . MASTER_DB . ".sec_groups_apps WHERE id_grupo=:ig AND group_id=:gid AND app_name=:app"
                : "SELECT priv_access FROM " . MASTER_DB . ".sec_groups_apps WHERE id_grupo=:ig AND group_id=:gid AND app_name=:app";
            $check = $pdo->prepare($checkSql);
            $check->execute([':ig'=>$id_empresa, ':gid'=>$group_id, ':app'=>$app_name]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $newVal = $row['priv_access'] === 'Y' ? 'N' : 'Y';
                if ($hasSecGroupsAppsId && isset($row['id'])) {
                    $pdo->prepare("UPDATE " . MASTER_DB . ".sec_groups_apps SET priv_access=:val WHERE id=:id")
                        ->execute([':val'=>$newVal, ':id'=>$row['id']]);
                } else {
                    $pdo->prepare("UPDATE " . MASTER_DB . ".sec_groups_apps SET priv_access=:val WHERE id_grupo=:ig AND group_id=:gid AND app_name=:app")
                        ->execute([':val'=>$newVal, ':ig'=>$id_empresa, ':gid'=>$group_id, ':app'=>$app_name]);
                }
            } else {
                $newVal = 'Y';
                $pdo->prepare("INSERT INTO " . MASTER_DB . ".sec_groups_apps (id_grupo, group_id, app_name, priv_access, priv_insert, priv_delete, priv_update, priv_export, priv_print)
                    VALUES (:ig, :gid, :app, 'Y','Y','Y','Y','Y','Y')")
                    ->execute([':ig'=>$id_empresa, ':gid'=>$group_id, ':app'=>$app_name]);
            }

            echo json_encode(['ok'=>true, 'acceso'=>$newVal, 'msg'=>$newVal === 'Y' ? 'Acceso habilitado' : 'Acceso denegado']);
            break;

        case 'grupos':
            // Listar grupos de la empresa
            $includeInactive = (($_GET['include_inactive'] ?? '0') === '1');
            $statusCol = detectSecGroupsStatusColumn($pdo);
            $where = "id_grupo = :ig";
            if (!$includeInactive) {
                if ($statusCol !== null) {
                    $where .= " AND " . secGroupsActiveWhere($statusCol);
                } else {
                    $where .= " AND description NOT LIKE '[ANULADO] %'";
                }
            }
            if ($statusCol !== null) {
                $activoExpr = ($statusCol === 'anulado')
                    ? "CASE WHEN ($statusCol IS NULL OR $statusCol IN ('N','0',0)) THEN 1 ELSE 0 END"
                    : (($statusCol === 'deleted_at')
                        ? "CASE WHEN $statusCol IS NULL THEN 1 ELSE 0 END"
                        : "CASE WHEN ($statusCol IS NULL OR $statusCol IN ('Y','1',1,'A','ACTIVO')) THEN 1 ELSE 0 END");
                $sql = "SELECT group_id, description, {$activoExpr} AS activo
                        FROM " . MASTER_DB . ".sec_groups
                        WHERE $where
                        ORDER BY description";
            } else {
                $sql = "SELECT group_id, description,
                               CASE WHEN description LIKE '[ANULADO] %' THEN 0 ELSE 1 END AS activo
                        FROM " . MASTER_DB . ".sec_groups
                        WHERE $where
                        ORDER BY description";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':ig'=>$id_empresa]);
            echo json_encode(['ok'=>true, 'grupos'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'guardar_grupo':
            $input = json_decode(file_get_contents('php://input'), true);
            $group_id = intval($input['group_id'] ?? 0);
            $description = trim((string)($input['description'] ?? ''));

            if ($description === '') {
                echo json_encode(['ok'=>false, 'error'=>'Nombre de grupo requerido']);
                exit;
            }
            if (mb_strlen($description) < 2 || mb_strlen($description) > 80) {
                echo json_encode(['ok'=>false, 'error'=>'El nombre debe tener entre 2 y 80 caracteres']);
                exit;
            }

            if ($group_id > 0) {
                Permission::requirePermission('usuarios', 'priv_update');
                $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".sec_groups SET description = :d WHERE id_grupo = :ig AND group_id = :gid");
                $stmt->execute([':d' => $description, ':ig' => $id_empresa, ':gid' => $group_id]);
                echo json_encode(['ok'=>true, 'msg'=>'Grupo actualizado']);
                break;
            }

            Permission::requirePermission('usuarios', 'priv_insert');
            $stmtNext = $pdo->prepare("SELECT COALESCE(MAX(group_id), 0) + 1 FROM " . MASTER_DB . ".sec_groups WHERE id_grupo = :ig");
            $stmtNext->execute([':ig' => $id_empresa]);
            $newGroupId = (int)$stmtNext->fetchColumn();
            if ($newGroupId <= 0) {
                $newGroupId = 1;
            }
            $stmtIns = $pdo->prepare("INSERT INTO " . MASTER_DB . ".sec_groups (id_grupo, group_id, description) VALUES (:ig, :gid, :d)");
            $stmtIns->execute([':ig' => $id_empresa, ':gid' => $newGroupId, ':d' => $description]);
            echo json_encode(['ok'=>true, 'msg'=>'Grupo creado', 'group_id' => $newGroupId]);
            break;

        case 'anular_grupo':
            Permission::requirePermission('usuarios', 'priv_update');
            $input = json_decode(file_get_contents('php://input'), true);
            $group_id = intval($input['group_id'] ?? 0);
            $accion = strtolower(trim((string)($input['accion'] ?? 'anular')));
            if ($group_id <= 0) {
                echo json_encode(['ok'=>false, 'error'=>'Grupo inválido']);
                exit;
            }

            $statusCol = detectSecGroupsStatusColumn($pdo);
            if ($statusCol !== null) {
                if ($statusCol === 'active' || $statusCol === 'estado') {
                    $newVal = ($accion === 'activar') ? 'Y' : 'N';
                } elseif ($statusCol === 'anulado') {
                    $newVal = ($accion === 'activar') ? 'N' : 'Y';
                } else { // deleted_at
                    $newVal = ($accion === 'activar') ? null : date('Y-m-d H:i:s');
                }
                if ($statusCol === 'deleted_at') {
                    $sql = "UPDATE " . MASTER_DB . ".sec_groups SET deleted_at = :val WHERE id_grupo = :ig AND group_id = :gid";
                } else {
                    $sql = "UPDATE " . MASTER_DB . ".sec_groups SET {$statusCol} = :val WHERE id_grupo = :ig AND group_id = :gid";
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':val' => $newVal, ':ig' => $id_empresa, ':gid' => $group_id]);
            } else {
                // Fallback universal: marca en descripción como anulado.
                $stmtGet = $pdo->prepare("SELECT description FROM " . MASTER_DB . ".sec_groups WHERE id_grupo = :ig AND group_id = :gid LIMIT 1");
                $stmtGet->execute([':ig' => $id_empresa, ':gid' => $group_id]);
                $desc = (string)$stmtGet->fetchColumn();
                if ($desc === '') {
                    echo json_encode(['ok'=>false, 'error'=>'Grupo no encontrado']);
                    exit;
                }
                if ($accion === 'activar') {
                    $newDesc = preg_replace('/^\[ANULADO\]\s+/i', '', $desc);
                } else {
                    $baseDesc = preg_replace('/^\[ANULADO\]\s+/i', '', $desc);
                    $newDesc = '[ANULADO] ' . $baseDesc;
                }
                $stmtUpd = $pdo->prepare("UPDATE " . MASTER_DB . ".sec_groups SET description = :d WHERE id_grupo = :ig AND group_id = :gid");
                $stmtUpd->execute([':d' => $newDesc, ':ig' => $id_empresa, ':gid' => $group_id]);
            }
            echo json_encode(['ok'=>true, 'msg'=>($accion === 'activar' ? 'Grupo reactivado' : 'Grupo anulado')]);
            break;

        default:
            respond_json(['ok'=>false,'error'=>'Acción no válida']);
    }
} catch (Throwable $e) {
    respond_json(['ok'=>false, 'error'=>$e->getMessage()]);
}
