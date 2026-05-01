<?php
/**
 * Centro de Control de Autorizaciones POS
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);

Session::requireLogin();

function posAuthEnsureUserNotificationsTable(PDO $pdo, string $masterDb): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$masterDb}.smx_user_notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            id_empresa INT NOT NULL DEFAULT 0,
            id_dest_login INT NOT NULL,
            id_remitente_login INT NOT NULL DEFAULT 0,
            tipo VARCHAR(20) NOT NULL DEFAULT 'info',
            titulo VARCHAR(150) NOT NULL,
            mensaje TEXT NOT NULL,
            url VARCHAR(255) NULL,
            leida TINYINT(1) NOT NULL DEFAULT 0,
            fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_lectura DATETIME NULL,
            INDEX idx_dest_fecha (id_dest_login, fecha_creacion),
            INDEX idx_dest_leida (id_dest_login, leida),
            INDEX idx_empresa_dest (id_empresa, id_dest_login)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ensured = true;
}

function posAuthNotifyRequester(
    PDO $pdo,
    string $masterDb,
    int $idEmpresa,
    string $tipo,
    string $estado,
    array $requestRow,
    string $approverLogin,
    string $motivo
): void {
    if (strtoupper(trim($tipo)) === 'ITEM_DELETE') {
        return;
    }

    $targetIdLogin = (int)($requestRow['solicitado_por_id_login'] ?? 0);
    if ($targetIdLogin <= 0) {
        return;
    }

    posAuthEnsureUserNotificationsTable($pdo, $masterDb);

    $tipo = strtoupper(trim($tipo));
    $estado = strtoupper(trim($estado));
    $aprobado = ($estado === 'APROBADO');
    $producto = trim((string)($requestRow['descripcion_producto'] ?? ''));
    $precio = isset($requestRow['precio']) ? (float)$requestRow['precio'] : null;
    $precioMinimo = isset($requestRow['precio_minimo']) ? (float)$requestRow['precio_minimo'] : null;
    $solicitante = trim((string)($requestRow['solicitado_por_login'] ?? ''));

    if ($tipo === 'PRICE') {
        $title = $aprobado
            ? 'Resultado de solicitud de precio especial'
            : 'Solicitud de precio especial rechazada';
        $parts = [];
        $parts[] = $aprobado
            ? 'Tu solicitud de precio especial fue aprobada'
            : 'Tu solicitud de precio especial fue rechazada';
        if ($producto !== '') {
            $parts[] = 'para ' . $producto;
        }
        if ($precio !== null) {
            $parts[] = 'por ' . number_format($precio, 0, ',', '.');
        }
        if ($precioMinimo !== null) {
            $parts[] = '(minimo ' . number_format($precioMinimo, 0, ',', '.') . ')';
        }
        $message = trim(implode(' ', $parts)) . '.';
        if ($approverLogin !== '') {
            $message .= ' Resuelto por ' . $approverLogin . '.';
        }
        if ($motivo !== '') {
            $message .= ' Motivo: ' . $motivo . '.';
        }
    } else {
        $labelByType = [
            'VOID' => 'anulación de comprobante',
            'ITEM_DELETE' => 'eliminación de ítem',
        ];
        $label = $labelByType[$tipo] ?? 'solicitud POS';
        $title = $aprobado ? 'Resultado de solicitud POS' : 'Solicitud POS rechazada';
        $message = 'Tu solicitud de ' . $label . ' fue ' . strtolower($estado) . '.';
        if ($approverLogin !== '') {
            $message .= ' Resuelto por ' . $approverLogin . '.';
        }
        if ($motivo !== '') {
            $message .= ' Motivo: ' . $motivo . '.';
        }
    }

    $stRecent = $pdo->prepare("
        SELECT id
        FROM {$masterDb}.smx_user_notifications
        WHERE id_empresa = :id_empresa
          AND id_dest_login = :id_dest_login
          AND id_remitente_login = 0
          AND titulo = :titulo
          AND mensaje = :mensaje
          AND COALESCE(url, '') = COALESCE(:url, '')
          AND fecha_creacion >= (NOW() - INTERVAL 2 MINUTE)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stRecent->execute([
        ':id_empresa' => $idEmpresa,
        ':id_dest_login' => $targetIdLogin,
        ':titulo' => $title,
        ':mensaje' => $message,
        ':url' => '/public/pos/index_desktop_dropdown.php?desktop=1',
    ]);
    if ($stRecent->fetchColumn()) {
        return;
    }

    $stInsert = $pdo->prepare("
        INSERT INTO {$masterDb}.smx_user_notifications
            (id_empresa, id_dest_login, id_remitente_login, tipo, titulo, mensaje, url, leida, fecha_creacion)
        VALUES
            (:id_empresa, :id_dest_login, 0, :tipo, :titulo, :mensaje, :url, 0, NOW())
    ");
    $stInsert->execute([
        ':id_empresa' => $idEmpresa,
        ':id_dest_login' => $targetIdLogin,
        ':tipo' => $aprobado ? 'success' : 'warning',
        ':titulo' => $title,
        ':mensaje' => $message,
        ':url' => '/public/pos/index_desktop_dropdown.php?desktop=1',
    ]);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? Session::getIdEmpresa());
$idLogin = (int)Session::getIdLogin();
$loginUsuario = trim((string)(Session::get('username') ?? Session::get('login') ?? $idLogin));

try {
    $pdo = Database::getMasterConnection();
    $masterDb = Database::getMasterDbName();

    $isAdmin = false;
    try {
        $stAdmin = $pdo->prepare("
            SELECT priv_admin, role
            FROM {$masterDb}.sec_users
            WHERE id_login = :id_login
              AND id_empresa = :id_empresa
            LIMIT 1
        ");
        $stAdmin->execute([':id_login' => $idLogin, ':id_empresa' => $idEmpresa]);
        $u = $stAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$u) {
            $stAdmin = $pdo->prepare("
                SELECT priv_admin, role
                FROM {$masterDb}.sec_users
                WHERE id_login = :id_login
                LIMIT 1
            ");
            $stAdmin->execute([':id_login' => $idLogin]);
            $u = $stAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($u) {
            $privAdmin = strtoupper(trim((string)($u['priv_admin'] ?? 'N')));
            $role = strtoupper(trim((string)($u['role'] ?? '')));
            $roleAdminLike = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT'], true)
                || (strpos($role, 'ADMIN') !== false && strpos($role, 'VENDEDOR') === false && strpos($role, 'CAJER') === false);
            $isAdmin = ($privAdmin === 'Y') || $roleAdminLike;
        }
    } catch (Throwable $e) {
        $isAdmin = false;
    }
    if (!$isAdmin) {
        if ($action === 'list') {
            http_response_code(200);
        } else {
            http_response_code(403);
        }
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT dbase FROM empresa WHERE id_empresa = :id LIMIT 1');
    $stmt->execute([':id' => $idEmpresa]);
    $db = (string)$stmt->fetchColumn();
    if ($db === '') {
        throw new Exception('Empresa no encontrada');
    }

    $tableExists = static function (string $table) use ($pdo, $db): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :tb');
        $st->execute([':db' => $db, ':tb' => $table]);
        return ((int)$st->fetchColumn()) > 0;
    };
    $columnExists = static function (string $table, string $column) use ($pdo, $db): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db AND table_name = :tb AND column_name = :col');
        $st->execute([':db' => $db, ':tb' => $table, ':col' => $column]);
        return ((int)$st->fetchColumn()) > 0;
    };

    if ($action === 'list') {
        $status = strtoupper(trim((string)($_GET['status'] ?? 'PENDIENTE')));
        $type = strtoupper(trim((string)($_GET['type'] ?? 'ALL')));
        $limit = max(20, min(500, (int)($_GET['limit'] ?? 200)));

        $rows = [];

        if (($type === 'ALL' || $type === 'VOID') && $tableExists('caja_void_approvals')) {
            $origenExpr = $columnExists('caja_void_approvals', 'origen') ? 'origen' : "'' AS origen";
            $sql = "SELECT 'VOID' AS tipo, id, estado, id_operacion AS referencia_id, id_caja, NULL AS id_producto,
                           NULL AS descripcion_producto, NULL AS cantidad, NULL AS precio, NULL AS precio_minimo,
                           solicitado_por_id_login, solicitado_por_login, aprobado_por_id_login, aprobado_por_login,
                           {$origenExpr}, motivo, payload_json, fecha_solicitud, fecha_resolucion
                    FROM $db.caja_void_approvals";
            if ($status !== 'ALL') {
                $sql .= ' WHERE estado = :status';
            }
            $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
            $st = $pdo->prepare($sql);
            if ($status !== 'ALL') $st->execute([':status' => $status]); else $st->execute();
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        if (($type === 'ALL' || $type === 'PRICE') && $tableExists('pos_price_override_approvals')) {
            $origenExpr = $columnExists('pos_price_override_approvals', 'origen') ? 'origen' : "'' AS origen";
            $sql = "SELECT 'PRICE' AS tipo, id, estado, NULL AS referencia_id, id_caja, id_producto,
                           descripcion_producto, NULL AS cantidad, precio_intentado AS precio, precio_minimo,
                           solicitado_por_id_login, solicitado_por_login, aprobado_por_id_login, aprobado_por_login,
                           {$origenExpr}, motivo, payload_json, fecha_solicitud, fecha_resolucion
                    FROM $db.pos_price_override_approvals";
            if ($status !== 'ALL') {
                $sql .= ' WHERE estado = :status';
            }
            $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
            $st = $pdo->prepare($sql);
            if ($status !== 'ALL') $st->execute([':status' => $status]); else $st->execute();
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        if (($type === 'ALL' || $type === 'ITEM_DELETE') && $tableExists('pos_item_delete_approvals')) {
            $origenExpr = $columnExists('pos_item_delete_approvals', 'origen') ? 'origen' : "'' AS origen";
            $sql = "SELECT 'ITEM_DELETE' AS tipo, id, estado, NULL AS referencia_id, id_caja, id_producto,
                           descripcion_producto, cantidad, precio, NULL AS precio_minimo,
                           solicitado_por_id_login, solicitado_por_login, aprobado_por_id_login, aprobado_por_login,
                           {$origenExpr}, motivo, payload_json, fecha_solicitud, fecha_resolucion
                    FROM $db.pos_item_delete_approvals";
            if ($status !== 'ALL') {
                $sql .= ' WHERE estado = :status';
            }
            $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
            $st = $pdo->prepare($sql);
            if ($status !== 'ALL') $st->execute([':status' => $status]); else $st->execute();
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        usort($rows, static function ($a, $b) {
            return strcmp((string)($b['fecha_solicitud'] ?? ''), (string)($a['fecha_solicitud'] ?? ''));
        });

        foreach ($rows as &$row) {
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $row['payload'] = $payload;
        }
        unset($row);

        $loginIds = [];
        foreach ($rows as $row) {
            $solicitaId = (int)($row['solicitado_por_id_login'] ?? 0);
            $autorizaId = (int)($row['aprobado_por_id_login'] ?? 0);
            if ($solicitaId > 0) $loginIds[$solicitaId] = true;
            if ($autorizaId > 0) $loginIds[$autorizaId] = true;
        }

        $userNamesById = [];
        if (!empty($loginIds)) {
            $ids = array_keys($loginIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stUsers = $pdo->prepare("
                SELECT id_login, COALESCE(NULLIF(TRIM(name), ''), login) AS usuario_nombre, COALESCE(NULLIF(TRIM(login), ''), '') AS usuario_login
                FROM {$masterDb}.sec_users
                WHERE id_login IN ($placeholders)
            ");
            $stUsers->execute($ids);
            foreach (($stUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) as $uRow) {
                $userNamesById[(int)$uRow['id_login']] = [
                    'nombre' => (string)($uRow['usuario_nombre'] ?? ''),
                    'login' => (string)($uRow['usuario_login'] ?? ''),
                ];
            }
        }

        foreach ($rows as &$row) {
            $solicitaId = (int)($row['solicitado_por_id_login'] ?? 0);
            $autorizaId = (int)($row['aprobado_por_id_login'] ?? 0);
            $solicitaUser = $userNamesById[$solicitaId] ?? null;
            $autorizaUser = $userNamesById[$autorizaId] ?? null;

            $row['solicitado_por_nombre'] = (string)($solicitaUser['nombre'] ?? ($row['solicitado_por_login'] ?? ''));
            $row['solicitado_por_login'] = (string)($solicitaUser['login'] ?? ($row['solicitado_por_login'] ?? ''));
            $row['aprobado_por_nombre'] = (string)($autorizaUser['nombre'] ?? ($row['aprobado_por_login'] ?? ''));
            $row['aprobado_por_login'] = (string)($autorizaUser['login'] ?? ($row['aprobado_por_login'] ?? ''));
        }
        unset($row);

        echo json_encode(['success' => true, 'items' => $rows]);
        exit;
    }

    if ($action === 'decide') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $tipo = strtoupper(trim((string)($input['tipo'] ?? '')));
        $id = (int)($input['id'] ?? 0);
        $decision = strtoupper(trim((string)($input['decision'] ?? '')));
        $motivo = trim((string)($input['motivo'] ?? ''));

        if ($id <= 0 || $tipo === '' || !in_array($decision, ['APROBAR', 'RECHAZAR'], true)) {
            throw new Exception('Parámetros inválidos');
        }

        $map = [
            'VOID' => 'caja_void_approvals',
            'PRICE' => 'pos_price_override_approvals',
            'ITEM_DELETE' => 'pos_item_delete_approvals',
        ];
        $table = $map[$tipo] ?? '';
        if ($table === '' || !$tableExists($table)) {
            throw new Exception('Tipo no disponible');
        }

        $stRequest = $pdo->prepare("
            SELECT id, solicitado_por_id_login, solicitado_por_login, descripcion_producto,
                   " . ($tipo === 'PRICE' ? "precio_intentado AS precio, precio_minimo" : "precio, NULL AS precio_minimo") . "
            FROM $db.$table
            WHERE id = :id
            LIMIT 1
        ");
        $stRequest->execute([':id' => $id]);
        $requestRow = $stRequest->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$requestRow) {
            throw new Exception('La solicitud no existe');
        }

        $newState = ($decision === 'APROBAR') ? 'APROBADO' : 'RECHAZADO';

        $sql = "UPDATE $db.$table
            SET estado = :estado,
                aprobado_por_id_login = :id_login,
                aprobado_por_login = :login,
                fecha_resolucion = NOW()";
        $params = [
            ':estado' => $newState,
            ':id_login' => $idLogin,
            ':login' => $loginUsuario,
            ':id' => $id,
        ];
        if ($motivo !== '') {
            $sql .= ", motivo = :motivo";
            $params[':motivo'] = substr($motivo, 0, 255);
        }
        $sql .= " WHERE id = :id
                  AND estado = 'PENDIENTE'";

        $st = $pdo->prepare($sql);
        $st->execute($params);

        if ($st->rowCount() <= 0) {
            echo json_encode(['success' => false, 'message' => 'La solicitud no está pendiente o no existe']);
            exit;
        }

        posAuthNotifyRequester($pdo, $masterDb, $idEmpresa, $tipo, $newState, $requestRow, $loginUsuario, $motivo);

        echo json_encode(['success' => true, 'message' => 'Solicitud actualizada']);
        exit;
    }

    throw new Exception('Acción no soportada');
} catch (Throwable $e) {
    if (in_array($action, ['decide', 'list'], true)) {
        http_response_code(200);
    } else {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
