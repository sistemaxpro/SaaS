<?php
/**
 * API Usuarios - Listar usuarios de la empresa logada
 * Tabla: serproc1.sec_users
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';

$id_empresa = $_SESSION['id_empresa'] ?? null;
if (!$id_empresa) { echo json_encode(['ok'=>false,'error'=>'Sin sesión']); exit; }

$pdo = getMasterPdo();

Permission::requireAccess('usuarios');

$search  = trim($_GET['search'] ?? '');
$searchNorm = preg_replace('/\s+/', ' ', $search);
$estado  = $_GET['estado'] ?? 'all';
$page    = max(1, intval($_GET['page'] ?? 1));
$limit   = max(10, min(100, intval($_GET['limit'] ?? 25)));
$offset  = ($page - 1) * $limit;
$sortBy  = $_GET['sort'] ?? 'name';
$sortDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

$allowedSort = ['name','login','email','active','priv_admin','id_sucursal'];
if (!in_array($sortBy, $allowedSort)) $sortBy = 'name';

function respond_json(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function hasColumn(PDO $pdo, string $schema, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=:s AND table_name=:t AND column_name=:c LIMIT 1");
    $stmt->execute([':s' => $schema, ':t' => $table, ':c' => $column]);
    return (bool)$stmt->fetchColumn();
}

function syncUsersStructure(PDO $pdo, int $idEmpresa, int $empresaBase = 169): void {
    // 1) Si la empresa no tiene grupos, copiar estructura base.
    $stmtGroups = $pdo->prepare("SELECT COUNT(*) FROM " . MASTER_DB . ".sec_groups WHERE id_grupo = :ig");
    $stmtGroups->execute([':ig' => $idEmpresa]);
    $hasGroups = (int)$stmtGroups->fetchColumn() > 0;

    if (!$hasGroups) {
        $stmtBase = $pdo->prepare("
            SELECT group_id, description
            FROM " . MASTER_DB . ".sec_groups
            WHERE id_grupo = :base
            ORDER BY group_id
        ");
        $stmtBase->execute([':base' => $empresaBase]);
        $baseGroups = $stmtBase->fetchAll(PDO::FETCH_ASSOC);

        if (!$baseGroups) {
            $baseGroups = [
                ['group_id' => 1, 'description' => 'Administrador'],
                ['group_id' => 2, 'description' => 'Usuarios'],
            ];
        }

        $insGroup = $pdo->prepare("
            INSERT INTO " . MASTER_DB . ".sec_groups (id_grupo, group_id, description)
            VALUES (:ig, :gid, :d)
        ");
        foreach ($baseGroups as $g) {
            $insGroup->execute([
                ':ig' => $idEmpresa,
                ':gid' => (int)$g['group_id'],
                ':d' => (string)$g['description'],
            ]);
        }
    }

    // 2) Garantizar mapeo usuario->grupo para cada usuario de la empresa.
    $stmtUsers = $pdo->prepare("
        SELECT u.login, COALESCE(NULLIF(u.id_grupo,0),1) AS group_id
        FROM " . MASTER_DB . ".sec_users u
        WHERE u.id_empresa = :ig
          AND NOT EXISTS (
                SELECT 1
                FROM " . MASTER_DB . ".sec_users_groups ug
                WHERE ug.id_grupo = :ig2
                  AND TRIM(ug.login) = TRIM(u.login)
          )
    ");
    $stmtUsers->execute([':ig' => $idEmpresa, ':ig2' => $idEmpresa]);
    $missingMaps = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

    if ($missingMaps) {
        $insMap = $pdo->prepare("
            INSERT INTO " . MASTER_DB . ".sec_users_groups (login, group_id, id_grupo)
            VALUES (:login, :gid, :ig)
        ");
        foreach ($missingMaps as $m) {
            $insMap->execute([
                ':login' => (string)$m['login'],
                ':gid' => (int)$m['group_id'],
                ':ig' => $idEmpresa,
            ]);
        }
    }
}

try {
    $hasTiposPrecioAsign = hasColumn($pdo, 'serproc1', 'sec_users', 'tipos_precio_asignados');
    syncUsersStructure($pdo, (int)$id_empresa, 169);

    $where = ["u.id_empresa = :id_empresa"];
    $params = [':id_empresa' => $id_empresa];

    if ($searchNorm !== '') {
        $where[] = "(
            TRIM(u.name) LIKE :search
            OR TRIM(u.login) LIKE :search2
            OR TRIM(u.email) LIKE :search3
            OR TRIM(u.documento) LIKE :search4
            OR CONCAT(TRIM(u.login), ' ', TRIM(u.name)) LIKE :search5
        )";
        $params[':search']  = "%$searchNorm%";
        $params[':search2'] = "%$searchNorm%";
        $params[':search3'] = "%$searchNorm%";
        $params[':search4'] = "%$searchNorm%";
        $params[':search5'] = "%$searchNorm%";
    }

    if ($estado === 'Y' || $estado === 'N') {
        $where[] = "u.active = :estado";
        $params[':estado'] = $estado;
    }

    $whereSQL = implode(' AND ', $where);

    // Contar total
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM " . MASTER_DB . ".sec_users u WHERE $whereSQL");
    $stmtCount->execute($params);
    $total = $stmtCount->fetchColumn();

    // Listar
    $tiposSelect = $hasTiposPrecioAsign ? "u.tipos_precio_asignados" : "'' AS tipos_precio_asignados";
    $sql = "SELECT u.id_login, u.login, u.name, u.email, u.active, u.priv_admin,
                   u.id_sucursal, u.id_caja, u.caja_def, u.documento, u.phone, u.foto, u.genero,
                   u.role, u.edita_precio, u.ver_costo, u.ajusta_stock, u.ti, {$tiposSelect},
                   (
                       SELECT ug.group_id
                       FROM " . MASTER_DB . ".sec_users_groups ug
                       WHERE ug.id_grupo = u.id_empresa
                         AND TRIM(ug.login) = TRIM(u.login)
                       ORDER BY ug.group_id DESC
                       LIMIT 1
                   ) AS group_id,
                   (
                       SELECT sg.description
                       FROM " . MASTER_DB . ".sec_users_groups ug2
                       INNER JOIN " . MASTER_DB . ".sec_groups sg
                               ON sg.id_grupo = ug2.id_grupo
                              AND sg.group_id = ug2.group_id
                       WHERE ug2.id_grupo = u.id_empresa
                         AND TRIM(ug2.login) = TRIM(u.login)
                       ORDER BY ug2.group_id DESC
                       LIMIT 1
                   ) AS grupo_nombre,
                   CASE
                       WHEN UPPER(TRIM(COALESCE(u.priv_admin, 'N'))) = 'Y'
                            AND EXISTS (
                                SELECT 1
                                FROM " . MASTER_DB . ".sec_users_groups uga
                                INNER JOIN " . MASTER_DB . ".sec_groups sga
                                        ON sga.id_grupo = uga.id_grupo
                                       AND sga.group_id = uga.group_id
                                WHERE uga.id_grupo = u.id_empresa
                                  AND TRIM(uga.login) = TRIM(u.login)
                                  AND (
                                      sga.group_id = 1
                                      OR UPPER(TRIM(sga.description)) LIKE '%ADMIN%'
                                  )
                            )
                       THEN 'Y' ELSE 'N'
                   END AS is_admin
            FROM " . MASTER_DB . ".sec_users u
            WHERE $whereSQL
            ORDER BY u.$sortBy $sortDir
            LIMIT $limit OFFSET $offset";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback: si hay problema en joins de grupos, devolver lista base de usuarios.
        $sqlBase = "SELECT u.id_login, u.login, u.name, u.email, u.active, u.priv_admin,
                           u.id_sucursal, u.id_caja, u.caja_def, u.documento, u.phone, u.foto, u.genero,
                           u.role, u.edita_precio, u.ver_costo, u.ajusta_stock, u.ti, {$tiposSelect},
                           NULL as group_id,
                           NULL as grupo_nombre,
                           CASE
                               WHEN UPPER(TRIM(COALESCE(u.priv_admin, 'N'))) = 'Y' THEN 'Y'
                               ELSE 'N'
                           END AS is_admin
                    FROM " . MASTER_DB . ".sec_users u
                    WHERE $whereSQL
                    ORDER BY u.$sortBy $sortDir
                    LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sqlBase);
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Stats
    $stmtStats = $pdo->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN active='Y' THEN 1 ELSE 0 END) as activos,
        SUM(CASE WHEN active='N' THEN 1 ELSE 0 END) as inactivos,
        SUM(CASE WHEN priv_admin='Y' THEN 1 ELSE 0 END) as admins
        FROM " . MASTER_DB . ".sec_users WHERE id_empresa = :id_empresa");
    $stmtStats->execute([':id_empresa' => $id_empresa]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    respond_json([
        'ok'    => true,
        'data'  => $usuarios,
        'total' => (int)$total,
        'page'  => $page,
        'pages' => ceil($total / $limit),
        'stats' => $stats
    ]);

} catch (Exception $e) {
    respond_json(['ok'=>false, 'error'=>$e->getMessage()]);
}
