<?php
$_tallerActionBootstrap = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
if (in_array($_tallerActionBootstrap, ['public', 'public_chat'], true)) {
    define('TALLER_SKIP_PERMISSION_CHECK', true);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $GLOBALS['__taller_raw_input'] = file_get_contents('php://input');
    $rawBootstrap = json_decode((string)$GLOBALS['__taller_raw_input'], true);
    $postActionBootstrap = strtolower(trim((string)($rawBootstrap['action'] ?? $_POST['action'] ?? '')));
    if ($postActionBootstrap === 'public_chat_send') {
        define('TALLER_SKIP_PERMISSION_CHECK', true);
    }
}
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../../lib/producto_stock_snapshot.php';

function tallerOrdenVehiculoFotosPublic(PDO $pdo, int $idEmpresa, string $db, int $idVehiculo): array
{
    if ($idVehiculo <= 0 || !tallerHasTable($pdo, $db, 'taller_vehiculo_fotos')) {
        return [];
    }

    $st = $pdo->prepare("
        SELECT id_foto, id_vehiculo, ruta, storage, file_id, url, titulo, created_at
        FROM {$db}.taller_vehiculo_fotos
        WHERE id_vehiculo = :id
        ORDER BY id_foto DESC
    ");
    $st->execute([':id' => $idVehiculo]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $storage = strtolower(trim((string)($row['storage'] ?? 'local')));
        $url = trim((string)($row['url'] ?? ''));
        $ruta = trim((string)($row['ruta'] ?? ''));
        if ($storage === 'r2' && $url !== '') {
            $row['url'] = $url;
            continue;
        }
        if ($url !== '' && preg_match('~^https?://~i', $url)) {
            $row['url'] = $url;
            continue;
        }
        $row['url'] = $ruta !== ''
            ? "/public/_lib/file/img/taller_vehiculos/e{$idEmpresa}/v{$idVehiculo}/" . rawurlencode($ruta)
            : '';
    }
    unset($row);

    return array_values(array_filter($rows, static fn(array $row): bool => trim((string)($row['url'] ?? '')) !== ''));
}

function tallerMecanicoExpr(): string
{
    return "COALESCE(NULLIF(TRIM(mu.name), ''), NULLIF(TRIM(mu.login), ''), '')";
}

function tallerChatItems(PDO $pdo, string $db, int $idOrden): array
{
    if ($idOrden <= 0 || !tallerHasTable($pdo, $db, 'taller_ot_chat')) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT id_chat, id_orden, sender_type, id_login, sender_name, mensaje, created_at
        FROM {$db}.taller_ot_chat
        WHERE id_orden = :id
        ORDER BY id_chat ASC
        LIMIT 300
    ");
    $stmt->execute([':id' => $idOrden]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$method = $_SERVER['REQUEST_METHOD'];
$idEmpresa = (int)(($method === 'GET' ? ($_GET['id_empresa'] ?? 0) : null) ?? ($_SESSION['id_empresa'] ?? 0));
if ($idEmpresa <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'id_empresa requerido']);
    exit;
}

try {
    $conn = tallerConn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = $conn['masterPdo'] ?? Database::getMasterConnection();
    $masterDb = $conn['masterDb'] ?? MASTER_DB;
    $idLogin = (int)($_SESSION['id_login'] ?? 0);
    $hasContactos = tallerHasTable($pdo, $db, 'clientes')
        && tallerHasColumn($pdo, $db, 'clientes', 'id')
        && tallerHasColumn($pdo, $db, 'clientes', 'nombre');
    $hasContactosTelefono = $hasContactos && tallerHasColumn($pdo, $db, 'clientes', 'telefono');
    $hasTallerClientes = tallerHasTable($pdo, $db, 'taller_clientes')
        && tallerHasColumn($pdo, $db, 'taller_clientes', 'id_cliente')
        && tallerHasColumn($pdo, $db, 'taller_clientes', 'nombre');
    $joinClientes = '';
    if ($hasContactos) {
        $joinClientes .= " LEFT JOIN {$db}.clientes c ON c.id = o.id_cliente";
    }
    if ($hasTallerClientes) {
        $joinClientes .= " LEFT JOIN {$db}.taller_clientes tc ON tc.id_cliente = o.id_cliente";
    }
    $joinMecanico = " LEFT JOIN {$masterDb}.sec_users mu ON mu.id_login = o.id_mecanico";
    $clienteNombreExpr = "COALESCE("
        . ($hasContactos ? "NULLIF(TRIM(c.nombre), '')" : "NULL")
        . ", "
        . ($hasTallerClientes ? "NULLIF(TRIM(tc.nombre), '')" : "NULL")
        . ", '')";
    $clienteTelefonoExpr = "COALESCE("
        . ($hasContactosTelefono ? "NULLIF(TRIM(c.telefono), '')" : "NULL")
        . ", "
        . ($hasTallerClientes && tallerHasColumn($pdo, $db, 'taller_clientes', 'telefono') ? "NULLIF(TRIM(tc.telefono), '')" : "NULL")
        . ", '')";
    $mecanicoExpr = tallerMecanicoExpr();

    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? 'list');
        if ($action === 'get') {
            $idOrden = (int)($_GET['id_orden'] ?? 0);
            if ($idOrden <= 0) throw new Exception('id_orden requerido');
            $stmt = $pdo->prepare("SELECT o.*,
                                          {$clienteNombreExpr} AS cliente,
                                          {$mecanicoExpr} AS mecanico,
                                          v.marca, v.modelo, v.chapa
                                   FROM {$db}.taller_ordenes o
                                   {$joinClientes}
                                   {$joinMecanico}
                                   LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
                                   WHERE o.id_orden = :id
                                   LIMIT 1");
            $stmt->execute([':id' => $idOrden]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orden) throw new Exception('Orden no encontrada');

            $it = $pdo->prepare("SELECT i.*, s.servicio
                                 FROM {$db}.taller_orden_items i
                                 LEFT JOIN {$db}.taller_servicios s ON s.id_servicio = i.id_servicio
                                 WHERE i.id_orden = :id
                                 ORDER BY i.id_item ASC");
            $it->execute([':id' => $idOrden]);
            $orden['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
            $orden['fotos'] = tallerOrdenVehiculoFotosPublic($pdo, $idEmpresa, $db, (int)($orden['id_vehiculo'] ?? 0));
            $orden['chat'] = tallerChatItems($pdo, $db, $idOrden);
            $orden['share_url'] = !empty($orden['is_public']) && !empty($orden['public_token'])
                ? tallerBuildPublicOtUrl($idEmpresa, (string)$orden['public_token'])
                : '';
            echo json_encode(['success' => true, 'data' => $orden]);
            exit;
        }
        if ($action === 'chat') {
            $idOrden = (int)($_GET['id_orden'] ?? 0);
            if ($idOrden <= 0) throw new Exception('id_orden requerido');
            echo json_encode(['success' => true, 'data' => tallerChatItems($pdo, $db, $idOrden)]);
            exit;
        }
        if ($action === 'public') {
            $token = trim((string)($_GET['token'] ?? ''));
            if ($token === '') throw new Exception('token requerido');
            $stmt = $pdo->prepare("SELECT o.*,
                                          {$clienteNombreExpr} AS cliente,
                                          {$clienteTelefonoExpr} AS telefono_cliente,
                                          {$mecanicoExpr} AS mecanico,
                                          v.marca, v.modelo, v.chapa, v.color, v.anio, v.km_actual
                                   FROM {$db}.taller_ordenes o
                                   {$joinClientes}
                                   {$joinMecanico}
                                   LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
                                   WHERE o.public_token = :token AND o.is_public = 1
                                   LIMIT 1");
            $stmt->execute([':token' => $token]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orden) throw new Exception('Orden no publicada');
            $it = $pdo->prepare("SELECT i.*, s.servicio
                                 FROM {$db}.taller_orden_items i
                                 LEFT JOIN {$db}.taller_servicios s ON s.id_servicio = i.id_servicio
                                 WHERE i.id_orden = :id
                                 ORDER BY i.id_item ASC");
            $it->execute([':id' => (int)$orden['id_orden']]);
            $orden['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
            $orden['fotos'] = tallerOrdenVehiculoFotosPublic($pdo, $idEmpresa, $db, (int)($orden['id_vehiculo'] ?? 0));
            $orden['chat'] = tallerChatItems($pdo, $db, (int)$orden['id_orden']);
            $orden['share_url'] = tallerBuildPublicOtUrl($idEmpresa, $token);
            echo json_encode(['success' => true, 'data' => $orden]);
            exit;
        }
        if ($action === 'public_chat') {
            $token = trim((string)($_GET['token'] ?? ''));
            if ($token === '') throw new Exception('token requerido');
            $stmt = $pdo->prepare("SELECT id_orden FROM {$db}.taller_ordenes WHERE public_token = :token AND is_public = 1 LIMIT 1");
            $stmt->execute([':token' => $token]);
            $idOrden = (int)$stmt->fetchColumn();
            if ($idOrden <= 0) throw new Exception('Orden no publicada');
            echo json_encode(['success' => true, 'data' => tallerChatItems($pdo, $db, $idOrden)]);
            exit;
        }

        $search = trim((string)($_GET['search'] ?? ''));
        $estado = trim((string)($_GET['estado'] ?? ''));
        $where = "WHERE 1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (o.nro_ot LIKE :q OR c.nombre LIKE :q OR v.chapa LIKE :q OR v.modelo LIKE :q)";
            $params[':q'] = "%{$search}%";
        }
        if ($estado !== '') {
            $where .= " AND o.estado = :es";
            $params[':es'] = $estado;
        }
        $vehiculoFotoExpr = "(
                    SELECT COALESCE(NULLIF(vf.url, ''), vf.ruta)
                    FROM {$db}.taller_vehiculo_fotos vf
                    WHERE vf.id_vehiculo = o.id_vehiculo
                    ORDER BY vf.id_foto DESC
                    LIMIT 1
                )";
        $sql = "SELECT o.id_orden, o.nro_ot, o.fecha, o.estado, o.prioridad, o.total, o.id_vehiculo,
                       {$clienteNombreExpr} AS cliente, {$mecanicoExpr} AS mecanico,
                       v.marca, v.modelo, v.chapa,
                       {$vehiculoFotoExpr} AS foto_ruta
                FROM {$db}.taller_ordenes o
                {$joinClientes}
                {$joinMecanico}
                LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
                {$where}
                ORDER BY o.id_orden DESC
                LIMIT 300";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $fotoRuta = trim((string)($row['foto_ruta'] ?? ''));
            $idVehiculo = (int)($row['id_vehiculo'] ?? 0);
            if ($fotoRuta === '' || $idVehiculo <= 0) {
                $row['foto_url'] = '';
                continue;
            }
            $row['foto_url'] = preg_match('~^https?://~i', $fotoRuta)
                ? $fotoRuta
                : "/public/_lib/file/img/taller_vehiculos/e{$idEmpresa}/v{$idVehiculo}/" . rawurlencode($fotoRuta);
        }
        unset($row);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    $input = tallerJsonInput();
    $action = (string)($input['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        tallerCan($action === 'create' ? 'priv_insert' : 'priv_update');

        $ot = $input['orden'] ?? [];
        $items = is_array($ot['items'] ?? null) ? $ot['items'] : [];
        $idOrden = (int)($ot['id_orden'] ?? 0);

        $idCliente = (int)($ot['id_cliente'] ?? 0);
        $idVehiculo = (int)($ot['id_vehiculo'] ?? 0);
        $idMecanico = (int)($ot['id_mecanico'] ?? 0);
        if ($idCliente <= 0 || $idVehiculo <= 0) throw new Exception('Cliente y vehiculo son obligatorios');

        $pdo->beginTransaction();

        if ($action === 'create') {
            $nroOt = buildNroOt($pdo, $db);
            $stmt = $pdo->prepare("INSERT INTO {$db}.taller_ordenes
                (nro_ot, fecha, hora_ingreso, id_cliente, id_vehiculo, id_mecanico, problema, diagnostico, estado, prioridad, km_ingreso, fecha_entrega, fecha_entrega_estimada, total, created_by)
                VALUES (:nro,:f,:hi,:c,:v,:m,:p,:d,:e,:pr,:km,:fe,:fee,0,:u)");
            $stmt->execute([
                ':nro' => $nroOt,
                ':f' => trim((string)($ot['fecha'] ?? date('Y-m-d'))),
                ':hi' => !empty($ot['hora_ingreso']) ? trim((string)$ot['hora_ingreso']) : null,
                ':c' => $idCliente,
                ':v' => $idVehiculo,
                ':m' => $idMecanico > 0 ? $idMecanico : ($idLogin > 0 ? $idLogin : null),
                ':p' => trim((string)($ot['problema'] ?? '')),
                ':d' => trim((string)($ot['diagnostico'] ?? '')),
                ':e' => trim((string)($ot['estado'] ?? 'abierta')),
                ':pr' => trim((string)($ot['prioridad'] ?? 'media')),
                ':km' => (float)($ot['km_ingreso'] ?? 0),
                ':fe' => !empty($ot['fecha_entrega']) ? $ot['fecha_entrega'] : null,
                ':fee' => !empty($ot['fecha_entrega_estimada']) ? $ot['fecha_entrega_estimada'] : null,
                ':u' => $idLogin > 0 ? $idLogin : null,
            ]);
            $idOrden = (int)$pdo->lastInsertId();
        } else {
            if ($idOrden <= 0) throw new Exception('ID de orden requerido');
            $stmt = $pdo->prepare("UPDATE {$db}.taller_ordenes
                                   SET fecha=:f, hora_ingreso=:hi, id_cliente=:c, id_vehiculo=:v, id_mecanico=:m, problema=:p, diagnostico=:d, estado=:e,
                                       prioridad=:pr, km_ingreso=:km, fecha_entrega=:fe, fecha_entrega_estimada=:fee
                                   WHERE id_orden=:id");
            $stmt->execute([
                ':id' => $idOrden,
                ':f' => trim((string)($ot['fecha'] ?? date('Y-m-d'))),
                ':hi' => !empty($ot['hora_ingreso']) ? trim((string)$ot['hora_ingreso']) : null,
                ':c' => $idCliente,
                ':v' => $idVehiculo,
                ':m' => $idMecanico > 0 ? $idMecanico : ($idLogin > 0 ? $idLogin : null),
                ':p' => trim((string)($ot['problema'] ?? '')),
                ':d' => trim((string)($ot['diagnostico'] ?? '')),
                ':e' => trim((string)($ot['estado'] ?? 'abierta')),
                ':pr' => trim((string)($ot['prioridad'] ?? 'media')),
                ':km' => (float)($ot['km_ingreso'] ?? 0),
                ':fe' => !empty($ot['fecha_entrega']) ? $ot['fecha_entrega'] : null,
                ':fee' => !empty($ot['fecha_entrega_estimada']) ? $ot['fecha_entrega_estimada'] : null,
            ]);
            tallerRevertOtExtractoMovements($pdo, $db, $idOrden);
            $pdo->prepare("DELETE FROM {$db}.taller_orden_items WHERE id_orden = :id")->execute([':id' => $idOrden]);
        }

        $total = 0.0;
        $insItem = $pdo->prepare("INSERT INTO {$db}.taller_orden_items
                                  (id_orden, tipo, id_servicio, id_producto, descripcion, cantidad, precio, subtotal)
                                  VALUES (:o,:t,:s,:ip,:d,:c,:p,:st)");
        foreach ($items as $it) {
            $cant = (float)($it['cantidad'] ?? 0);
            $precio = (float)($it['precio'] ?? 0);
            if ($cant <= 0) $cant = 1;
            $subtotal = $cant * $precio;
            $total += $subtotal;
            $tipoItem = trim((string)($it['tipo'] ?? 'servicio'));
            $insItem->execute([
                ':o' => $idOrden,
                ':t' => in_array($tipoItem, ['servicio', 'repuesto', 'manual'], true) ? $tipoItem : 'servicio',
                ':s' => (int)($it['id_servicio'] ?? 0) ?: null,
                ':ip' => (int)($it['id_producto'] ?? 0) ?: null,
                ':d' => trim((string)($it['descripcion'] ?? 'Servicio')),
                ':c' => $cant,
                ':p' => $precio,
                ':st' => $subtotal,
            ]);
        }

        $pdo->prepare("UPDATE {$db}.taller_ordenes SET total=:t WHERE id_orden=:id")->execute([
            ':t' => $total,
            ':id' => $idOrden,
        ]);
        tallerApplyOtExtractoMovements($pdo, $db, $idOrden);

        $vehStmt = $pdo->prepare("SELECT chapa FROM {$db}.taller_vehiculos WHERE id_vehiculo = :id LIMIT 1");
        $vehStmt->execute([':id' => $idVehiculo]);
        $vehiculoRow = $vehStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $chapa = (string)($vehiculoRow['chapa'] ?? '');
        $tituloEvento = $action === 'create' ? 'Nueva OT registrada' : 'OT actualizada';
        $mensajeEvento = ($action === 'create' ? 'Se registró' : 'Se actualizó') . ' la orden ' . (string)($nroOt ?? ('#' . $idOrden));
        tallerPushEventoOt(
            $pdo,
            $db,
            $idOrden,
            $idVehiculo,
            $chapa,
            $action === 'create' ? 'ot_creada' : 'ot_actualizada',
            $tituloEvento,
            $mensajeEvento,
            trim((string)($ot['estado'] ?? 'abierta'))
        );

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'id_orden' => $idOrden,
            'message' => $action === 'create' ? 'Orden creada' : 'Orden actualizada'
        ]);
        exit;
    }

    if ($action === 'delete') {
        tallerCan('priv_delete');
        $idOrden = (int)($input['id_orden'] ?? 0);
        if ($idOrden <= 0) throw new Exception('ID inválido');
        $pdo->beginTransaction();
        tallerRevertOtExtractoMovements($pdo, $db, $idOrden);
        $pdo->prepare("DELETE FROM {$db}.taller_orden_items WHERE id_orden = :id")->execute([':id' => $idOrden]);
        $pdo->prepare("DELETE FROM {$db}.taller_ordenes WHERE id_orden = :id")->execute([':id' => $idOrden]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Orden suprimida']);
        exit;
    }

    if ($action === 'set_estado') {
        tallerCan('priv_update');
        $idOrden = (int)($input['id_orden'] ?? 0);
        $estado = trim((string)($input['estado'] ?? ''));
        if ($idOrden <= 0 || $estado === '') throw new Exception('Datos incompletos');
        $pdo->prepare("UPDATE {$db}.taller_ordenes SET estado = :e WHERE id_orden = :id")->execute([':e' => $estado, ':id' => $idOrden]);
        $stmt = $pdo->prepare("SELECT o.nro_ot, o.id_vehiculo, v.chapa
                               FROM {$db}.taller_ordenes o
                               LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
                               WHERE o.id_orden = :id
                               LIMIT 1");
        $stmt->execute([':id' => $idOrden]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        tallerPushEventoOt(
            $pdo,
            $db,
            $idOrden,
            (int)($orden['id_vehiculo'] ?? 0),
            (string)($orden['chapa'] ?? ''),
            'estado',
            'Estado de OT actualizado',
            'La orden ' . (string)($orden['nro_ot'] ?? ('#' . $idOrden)) . ' ahora está en estado ' . $estado,
            $estado
        );
        echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
        exit;
    }
    if ($action === 'publish') {
        tallerCan('priv_update');
        $idOrden = (int)($input['id_orden'] ?? 0);
        if ($idOrden <= 0) throw new Exception('ID inválido');
        $stmt = $pdo->prepare("SELECT o.id_orden, o.nro_ot, o.public_token,
                                      {$clienteNombreExpr} AS cliente, {$clienteTelefonoExpr} AS telefono_cliente
                               FROM {$db}.taller_ordenes o
                               {$joinClientes}
                               WHERE o.id_orden = :id
                               LIMIT 1");
        $stmt->execute([':id' => $idOrden]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$orden) throw new Exception('Orden no encontrada');
        $token = trim((string)($orden['public_token'] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }
        $pdo->prepare("UPDATE {$db}.taller_ordenes
                       SET is_public = 1, public_token = :token, public_shared_at = NOW()
                       WHERE id_orden = :id")
            ->execute([':token' => $token, ':id' => $idOrden]);
        $stmtVeh = $pdo->prepare("SELECT id_vehiculo, chapa FROM {$db}.taller_vehiculos WHERE id_vehiculo = (SELECT id_vehiculo FROM {$db}.taller_ordenes WHERE id_orden = :id LIMIT 1)");
        $stmtVeh->execute([':id' => $idOrden]);
        $veh = $stmtVeh->fetch(PDO::FETCH_ASSOC) ?: [];
        tallerPushEventoOt(
            $pdo,
            $db,
            $idOrden,
            (int)($veh['id_vehiculo'] ?? 0),
            (string)($veh['chapa'] ?? ''),
            'publicada',
            'OT publicada',
            'Ya podés seguir la OT desde tu aplicación.',
            null
        );
        $shareUrl = tallerBuildPublicOtUrl($idEmpresa, $token);
        $telefono = preg_replace('/\D+/', '', (string)($orden['telefono_cliente'] ?? ''));
        $mensaje = 'Hola ' . trim((string)($orden['cliente'] ?? '')) . ', podés ver el detalle de tu OT ' . trim((string)($orden['nro_ot'] ?? '')) . ' aquí: ' . $shareUrl;
        echo json_encode([
            'success' => true,
            'share_url' => $shareUrl,
            'whatsapp_url' => $telefono !== '' ? 'https://api.whatsapp.com/send?phone=' . $telefono . '&text=' . rawurlencode($mensaje) : '',
            'telefono' => $telefono,
            'message' => 'OT publicada'
        ]);
        exit;
    }
    if ($action === 'notify') {
        tallerCan('priv_update');
        $idOrden = (int)($input['id_orden'] ?? 0);
        $titulo = trim((string)($input['titulo'] ?? 'Actualización del taller'));
        $mensaje = trim((string)($input['mensaje'] ?? ''));
        if ($idOrden <= 0 || $mensaje === '') throw new Exception('Datos incompletos');
        $stmt = $pdo->prepare("SELECT o.id_orden, o.estado, o.id_vehiculo, v.chapa
                               FROM {$db}.taller_ordenes o
                               LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
                               WHERE o.id_orden = :id
                               LIMIT 1");
        $stmt->execute([':id' => $idOrden]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$orden) throw new Exception('Orden no encontrada');
        tallerPushEventoOt(
            $pdo,
            $db,
            $idOrden,
            (int)($orden['id_vehiculo'] ?? 0),
            (string)($orden['chapa'] ?? ''),
            'manual',
            $titulo,
            $mensaje,
            (string)($orden['estado'] ?? '')
        );
        echo json_encode(['success' => true, 'message' => 'Notificación enviada']);
        exit;
    }

    if ($action === 'chat_send') {
        tallerCan('priv_update');
        $idOrden = (int)($input['id_orden'] ?? 0);
        $mensaje = trim((string)($input['mensaje'] ?? ''));
        if ($idOrden <= 0 || $mensaje === '') throw new Exception('Datos incompletos');

        $stmt = $pdo->prepare("
            SELECT o.id_orden, o.nro_ot, o.id_vehiculo, o.id_mecanico,
                   {$clienteNombreExpr} AS cliente,
                   {$mecanicoExpr} AS mecanico,
                   v.chapa
            FROM {$db}.taller_ordenes o
            {$joinClientes}
            {$joinMecanico}
            LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
            WHERE o.id_orden = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $idOrden]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$orden) throw new Exception('Orden no encontrada');

        $stmtUser = $masterPdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(login), ''), CONCAT('Usuario #', id_login)) AS nombre
            FROM {$masterDb}.sec_users
            WHERE id_login = :id
            LIMIT 1
        ");
        $stmtUser->execute([':id' => $idLogin]);
        $senderName = (string)($stmtUser->fetchColumn() ?: 'Taller');
        $senderType = ((int)($orden['id_mecanico'] ?? 0) === $idLogin) ? 'mecanico' : 'interno';

        $ins = $pdo->prepare("
            INSERT INTO {$db}.taller_ot_chat (id_orden, sender_type, id_login, sender_name, mensaje)
            VALUES (:o, :t, :l, :n, :m)
        ");
        $ins->execute([
            ':o' => $idOrden,
            ':t' => $senderType,
            ':l' => $idLogin > 0 ? $idLogin : null,
            ':n' => $senderName,
            ':m' => $mensaje,
        ]);

        tallerPushEventoOt(
            $pdo,
            $db,
            $idOrden,
            (int)($orden['id_vehiculo'] ?? 0),
            (string)($orden['chapa'] ?? ''),
            'chat',
            'Nuevo mensaje del taller',
            $senderName . ': ' . $mensaje,
            null
        );
        echo json_encode(['success' => true, 'data' => tallerChatItems($pdo, $db, $idOrden)]);
        exit;
    }

    if ($action === 'public_chat_send') {
        $token = trim((string)($input['token'] ?? ''));
        $mensaje = trim((string)($input['mensaje'] ?? ''));
        if ($token === '' || $mensaje === '') throw new Exception('Datos incompletos');

        $stmt = $pdo->prepare("
            SELECT o.id_orden, o.nro_ot, o.id_vehiculo, o.estado,
                   {$clienteNombreExpr} AS cliente,
                   v.chapa
            FROM {$db}.taller_ordenes o
            {$joinClientes}
            LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
            WHERE o.public_token = :token AND o.is_public = 1
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$orden) throw new Exception('Orden no publicada');

        $senderName = trim((string)($orden['cliente'] ?? 'Cliente'));
        if ($senderName === '') $senderName = 'Cliente';

        $ins = $pdo->prepare("
            INSERT INTO {$db}.taller_ot_chat (id_orden, sender_type, id_login, sender_name, mensaje)
            VALUES (:o, 'cliente', NULL, :n, :m)
        ");
        $ins->execute([
            ':o' => (int)$orden['id_orden'],
            ':n' => $senderName,
            ':m' => $mensaje,
        ]);

        tallerPushEventoOt(
            $pdo,
            $db,
            (int)$orden['id_orden'],
            (int)($orden['id_vehiculo'] ?? 0),
            (string)($orden['chapa'] ?? ''),
            'chat_cliente',
            'Nuevo mensaje del cliente',
            $senderName . ': ' . $mensaje,
            (string)($orden['estado'] ?? '')
        );
        echo json_encode(['success' => true, 'data' => tallerChatItems($pdo, $db, (int)$orden['id_orden'])]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Accion no valida']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function buildNroOt(PDO $pdo, string $db): string
{
    $prefix = 'OT-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$db}.taller_ordenes WHERE nro_ot LIKE :p");
    $stmt->execute([':p' => $prefix . '%']);
    $next = (int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function tallerBuildPublicOtUrl(int $idEmpresa, string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/public/taller/ot_public.php?id_empresa=' . $idEmpresa . '&token=' . rawurlencode($token);
}

function tallerExtractoCols(PDO $pdo, string $db): array
{
    static $cache = [];
    $key = $db . '.extracto_productos';
    if (isset($cache[$key])) return $cache[$key];
    $cache[$key] = tallerTableColumns($pdo, $db, 'extracto_productos');
    return $cache[$key];
}

function tallerStockSnapshotReady(PDO $pdo, string $db): bool
{
    return sxProductoStockSnapshotReady($pdo, $db);
}

function tallerStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
{
    sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
}

function tallerOtReferencia(int $idOrden): string
{
    return 'OT-' . $idOrden;
}

function tallerRevertOtExtractoMovements(PDO $pdo, string $db, int $idOrden): void
{
    if ($idOrden <= 0 || !tallerHasTable($pdo, $db, 'extracto_productos')) return;
    $cols = tallerExtractoCols($pdo, $db);
    if (empty($cols) || !in_array('referencia', $cols, true)) return;

    $stmt = $pdo->prepare("
        SELECT idproducto,
               COALESCE(NULLIF(id_sucursal, 0), 1) AS id_sucursal,
               salida
        FROM {$db}.extracto_productos
        WHERE referencia = :ref
          AND COALESCE(salida, 0) > 0
    ");
    $stmt->execute([':ref' => tallerOtReferencia($idOrden)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $idProducto = (int)($row['idproducto'] ?? 0);
        $salida = (float)($row['salida'] ?? 0);
        if ($idProducto > 0 && $salida > 0 && tallerHasColumn($pdo, $db, 'tblproductos', 'saldo')) {
            $pdo->prepare("UPDATE {$db}.tblproductos SET saldo = COALESCE(saldo, 0) + :cant WHERE idproducto = :id")
                ->execute([':cant' => $salida, ':id' => $idProducto]);
        }
        tallerStockSnapshotAdjust(
            $pdo,
            $db,
            $idProducto,
            (int)($row['id_sucursal'] ?? 1),
            $salida
        );
    }
    $pdo->prepare("DELETE FROM {$db}.extracto_productos WHERE referencia = :ref")->execute([':ref' => tallerOtReferencia($idOrden)]);
}

function tallerApplyOtExtractoMovements(PDO $pdo, string $db, int $idOrden): void
{
    if ($idOrden <= 0 || !tallerHasTable($pdo, $db, 'extracto_productos')) return;
    $cols = tallerExtractoCols($pdo, $db);
    if (empty($cols) || !in_array('idproducto', $cols, true)) return;

    $stmtOrden = $pdo->prepare("
        SELECT o.id_orden, o.nro_ot, o.id_cliente, o.id_vehiculo,
               v.chapa,
               c.nombre AS cliente
        FROM {$db}.taller_ordenes o
        LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
        LEFT JOIN {$db}.taller_clientes c ON c.id_cliente = o.id_cliente
        WHERE o.id_orden = :id
        LIMIT 1
    ");
    $stmtOrden->execute([':id' => $idOrden]);
    $orden = $stmtOrden->fetch(PDO::FETCH_ASSOC);
    if (!$orden) return;

    $stmtItems = $pdo->prepare("
        SELECT id_producto, descripcion, cantidad, precio
        FROM {$db}.taller_orden_items
        WHERE id_orden = :id
          AND tipo = 'repuesto'
          AND id_producto IS NOT NULL
    ");
    $stmtItems->execute([':id' => $idOrden]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($items)) return;

    $idSucursal = (int)($_SESSION['id_sucursal'] ?? 1);
    $idLogin = (int)($_SESSION['id_login'] ?? 0);
    $referencia = tallerOtReferencia($idOrden);
    $obs = 'Aplicado a ' . (string)($orden['nro_ot'] ?? $referencia)
        . ' | Chapa: ' . (string)($orden['chapa'] ?? '-')
        . ' | Cliente: ' . (string)($orden['cliente'] ?? '-');

    foreach ($items as $item) {
        $idProducto = (int)($item['id_producto'] ?? 0);
        $cantidad = (float)($item['cantidad'] ?? 0);
        if ($idProducto <= 0 || $cantidad <= 0) continue;

        $stmtProd = $pdo->prepare("
            SELECT idproducto, cve_producto, desproducto
            FROM {$db}.tblproductos
            WHERE idproducto = :id
            LIMIT 1
        ");
        $stmtProd->execute([':id' => $idProducto]);
        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if (!$prod) continue;

        $fields = [];
        $placeholders = [];
        $params = [];
        $assign = static function (string $field, $value) use (&$fields, &$placeholders, &$params): void {
            $fields[] = $field;
            $placeholders[] = ':' . $field;
            $params[':' . $field] = $value;
        };

        $assign('idproducto', $idProducto);
        if (in_array('estado', $cols, true)) $assign('estado', 1);
        if (in_array('id_sucursal', $cols, true)) $assign('id_sucursal', $idSucursal);
        if (in_array('fecha', $cols, true)) $assign('fecha', date('Y-m-d H:i:s'));
        if (in_array('referencia', $cols, true)) $assign('referencia', $referencia);
        if (in_array('codigo', $cols, true)) $assign('codigo', (string)($prod['cve_producto'] ?? ''));
        if (in_array('descripcion', $cols, true)) $assign('descripcion', (string)($item['descripcion'] ?: ($prod['desproducto'] ?? '')));
        if (in_array('entrada', $cols, true)) $assign('entrada', 0);
        if (in_array('salida', $cols, true)) $assign('salida', $cantidad);
        if (in_array('obs', $cols, true)) $assign('obs', $obs);
        if (in_array('id_login', $cols, true)) $assign('id_login', $idLogin > 0 ? $idLogin : null);
        if (in_array('precio', $cols, true)) $assign('precio', (float)($item['precio'] ?? 0));
        if (in_array('costo', $cols, true)) $assign('costo', 0);
        if (in_array('tipo_documento', $cols, true)) $assign('tipo_documento', 98);

        $sql = "INSERT INTO {$db}.extracto_productos (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $pdo->prepare($sql)->execute($params);

        if (tallerHasColumn($pdo, $db, 'tblproductos', 'saldo')) {
            $pdo->prepare("UPDATE {$db}.tblproductos SET saldo = COALESCE(saldo, 0) - :cant WHERE idproducto = :id")
                ->execute([':cant' => $cantidad, ':id' => $idProducto]);
        }
        tallerStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, -1 * $cantidad);
    }
}
