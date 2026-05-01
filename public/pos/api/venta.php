<?php
/**
 * API de Ventas POS
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
ini_set('display_errors', '0');
ob_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ERROR | E_PARSE);

require_once __DIR__ . '/../../../lib/producto_stock_snapshot.php';

if (!function_exists('posStockSnapshotReady')) {
    function posStockSnapshotReady(PDO $pdo, string $db): bool
    {
        return sxProductoStockSnapshotReady($pdo, $db);
    }
}

if (!function_exists('posStockSnapshotAdjust')) {
    function posStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
    {
        sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
    }
}

try {
    require_once __DIR__ . '/../../../config/bootstrap.php';
    require_once __DIR__ . '/sifen_queue.php';
    require_once __DIR__ . '/schema_compat.php';
    require_once __DIR__ . '/push_notify_helper.php';
    // bootstrap.php activa E_ALL y display_errors=1; forzamos API limpia JSON.
    ini_set('display_errors', '0');
    error_reporting(E_ERROR | E_PARSE);

    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    $action = $_GET['action'] ?? '';

    // Fallback para mobile/webview: si la cookie de sesión no viaja,
    // permitir operación cuando el payload trae IDs válidos.
    $sessionOk = Session::isLoggedIn();
    $idEmpresaReq = (int)($data['id_empresa'] ?? 0);
    $idUsuarioReq = (int)($data['id_usuario'] ?? 0);
    if (!$sessionOk && ($idEmpresaReq <= 0 || $idUsuarioReq <= 0)) {
        throw new Exception('Sesión no válida');
    }

    if ($action === 'rollback_fe') {
        $id_empresa = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
        $id_factura = (int)($data['id_factura'] ?? 0);

        if ($id_factura <= 0 || $id_empresa <= 0) {
            throw new Exception('Datos inválidos para rollback FE');
        }

        $pdo = Database::getMasterConnection();
        $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
        $stmt->execute([$id_empresa]);
        $dbase = $stmt->fetchColumn();
        if (!$dbase) {
            throw new Exception('Empresa no encontrada');
        }

        $pdo->beginTransaction();
        try {
            $stmtFact = $pdo->prepare("SELECT * FROM $dbase.factura_ventas WHERE id_factura = :id LIMIT 1");
            $stmtFact->execute([':id' => $id_factura]);
            $fact = $stmtFact->fetch(PDO::FETCH_ASSOC);
            if (!$fact) {
                throw new Exception('Factura no encontrada para rollback');
            }

            $tipoDoc = (int)($fact['tipo_documento'] ?? 0);
            if ($tipoDoc !== 3) {
                throw new Exception('Rollback permitido solo para factura electrónica');
            }

            $estadoSifen = strtolower(trim((string)($fact['estado_sifen'] ?? '')));
            $protLote = trim((string)($fact['prot_cons_lote_sifen'] ?? ''));
            $protAut = trim((string)($fact['protocolo_autorizacion'] ?? ''));
            if ($protLote !== '' || $protAut !== '' || in_array($estadoSifen, ['aprobado', 'anulado'], true)) {
                throw new Exception('No se puede revertir: la FE ya fue procesada por SIFEN');
            }

            // Revertir stock y limpiar movimientos en extracto_productos (si existe)
            try {
                $stmtMov = $pdo->prepare("SELECT idproducto, id_sucursal, salida FROM $dbase.extracto_productos WHERE idfactura = :id AND salida > 0");
                $stmtMov->execute([':id' => $id_factura]);
                $movs = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($movs)) {
                    $stmtStock = $pdo->prepare("UPDATE $dbase.tblproductos SET saldo = saldo + :cant WHERE idproducto = :idp");
                    foreach ($movs as $m) {
                        $stmtStock->execute([
                            ':cant' => (float)($m['salida'] ?? 0),
                            ':idp' => (int)($m['idproducto'] ?? 0)
                        ]);
                        posStockSnapshotAdjust(
                            $pdo,
                            $dbase,
                            (int)($m['idproducto'] ?? 0),
                            (int)($m['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 1)),
                            (float)($m['salida'] ?? 0)
                        );
                    }
                }

                $pdo->prepare("DELETE FROM $dbase.extracto_productos WHERE idfactura = :id")
                    ->execute([':id' => $id_factura]);
            } catch (Exception $e) {
                // Tabla no existente o estructura distinta: continuar
            }

            // Limpiar detalle de factura si existe tabla
            $tablasDetalle = ['factura_ventas_items', 'factura_ventas_detalle', 'factura_venta_detalle', 'detalle_factura', 'facturas_detalle'];
            foreach ($tablasDetalle as $tablaDetalle) {
                try {
                    $stmtCols = $pdo->query("DESCRIBE $dbase.$tablaDetalle");
                    $cols = [];
                    while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                        $cols[] = strtolower($col['Field']);
                    }
                    if (in_array('id_factura', $cols, true)) {
                        $pdo->prepare("DELETE FROM $dbase.$tablaDetalle WHERE id_factura = :id")->execute([':id' => $id_factura]);
                    } elseif (in_array('idfactura', $cols, true)) {
                        $pdo->prepare("DELETE FROM $dbase.$tablaDetalle WHERE idfactura = :id")->execute([':id' => $id_factura]);
                    }
                } catch (Exception $e) {
                    continue;
                }
            }

            // Limpiar registro FE auxiliar si existe
            try {
                $pdo->prepare("DELETE FROM $dbase.fe WHERE id_factura = :id")->execute([':id' => $id_factura]);
            } catch (Exception $e) {
                // tabla fe puede no existir
            }

            try {
                $pdo->prepare("UPDATE $dbase.producto_series
                    SET estado = 1, fecha_salida = NULL, id_factura = NULL
                    WHERE id_factura = :id")
                    ->execute([':id' => $id_factura]);
            } catch (Exception $e) {
                // tabla producto_series puede no existir
            }

            // Eliminar factura principal
            $pdo->prepare("DELETE FROM $dbase.factura_ventas WHERE id_factura = :id")->execute([':id' => $id_factura]);

            $pdo->commit();
            invalidatePopularProductsCache($id_empresa, (int)($_SESSION['id_sucursal'] ?? 0));
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'message' => 'Venta electrónica revertida correctamente',
                'data' => ['id_factura' => $id_factura]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'request_price_override') {
        $id_empresa = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
        $id_usuario = (int)($data['id_usuario'] ?? Session::getIdLogin());
        $id_caja = (int)($data['id_caja'] ?? 0);
        $id_producto = (int)($data['id_producto'] ?? 0);
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        $precio_intentado = (float)($data['precio_intentado'] ?? 0);
        $precio_minimo = (float)($data['precio_minimo'] ?? 0);
        $origen = strtoupper(trim((string)($data['origen'] ?? 'POS')));
        $motivo = trim((string)($data['motivo'] ?? ''));

        if ($id_empresa <= 0 || $id_usuario <= 0) {
            throw new Exception('Datos inválidos para solicitar autorización');
        }
        if ($id_producto <= 0) {
            throw new Exception('Producto inválido');
        }
        if ($precio_minimo <= 0 || $precio_intentado >= $precio_minimo) {
            throw new Exception('No corresponde solicitud de precio especial');
        }

        $pdo = Database::getMasterConnection();
        $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
        $stmt->execute([$id_empresa]);
        $dbase = $stmt->fetchColumn();
        if (!$dbase) {
            throw new Exception('Empresa no encontrada');
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS $dbase.pos_price_override_approvals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
                id_empresa INT NOT NULL DEFAULT 0,
                id_caja INT NOT NULL DEFAULT 0,
                id_producto INT NOT NULL DEFAULT 0,
                descripcion_producto VARCHAR(160) NULL,
                precio_intentado DECIMAL(15,2) NOT NULL DEFAULT 0,
                precio_minimo DECIMAL(15,2) NOT NULL DEFAULT 0,
                solicitado_por_id_login INT NOT NULL DEFAULT 0,
                solicitado_por_login VARCHAR(80) NULL,
                aprobado_por_id_login INT NULL,
                aprobado_por_login VARCHAR(80) NULL,
                origen VARCHAR(30) NOT NULL DEFAULT 'POS',
                motivo VARCHAR(255) NULL,
                payload_json TEXT NULL,
                fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_resolucion DATETIME NULL,
                INDEX idx_estado_fecha (estado, fecha_solicitud),
                INDEX idx_producto_estado (id_producto, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $loginUsuario = trim((string)($_SESSION['username'] ?? $_SESSION['login'] ?? (string)$id_usuario));

        $stmtApproved = $pdo->prepare("
            SELECT id
            FROM $dbase.pos_price_override_approvals
            WHERE estado = 'APROBADO'
              AND id_producto = :id_producto
              AND solicitado_por_id_login = :id_login
              AND ABS(precio_intentado - :precio_int) < 0.0001
              AND ABS(precio_minimo - :precio_min) < 0.0001
              AND fecha_resolucion >= (NOW() - INTERVAL 30 MINUTE)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtApproved->execute([
            ':id_producto' => $id_producto,
            ':id_login' => $id_usuario,
            ':precio_int' => $precio_intentado,
            ':precio_min' => $precio_minimo,
        ]);
        $approvedId = (int)($stmtApproved->fetchColumn() ?: 0);
        if ($approvedId > 0) {
            $pdo->prepare("UPDATE $dbase.pos_price_override_approvals SET estado = 'EJECUTADO', fecha_resolucion = NOW() WHERE id = :id")
                ->execute([':id' => $approvedId]);
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'approved' => true,
                'id' => $approvedId,
                'message' => 'Precio especial autorizado por administrador.'
            ]);
            exit;
        }

        $stmtPending = $pdo->prepare("
            SELECT id
            FROM $dbase.pos_price_override_approvals
            WHERE estado = 'PENDIENTE'
              AND id_producto = :id_producto
              AND solicitado_por_id_login = :id_login
              AND ABS(precio_intentado - :precio_int) < 0.0001
              AND ABS(precio_minimo - :precio_min) < 0.0001
              AND fecha_solicitud >= (NOW() - INTERVAL 5 MINUTE)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtPending->execute([
            ':id_producto' => $id_producto,
            ':id_login' => $id_usuario,
            ':precio_int' => $precio_intentado,
            ':precio_min' => $precio_minimo,
        ]);
        $existing = $stmtPending->fetchColumn();
        if ($existing) {
            try {
                notifyCompanyAdmins(
                    $pdo,
                    Database::getMasterDbName(),
                    $id_empresa,
                    $id_usuario,
                    'warning',
                    'Precio especial pendiente',
                    sprintf(
                        '%s reenvia la solicitud de autorizar precio especial para "%s".',
                        $loginUsuario !== '' ? $loginUsuario : ('Usuario #' . $id_usuario),
                        substr($descripcion !== '' ? $descripcion : ('Producto #' . $id_producto), 0, 90)
                    ),
                    '/public/pos/autorizaciones.php',
                    true
                );
            } catch (Throwable $e) {
                error_log('POS Venta: no se pudo reenviar notificacion de precio especial: ' . $e->getMessage());
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo json_encode([
                'success' => true,
                'pending_approval' => true,
                'id' => (int)$existing,
                'message' => 'La solicitud pendiente fue reenviada al administrador.'
            ]);
            exit;
        }

        $payload = [
            'id_producto' => $id_producto,
            'descripcion' => $descripcion,
            'precio_intentado' => $precio_intentado,
            'precio_minimo' => $precio_minimo,
            'id_caja' => $id_caja,
            'id_empresa' => $id_empresa,
            'id_usuario' => $id_usuario,
            'origen' => $origen,
        ];

        $stmtIns = $pdo->prepare("
            INSERT INTO $dbase.pos_price_override_approvals
            (estado, id_empresa, id_caja, id_producto, descripcion_producto, precio_intentado, precio_minimo,
             solicitado_por_id_login, solicitado_por_login, origen, motivo, payload_json, fecha_solicitud)
            VALUES
            ('PENDIENTE', :id_empresa, :id_caja, :id_producto, :descripcion, :precio_intentado, :precio_minimo,
             :id_login, :login, :origen, :motivo, :payload, NOW())
        ");
        $stmtIns->execute([
            ':id_empresa' => $id_empresa,
            ':id_caja' => $id_caja,
            ':id_producto' => $id_producto,
            ':descripcion' => substr($descripcion, 0, 160),
            ':precio_intentado' => $precio_intentado,
            ':precio_minimo' => $precio_minimo,
            ':id_login' => $id_usuario,
            ':login' => substr($loginUsuario, 0, 80),
            ':origen' => substr($origen, 0, 30),
            ':motivo' => substr($motivo, 0, 255),
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $insertId = (int)$pdo->lastInsertId();

        try {
            notifyCompanyAdmins(
                $pdo,
                Database::getMasterDbName(),
                $id_empresa,
                $id_usuario,
                'warning',
                'Precio especial pendiente',
                sprintf(
                    '%s solicita autorizar precio especial para "%s".',
                    $loginUsuario !== '' ? $loginUsuario : ('Usuario #' . $id_usuario),
                    substr($descripcion !== '' ? $descripcion : ('Producto #' . $id_producto), 0, 90)
                ),
                '/public/pos/autorizaciones.php'
            );
        } catch (Throwable $e) {
            error_log('POS Venta: no se pudo notificar admins por precio especial: ' . $e->getMessage());
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode([
            'success' => true,
            'pending_approval' => true,
            'id' => $insertId,
            'message' => 'Solicitud enviada a administrador para autorizar precio especial.'
        ]);
        exit;
    }

    if ($action === 'request_item_delete') {
        $id_empresa = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
        $id_usuario = (int)($data['id_usuario'] ?? Session::getIdLogin());
        $id_caja = (int)($data['id_caja'] ?? 0);
        $id_producto = (int)($data['id_producto'] ?? 0);
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        $cantidad = (float)($data['cantidad'] ?? 0);
        $precio = (float)($data['precio'] ?? 0);
        $origen = strtoupper(trim((string)($data['origen'] ?? 'POS')));
        $motivo = trim((string)($data['motivo'] ?? 'Eliminar item del carrito'));

        if ($id_empresa <= 0 || $id_usuario <= 0) {
            throw new Exception('Datos inválidos para solicitar autorización');
        }
        if ($id_producto <= 0) {
            throw new Exception('Producto inválido');
        }

        $pdo = Database::getMasterConnection();
        $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
        $stmt->execute([$id_empresa]);
        $dbase = $stmt->fetchColumn();
        if (!$dbase) {
            throw new Exception('Empresa no encontrada');
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS $dbase.pos_item_delete_approvals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
                id_empresa INT NOT NULL DEFAULT 0,
                id_caja INT NOT NULL DEFAULT 0,
                id_producto INT NOT NULL DEFAULT 0,
                descripcion_producto VARCHAR(160) NULL,
                cantidad DECIMAL(15,3) NOT NULL DEFAULT 0,
                precio DECIMAL(15,2) NOT NULL DEFAULT 0,
                solicitado_por_id_login INT NOT NULL DEFAULT 0,
                solicitado_por_login VARCHAR(80) NULL,
                aprobado_por_id_login INT NULL,
                aprobado_por_login VARCHAR(80) NULL,
                origen VARCHAR(30) NOT NULL DEFAULT 'POS',
                motivo VARCHAR(255) NULL,
                payload_json TEXT NULL,
                fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_resolucion DATETIME NULL,
                INDEX idx_estado_fecha (estado, fecha_solicitud),
                INDEX idx_producto_estado (id_producto, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $loginUsuario = trim((string)($_SESSION['username'] ?? $_SESSION['login'] ?? (string)$id_usuario));

        $stmtApproved = $pdo->prepare("
            SELECT id, COALESCE(NULLIF(TRIM(aprobado_por_login), ''), '') AS aprobado_por_login
            FROM $dbase.pos_item_delete_approvals
            WHERE estado = 'APROBADO'
              AND id_producto = :id_producto
              AND solicitado_por_id_login = :id_login
              AND fecha_resolucion >= (NOW() - INTERVAL 30 MINUTE)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtApproved->execute([
            ':id_producto' => $id_producto,
            ':id_login' => $id_usuario,
        ]);
        $approvedRow = $stmtApproved->fetch(PDO::FETCH_ASSOC) ?: [];
        $approvedId = (int)($approvedRow['id'] ?? 0);
        $approvedByLogin = trim((string)($approvedRow['aprobado_por_login'] ?? ''));
        if ($approvedId > 0) {
            $pdo->prepare("UPDATE $dbase.pos_item_delete_approvals SET estado = 'EJECUTADO', fecha_resolucion = NOW() WHERE id = :id")
                ->execute([':id' => $approvedId]);
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'approved' => true,
                'id' => $approvedId,
                'approved_by_login' => $approvedByLogin,
                'message' => 'Eliminación de ítem autorizada por administrador.'
            ]);
            exit;
        }

        $stmtPending = $pdo->prepare("
            SELECT id
            FROM $dbase.pos_item_delete_approvals
            WHERE estado = 'PENDIENTE'
              AND id_producto = :id_producto
              AND solicitado_por_id_login = :id_login
              AND fecha_solicitud >= (NOW() - INTERVAL 5 MINUTE)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtPending->execute([
            ':id_producto' => $id_producto,
            ':id_login' => $id_usuario,
        ]);
        $existing = $stmtPending->fetchColumn();
        if ($existing) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'pending_approval' => true,
                'id' => (int)$existing,
                'message' => 'La solicitud pendiente fue reenviada al administrador.'
            ]);
            exit;
        }

        $payload = [
            'id_producto' => $id_producto,
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'precio' => $precio,
            'id_caja' => $id_caja,
            'id_empresa' => $id_empresa,
            'id_usuario' => $id_usuario,
            'origen' => $origen,
        ];

        $stmtIns = $pdo->prepare("
            INSERT INTO $dbase.pos_item_delete_approvals
            (estado, id_empresa, id_caja, id_producto, descripcion_producto, cantidad, precio,
             solicitado_por_id_login, solicitado_por_login, origen, motivo, payload_json, fecha_solicitud)
            VALUES
            ('PENDIENTE', :id_empresa, :id_caja, :id_producto, :descripcion, :cantidad, :precio,
             :id_login, :login, :origen, :motivo, :payload, NOW())
        ");
        $stmtIns->execute([
            ':id_empresa' => $id_empresa,
            ':id_caja' => $id_caja,
            ':id_producto' => $id_producto,
            ':descripcion' => substr($descripcion, 0, 160),
            ':cantidad' => $cantidad,
            ':precio' => $precio,
            ':id_login' => $id_usuario,
            ':login' => substr($loginUsuario, 0, 80),
            ':origen' => substr($origen, 0, 30),
            ':motivo' => substr($motivo, 0, 255),
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $insertId = (int)$pdo->lastInsertId();

        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode([
            'success' => true,
            'pending_approval' => true,
            'id' => $insertId,
            'message' => 'Solicitud enviada a administrador para eliminar ítem.'
        ]);
        exit;
    }

    if ($action === 'request_item_delete_status') {
        $id_empresa = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
        $id_usuario = (int)($data['id_usuario'] ?? Session::getIdLogin());
        $rawProductos = $data['id_productos'] ?? [];

        if ($id_empresa <= 0 || $id_usuario <= 0) {
            throw new Exception('Datos inválidos para consultar autorizaciones');
        }

        $idsProductos = [];
        if (is_array($rawProductos)) {
            foreach ($rawProductos as $rawId) {
                $idProducto = (int)$rawId;
                if ($idProducto > 0) {
                    $idsProductos[$idProducto] = $idProducto;
                }
            }
        }

        if (!$idsProductos) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['success' => true, 'items' => []]);
            exit;
        }

        $pdo = Database::getMasterConnection();
        $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
        $stmt->execute([$id_empresa]);
        $dbase = $stmt->fetchColumn();
        if (!$dbase) {
            throw new Exception('Empresa no encontrada');
        }

        $stTable = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = :db
              AND table_name = 'pos_item_delete_approvals'
        ");
        $stTable->execute([':db' => $dbase]);
        if ((int)$stTable->fetchColumn() <= 0) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['success' => true, 'items' => []]);
            exit;
        }

        $ids = array_values($idsProductos);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
            SELECT p.id_producto,
                   p.estado,
                   COALESCE(NULLIF(TRIM(p.aprobado_por_login), ''), '') AS aprobado_por_login
            FROM {$dbase}.pos_item_delete_approvals p
            INNER JOIN (
                SELECT id_producto, MAX(id) AS max_id
                FROM {$dbase}.pos_item_delete_approvals
                WHERE solicitado_por_id_login = ?
                  AND id_producto IN ($placeholders)
                  AND (
                      fecha_solicitud >= (NOW() - INTERVAL 2 HOUR)
                      OR fecha_resolucion >= (NOW() - INTERVAL 2 HOUR)
                  )
                GROUP BY id_producto
            ) latest
              ON latest.id_producto = p.id_producto
             AND latest.max_id = p.id
        ";
        $params = array_merge([$id_usuario], $ids);
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $items = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $idProducto = (int)($row['id_producto'] ?? 0);
            if ($idProducto <= 0) {
                continue;
            }
            $items[] = [
                'id_producto' => $idProducto,
                'estado' => strtoupper(trim((string)($row['estado'] ?? ''))),
                'approved_by_login' => trim((string)($row['aprobado_por_login'] ?? '')),
            ];
        }

        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }
    
    if (!$data) {
        throw new Exception('Datos de entrada inválidos');
    }
    
    $id_empresa = (int)($data['id_empresa'] ?? Session::getIdEmpresa());
    $id_caja = (int)($data['id_caja'] ?? 1);
    $id_usuario = (int)($data['id_usuario'] ?? Session::getIdLogin());
    $items = $data['items'] ?? [];
    $id_cliente = $data['id_cliente'] ?? null;
    $cliente_nombre = $data['cliente_nombre'] ?? 'SIN NOMBRE';
    $cliente_ruc = $data['cliente_ruc'] ?? '4444440-1';
    
    // Tipo de documento: el frontend envía 'doc_type' con valores string
    // Convertir a código numérico de la BD: 0=nota, 1=autoimpresa, 3=electrónica
    $doc_type_raw = strtolower(trim((string)($data['doc_type'] ?? $data['tipo_documento'] ?? 'comun')));
    $tipo_documento_map = [
        'comun'   => 0,   // Nota de Control (sin validez fiscal)
        'auto'    => 1,   // Factura Autoimpresa
        'electro' => 3,   // Factura Electrónica SIFEN
    ];
    $tipo_documento = $tipo_documento_map[$doc_type_raw] ?? (is_numeric($doc_type_raw) ? (int)$doc_type_raw : 0);
    
    // Forma de pago desde POS (prioridad: payment_method -> forma_pago -> payments[0].method)
    $forma_pago_raw = $data['payment_method'] ?? $data['forma_pago'] ?? ($data['payments'][0]['method'] ?? 'efectivo');
    $forma_pago_raw = strtolower(trim((string)$forma_pago_raw));
    $payments = $data['payments'] ?? [];
    $is_pending_flag = isPendingSalePayload($data, is_array($payments) ? $payments : []);
    if ($is_pending_flag) {
        // Forzar coherencia para que nunca se registre como cobrada antes de pasar por Caja.
        $forma_pago_raw = 'pendiente';
    }
    $forma_pago_map = [
        'efectivo' => 1,
        'tarjeta' => 2,
        'transferencia' => 3,
        'qr' => 4,
        'pix' => 4,
        'credito' => 5,
        'contado' => 1,
        'pendiente' => 1
    ];
    $forma_pago = $forma_pago_map[$forma_pago_raw] ?? (is_numeric($forma_pago_raw) ? (int)$forma_pago_raw : 1);
    $condicion_pago = ($forma_pago === 5) ? 'credito' : 'contado';
    $is_pendiente = ($forma_pago_raw === 'pendiente' || $is_pending_flag) ? 1 : 0;
    $medio_cobro = resolveInvoicePaymentMethod($payments, $forma_pago_raw, $forma_pago);
    $is_credit_sale = ($forma_pago === 5 && !$is_pendiente);
    if ($is_pendiente) {
        $medio_cobro = 'PENDIENTE';
    }
    $total = (float)($data['total'] ?? 0);
    $offlineSyncId = substr(trim((string)($data['offline_sync_id'] ?? $data['offline_uuid'] ?? '')), 0, 80);
    
    if (empty($items)) {
        throw new Exception('El carrito está vacío');
    }
    if ($is_credit_sale && (int)$id_cliente <= 0) {
        throw new Exception('Debe seleccionar un cliente para vender a crédito');
    }

    $pdo = Database::getMasterConnection();
    
    // Obtener dbase de la empresa
    $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
    $stmt->execute([$id_empresa]);
    $dbase = $stmt->fetchColumn();
    
    if (!$dbase) {
        throw new Exception('Empresa no encontrada');
    }
    ensurePosSchemaCompatibility($pdo, (string)$dbase);
    ensurePosOfflineSyncTable($pdo, (string)$dbase);

    if ($offlineSyncId !== '') {
        $existingOfflineSync = findPosOfflineSync($pdo, (string)$dbase, $offlineSyncId);
        if ($existingOfflineSync && (int)($existingOfflineSync['id_factura'] ?? 0) > 0) {
            $storedResponse = json_decode((string)($existingOfflineSync['response_json'] ?? ''), true);
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(is_array($storedResponse) && !empty($storedResponse) ? $storedResponse : [
                'success' => true,
                'message' => 'Venta ya sincronizada anteriormente',
                'data' => [
                    'id_factura' => (int)($existingOfflineSync['id_factura'] ?? 0)
                ],
                'deduplicated' => true
            ]);
            exit;
        }
    }

    $productoSeriesReady = false;
    try {
        $stmtSeries = $pdo->query("SHOW COLUMNS FROM $dbase.producto_series");
        $seriesCols = [];
        while ($col = $stmtSeries->fetch(PDO::FETCH_ASSOC)) {
            $seriesCols[] = strtolower((string)($col['Field'] ?? ''));
        }
        $productoSeriesReady = in_array('id', $seriesCols, true)
            && in_array('idproducto', $seriesCols, true)
            && in_array('serie', $seriesCols, true)
            && in_array('estado', $seriesCols, true);
    } catch (Throwable $e) {
        $productoSeriesReady = false;
    }

    $serialLocks = [];
    foreach ($items as $index => $item) {
        $itemProductId = (int)($item['id'] ?? $item['id_producto'] ?? 0);
        $usaSerial = (int)($item['usaserial'] ?? $item['usa_serial'] ?? $item['uses_serial'] ?? 0) === 1;
        $serialId = (int)($item['serial_id'] ?? 0);
        $serialCode = trim((string)($item['serial_code'] ?? ''));
        $cantidad = (float)($item['cantidad'] ?? 0);

        if (!$usaSerial) {
            continue;
        }
        if (!$productoSeriesReady) {
            throw new Exception('La gestión de seriales no está disponible para esta empresa.');
        }
        if ($itemProductId <= 0 || $serialId <= 0 || $serialCode === '') {
            throw new Exception('Falta seleccionar el serial/IMEI para uno de los productos.');
        }
        if (abs($cantidad - 1) > 0.0001) {
            throw new Exception('Los productos con serial/IMEI deben venderse de a una unidad por línea.');
        }
        if (isset($serialLocks[$serialId])) {
            throw new Exception('El mismo serial/IMEI no puede repetirse en la misma venta.');
        }

        $serialLocks[$serialId] = [
            'item_index' => $index,
            'idproducto' => $itemProductId,
            'serie' => $serialCode,
        ];
    }
    
    // Verificar estructura de la tabla factura_ventas
    $columnas = [];
    try {
        $stmt_cols = $pdo->query("DESCRIBE $dbase.factura_ventas");
        while ($col = $stmt_cols->fetch(PDO::FETCH_ASSOC)) {
            $columnas[] = strtolower($col['Field']);
        }
    } catch (Exception $e) {
        error_log("Error obteniendo columnas: " . $e->getMessage());
    }
    
    error_log("Columnas disponibles en factura_ventas: " . implode(', ', $columnas));
    
    $pdo->beginTransaction();
    
    try {
        // Calcular totales
        $subtotal = 0;
        $iva_5 = 0;
        $iva_10 = 0;
        $exentas = 0;
        
        foreach ($items as $item) {
            $precio = (float)$item['precio'];
            $cantidad = (float)$item['cantidad'];
            $tasa_iva = normalizeIvaRate($item['tasa_iva'] ?? 10);
            
            $item_total = $precio * $cantidad;
            $subtotal += $item_total;
            
            if ($tasa_iva == 10) {
                $iva_10 += round($item_total / 11, 0);
            } elseif ($tasa_iva == 5) {
                $iva_5 += round($item_total / 21, 0);
            } else {
                $exentas += $item_total;
            }
        }

        if (!empty($serialLocks)) {
            $stmtValidateSerial = $pdo->prepare("
                SELECT id
                FROM $dbase.producto_series
                WHERE id = :id
                  AND idproducto = :idproducto
                  AND serie = :serie
                  AND estado = 1
                LIMIT 1
            ");
            foreach ($serialLocks as $serialId => $serialMeta) {
                $stmtValidateSerial->execute([
                    ':id' => $serialId,
                    ':idproducto' => $serialMeta['idproducto'],
                    ':serie' => $serialMeta['serie'],
                ]);
                if (!$stmtValidateSerial->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception('El serial/IMEI seleccionado ya no está disponible: ' . $serialMeta['serie']);
                }
            }
        }
        
        $iva_total = $iva_5 + $iva_10;
        
        // Obtener secuencia base por id_factura (fallback general)
        $stmt = $pdo->query("SELECT COALESCE(MAX(id_factura), 0) + 1 as next_id FROM $dbase.factura_ventas");
        $next_id = (int)$stmt->fetchColumn();

        // Cargar configuración de caja para numeración fiscal
        $cajaCfg = null;
        try {
            $stmtCaja = $pdo->prepare("SELECT factura_1, factura_2, factura_3, timbrado FROM $dbase.cajas WHERE id_caja = :id LIMIT 1");
            $stmtCaja->execute([':id' => $id_caja]);
            $cajaCfg = $stmtCaja->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log("POS Venta: no se pudo leer configuración de caja {$id_caja}: " . $e->getMessage());
        }

        // Fuente oficial de timbrado fiscal: serproc1.habilitacion_sifen por empresa logada
        $timbradoHab = '';
        if (in_array($tipo_documento, [1, 3], true)) {
            try {
                $stmtHab = $pdo->prepare("
                    SELECT numero_timbrado, timbrado
                    FROM " . MASTER_DB . ".habilitacion_sifen
                    WHERE id_empresa = :id AND activo = 1
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmtHab->execute([':id' => $id_empresa]);
                $hab = $stmtHab->fetch(PDO::FETCH_ASSOC) ?: [];
                $timbradoHab = trim((string)($hab['numero_timbrado'] ?? $hab['timbrado'] ?? ''));
            } catch (Exception $e) {
                error_log("POS Venta: no se pudo leer habilitacion_sifen para empresa {$id_empresa}: " . $e->getMessage());
            }
        }

        // Generar número de factura
        // Documentos fiscales (autoimpresa/electrónica): usar prefijos de caja => 001-001-0000001
        // Otros tipos: formato interno => 001-0000001
        $nro_factura = str_pad($id_caja, 3, '0', STR_PAD_LEFT) . '-' . str_pad($next_id, 7, '0', STR_PAD_LEFT);
        $timbradoCaja = '';
        if (in_array($tipo_documento, [1, 3], true) && $cajaCfg) {
            $f1 = str_pad((string)($cajaCfg['factura_1'] ?? '001'), 3, '0', STR_PAD_LEFT);
            $f2 = str_pad((string)($cajaCfg['factura_2'] ?? '001'), 3, '0', STR_PAD_LEFT);
            $baseSeq = (int)($cajaCfg['factura_3'] ?? 0);
            $timbradoCaja = $timbradoHab !== '' ? $timbradoHab : trim((string)($cajaCfg['timbrado'] ?? ''));

            $sqlCount = "
                SELECT COUNT(*) 
                FROM $dbase.factura_ventas
                WHERE tipo_documento = :tipo_documento
                  AND SUBSTRING_INDEX(nro_factura, '-', 1) = :f1
                  AND SUBSTRING_INDEX(SUBSTRING_INDEX(nro_factura, '-', 2), '-', -1) = :f2
            ";
            $paramsCount = [':tipo_documento' => $tipo_documento, ':f1' => $f1, ':f2' => $f2];
            if (in_array('timbrado', $columnas) && $timbradoCaja !== '') {
                $sqlCount .= " AND timbrado = :timbrado";
                $paramsCount[':timbrado'] = $timbradoCaja;
            }

            $stmtCount = $pdo->prepare($sqlCount);
            $stmtCount->execute($paramsCount);
            $existingCount = (int)$stmtCount->fetchColumn();

            $nextSeq = $baseSeq + $existingCount + 1;
            $nro_factura = $f1 . '-' . $f2 . '-' . str_pad((string)$nextSeq, 7, '0', STR_PAD_LEFT);
        }
        
        // Construir SQL dinámicamente según las columnas existentes
        $campos_base = [];
        $valores_base = [];
        
        // Columnas obligatorias/comunes
        if (in_array('id_empresa', $columnas)) {
            $campos_base[] = 'id_empresa';
            $valores_base[] = $id_empresa;
        }
        
        if (in_array('id_caja', $columnas)) {
            $campos_base[] = 'id_caja';
            $valores_base[] = $id_caja;
        }
        
        // id_usuario o id_login (variantes)
        if (in_array('id_usuario', $columnas)) {
            $campos_base[] = 'id_usuario';
            $valores_base[] = $id_usuario;
        } elseif (in_array('id_login', $columnas)) {
            $campos_base[] = 'id_login';
            $valores_base[] = $id_usuario;
        } elseif (in_array('usuario', $columnas)) {
            $campos_base[] = 'usuario';
            $valores_base[] = $id_usuario;
        }
        
        // nro_factura o numero
        if (in_array('nro_factura', $columnas)) {
            $campos_base[] = 'nro_factura';
            $valores_base[] = $nro_factura;
        } elseif (in_array('numero', $columnas)) {
            $campos_base[] = 'numero';
            $valores_base[] = $nro_factura;
        }
        
        // fecha
        if (in_array('fecha', $columnas)) {
            $campos_base[] = 'fecha';
            $valores_base[] = date('Y-m-d H:i:s');
        }
        
        // total
        if (in_array('total', $columnas)) {
            $campos_base[] = 'total';
            $valores_base[] = $subtotal;
        } elseif (in_array('monto_total', $columnas)) {
            $campos_base[] = 'monto_total';
            $valores_base[] = $subtotal;
        }
        
        // tipo_documento
        if (in_array('tipo_documento', $columnas)) {
            $campos_base[] = 'tipo_documento';
            $valores_base[] = $tipo_documento;
        } elseif (in_array('tipo', $columnas)) {
            $campos_base[] = 'tipo';
            $valores_base[] = $tipo_documento;
        }
        
        // forma_pago (código numérico POS)
        if (in_array('forma_pago', $columnas)) {
            $campos_base[] = 'forma_pago';
            $valores_base[] = $forma_pago;
        }
        // timbrado (documentos fiscales, tomado de habilitación/caja)
        if (in_array('timbrado', $columnas) && !empty($timbradoCaja)) {
            $campos_base[] = 'timbrado';
            $valores_base[] = $timbradoCaja;
        }
        // condicion (texto contado/credito) para esquemas legacy
        if (in_array('condicion', $columnas)) {
            $campos_base[] = 'condicion';
            $valores_base[] = $condicion_pago;
        }
        // medio_cobro (texto) para compatibilidad con esquemas que lo exigen
        if (in_array('medio_cobro', $columnas)) {
            $campos_base[] = 'medio_cobro';
            $valores_base[] = $medio_cobro;
        }
        // pendiente (1 = factura pendiente de cobro)
        if (in_array('pendiente', $columnas)) {
            $campos_base[] = 'pendiente';
            $valores_base[] = $is_pendiente ? $subtotal : 0;
        }
        
        // cliente (nombre)
        if (in_array('cliente', $columnas)) {
            $campos_base[] = 'cliente';
            $valores_base[] = $cliente_nombre;
        } elseif (in_array('nombre_cliente', $columnas)) {
            $campos_base[] = 'nombre_cliente';
            $valores_base[] = $cliente_nombre;
        }
        
        // ruc_cliente o ruc
        if (in_array('ruc_cliente', $columnas)) {
            $campos_base[] = 'ruc_cliente';
            $valores_base[] = $cliente_ruc;
        } elseif (in_array('ruc', $columnas)) {
            $campos_base[] = 'ruc';
            $valores_base[] = $cliente_ruc;
        }
        
        // estado
        if (in_array('estado', $columnas)) {
            $campos_base[] = 'estado';
            $valores_base[] = 1;
        }
        
        // id_cliente
        if (in_array('id_cliente', $columnas) && $id_cliente) {
            $campos_base[] = 'id_cliente';
            $valores_base[] = $id_cliente;
        } elseif (in_array('idcliente', $columnas) && $id_cliente) {
            $campos_base[] = 'idcliente';
            $valores_base[] = $id_cliente;
        }
        
        // Columnas de IVA
        if (in_array('iva', $columnas)) {
            $campos_base[] = 'iva';
            $valores_base[] = $iva_total;
        }
        if (in_array('iva_10', $columnas)) {
            $campos_base[] = 'iva_10';
            $valores_base[] = $iva_10;
        }
        if (in_array('iva_5', $columnas)) {
            $campos_base[] = 'iva_5';
            $valores_base[] = $iva_5;
        }
        if (in_array('exentas', $columnas)) {
            $campos_base[] = 'exentas';
            $valores_base[] = $exentas;
        }
        if (in_array('gravadas_10', $columnas)) {
            $campos_base[] = 'gravadas_10';
            $valores_base[] = $subtotal - $exentas - ($iva_5 > 0 ? ($iva_5 * 21) : 0);
        }
        if (in_array('gravadas_5', $columnas)) {
            $campos_base[] = 'gravadas_5';
            $valores_base[] = $iva_5 > 0 ? ($iva_5 * 21) : 0;
        }
        
        // Verificar que tenemos campos para insertar
        if (empty($campos_base)) {
            throw new Exception('No se pudieron detectar las columnas de la tabla factura_ventas');
        }
        
        $placeholders = array_fill(0, count($campos_base), '?');
        
        $sql = "INSERT INTO $dbase.factura_ventas (" . implode(', ', $campos_base) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        error_log("SQL Insert: $sql");
        error_log("Valores: " . json_encode($valores_base));
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($valores_base);
        
        $id_factura = (int)$pdo->lastInsertId();
        if ($id_factura <= 0) {
            // Fallback para esquemas donde lastInsertId() no retorna id_factura correcto.
            $stmtId = $pdo->prepare("
                SELECT id_factura
                FROM $dbase.factura_ventas
                WHERE nro_factura = :nro
                ORDER BY id_factura DESC
                LIMIT 1
            ");
            $stmtId->execute([':nro' => $nro_factura]);
            $id_factura = (int)$stmtId->fetchColumn();
        }
        if ($id_factura <= 0) {
            throw new Exception('No se pudo resolver id_factura luego de insertar la venta');
        }
        
        // Verificar nombre de tabla de items
        $tabla_items = null;
        $tablas_posibles = ['factura_ventas_items', 'factura_ventas_detalle', 'factura_venta_detalle', 'detalle_factura', 'facturas_detalle'];
        
        foreach ($tablas_posibles as $tabla) {
            try {
                $pdo->query("SELECT 1 FROM $dbase.$tabla LIMIT 1");
                $tabla_items = $tabla;
                break;
            } catch (Exception $e) {
                continue;
            }
        }
        
        if ($tabla_items) {
            // Obtener columnas de la tabla de items
            $cols_items = [];
            try {
                $stmt_cols = $pdo->query("DESCRIBE $dbase.$tabla_items");
                while ($col = $stmt_cols->fetch(PDO::FETCH_ASSOC)) {
                    $cols_items[] = strtolower($col['Field']);
                }
            } catch (Exception $e) {
                error_log("Error obteniendo columnas items: " . $e->getMessage());
            }
            
            error_log("Columnas disponibles en $tabla_items: " . implode(', ', $cols_items));
            
            foreach ($items as $item) {
                $campos_item = [];
                $valores_item = [];
                
                // id_factura
                if (in_array('id_factura', $cols_items)) {
                    $campos_item[] = 'id_factura';
                    $valores_item[] = $id_factura;
                } elseif (in_array('idfactura', $cols_items)) {
                    $campos_item[] = 'idfactura';
                    $valores_item[] = $id_factura;
                }
                
            // id_producto
            $itemProductId = (int)($item['id'] ?? $item['id_producto'] ?? 0);
            if (in_array('id_producto', $cols_items)) {
                $campos_item[] = 'id_producto';
                $valores_item[] = $itemProductId;
            } elseif (in_array('idproducto', $cols_items)) {
                $campos_item[] = 'idproducto';
                $valores_item[] = $itemProductId;
            }
                
                // codigo
                if (in_array('codigo', $cols_items)) {
                    $campos_item[] = 'codigo';
                    $valores_item[] = $item['codigo'] ?? '';
                }
                
                // descripcion
                if (in_array('descripcion', $cols_items)) {
                    $campos_item[] = 'descripcion';
                    $valores_item[] = $item['descripcion'] ?? $item['producto_nombre'] ?? '';
                }
                
                // cantidad
                if (in_array('cantidad', $cols_items)) {
                    $campos_item[] = 'cantidad';
                    $valores_item[] = $item['cantidad'];
                }
                
                // precio
                if (in_array('precio', $cols_items)) {
                    $campos_item[] = 'precio';
                    $valores_item[] = $item['precio'];
                } elseif (in_array('precio_unitario', $cols_items)) {
                    $campos_item[] = 'precio_unitario';
                    $valores_item[] = $item['precio'];
                } elseif (in_array('precio_venta', $cols_items)) {
                    $campos_item[] = 'precio_venta';
                    $valores_item[] = $item['precio'];
                }
                
                // tasa_iva
                if (in_array('tasa_iva', $cols_items)) {
                    $campos_item[] = 'tasa_iva';
                    $valores_item[] = normalizeIvaRate($item['tasa_iva'] ?? 10);
                } elseif (in_array('iva', $cols_items)) {
                    $campos_item[] = 'iva';
                    $valores_item[] = normalizeIvaRate($item['tasa_iva'] ?? 10);
                }
                
                // subtotal
                if (in_array('subtotal', $cols_items)) {
                    $campos_item[] = 'subtotal';
                    $valores_item[] = $item['precio'] * $item['cantidad'];
                } elseif (in_array('total', $cols_items)) {
                    $campos_item[] = 'total';
                    $valores_item[] = $item['precio'] * $item['cantidad'];
                }
                
                if (!empty($campos_item)) {
                    $placeholders_item = array_fill(0, count($campos_item), '?');
                    $sql_item = "INSERT INTO $dbase.$tabla_items (" . implode(', ', $campos_item) . ") VALUES (" . implode(', ', $placeholders_item) . ")";
                    
                    $stmt = $pdo->prepare($sql_item);
                    $stmt->execute($valores_item);
                }
            }
        } else {
            // Fallback: insertar en extracto_productos (tabla principal de movimientos)
            error_log("Insertando items en extracto_productos (tabla principal)");
            $id_login = $_SESSION['id_login'] ?? 1;
            $idSucursalVenta = (int)($data['id_sucursal'] ?? $_SESSION['id_sucursal'] ?? 1);
            
            foreach ($items as $item) {
                $itemProductId = (int)($item['id'] ?? $item['id_producto'] ?? 0);
                if ($itemProductId <= 0) {
                    throw new Exception('Item inválido: falta id de producto');
                }
                $sqlExtracto = "INSERT INTO $dbase.extracto_productos
                    (id_sucursal, idproducto, entrada, salida, fecha, id_login, idfactura, codigo, descripcion, costo, precio)
                    SELECT 
                        :id_sucursal, 
                        p.idproducto, 
                        0, 
                        :cantidad, 
                        NOW(), 
                        :id_login, 
                        :id_factura,
                        p.cve_producto, 
                        p.desproducto,
                        COALESCE(mp.costo, 0),
                        :precio_venta
                    FROM $dbase.tblproductos p
                    LEFT JOIN $dbase.mercaderia_precio mp ON mp.codigo = p.idproducto AND mp.tipo = 1
                    WHERE p.idproducto = :idproducto";

                $stmtExtracto = $pdo->prepare($sqlExtracto);
                $stmtExtracto->execute([
                    ':idproducto' => $itemProductId,
                    ':id_sucursal' => $idSucursalVenta,
                    ':cantidad' => $item['cantidad'],
                    ':id_login' => $id_login,
                    ':id_factura' => $id_factura,
                    ':precio_venta' => $item['precio']
                ]);

                // Descontar stock
                if ($itemProductId > 0) {
                    $pdo->prepare("UPDATE $dbase.tblproductos SET saldo = saldo - :cant WHERE idproducto = :id")
                        ->execute([':cant' => $item['cantidad'], ':id' => $itemProductId]);
                    posStockSnapshotAdjust($pdo, $dbase, $itemProductId, $idSucursalVenta, -1 * (float)$item['cantidad']);
                }
            }
        }

        // Persistir siempre el detalle POS del cobro para reimpresión y trazabilidad.
        // extracto_caja conserva el movimiento de caja; factura_ventas_pagos conserva
        // los metadatos del pago (entrega, vuelto, referencias, etc.).
        if (!$is_pendiente) {
            savePosPayments($pdo, $dbase, $id_factura, $id_usuario, $payments, $forma_pago_raw, $subtotal);
        }

        if ($is_credit_sale) {
            $creditPlan = resolveCreditSalePlan($data, $payments, $subtotal);
            persistCreditSaleArtifacts(
                $pdo,
                $dbase,
                $id_factura,
                (int)$id_cliente,
                (string)$nro_factura,
                $id_usuario,
                (int)($data['id_sucursal'] ?? Session::get('id_sucursal', 1)),
                $subtotal,
                $creditPlan
            );
        }

        // Registrar cobro de la factura en extracto_caja (solo rol Vendedor Cajero),
        // referenciando el id_factura para trazabilidad en Mi Caja.
        if (!$is_pendiente) {
            registerSalePaymentsInCashLedger(
                $pdo,
                $dbase,
                $id_empresa,
                $id_caja,
                $id_usuario,
                $id_factura,
                (string)$nro_factura,
                $payments,
                (string)$forma_pago_raw,
                (float)$subtotal
            );
        }

        if (!empty($serialLocks)) {
            $stmtConsumeSerial = $pdo->prepare("
                UPDATE $dbase.producto_series
                SET estado = 0,
                    fecha_salida = NOW(),
                    id_factura = :id_factura
                WHERE id = :id
                  AND idproducto = :idproducto
                  AND serie = :serie
                  AND estado = 1
            ");
            foreach ($serialLocks as $serialId => $serialMeta) {
                $stmtConsumeSerial->execute([
                    ':id_factura' => $id_factura,
                    ':id' => $serialId,
                    ':idproducto' => $serialMeta['idproducto'],
                    ':serie' => $serialMeta['serie'],
                ]);
                if ($stmtConsumeSerial->rowCount() < 1) {
                    throw new Exception('No se pudo confirmar la salida del serial/IMEI: ' . $serialMeta['serie']);
                }
            }
        }
        
        $pdo->commit();

        $feQueued = false;
        $feQueueId = null;
        $feQueueWarning = null;

        // FE asíncrona: no bloquear cierre de caja.
        if ($tipo_documento === 3) {
            try {
                // Marcar estado local en factura_ventas
                if (in_array('estado_sifen', $columnas, true)) {
                    $stmtMarkSifen = $pdo->prepare("UPDATE $dbase.factura_ventas
                        SET estado_sifen = 'Guardado Local',
                            mensaje_sifen = 'Venta registrada. FE en cola para envío a SIFEN.'
                        WHERE id_factura = :id");
                    $stmtMarkSifen->execute([':id' => $id_factura]);
                }

                // Sincronizar estado en tabla fe (si existe fila)
                sifenQueueUpdateFeState(
                    $pdo,
                    $dbase,
                    $id_factura,
                    'Guardado Local',
                    'Venta registrada. FE en cola para envío a SIFEN.'
                );

                // Encolar en master
                $q = sifenQueueEnqueue($pdo, $id_empresa, $dbase, $id_factura, 'emitir', 3);
                $feQueued = (bool)($q['queued'] ?? false);
                $feQueueId = (int)($q['queue_id'] ?? 0);
            } catch (Throwable $eQueue) {
                // No romper venta por error de cola
                $feQueueWarning = $eQueue->getMessage();
                error_log("POS Venta FE queue warning: " . $eQueue->getMessage());
            }
        }

        // Refrescar "Productos Frecuentes" inmediatamente después de vender.
        invalidatePopularProductsCache($id_empresa, (int)($_SESSION['id_sucursal'] ?? 0));
        
        $responsePayload = [
            'success' => true,
            'message' => 'Venta registrada correctamente',
            'data' => [
                'id_factura' => $id_factura,
                'nro_factura' => $nro_factura,
                'total' => $subtotal,
                'iva' => $iva_total,
                'fecha' => date('Y-m-d H:i:s'),
                'cliente' => $cliente_nombre,
                'tipo_documento' => $tipo_documento,
                'medio_cobro' => $medio_cobro,
                'cdc' => null,
                'fe_queued' => $feQueued,
                'fe_queue_id' => $feQueueId
            ],
            'warning' => $feQueueWarning
        ];
        if ($offlineSyncId !== '') {
            savePosOfflineSync(
                $pdo,
                (string)$dbase,
                $offlineSyncId,
                $id_empresa,
                $id_caja,
                $id_usuario,
                $id_factura,
                is_array($data) ? $data : [],
                $responsePayload
            );
        }

        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode($responsePayload);
        
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('POS Venta Error: ' . $e->getMessage());

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}

function normalizeIvaRate($raw): int
{
    $v = (int)$raw;
    if ($v === 1 || $v === 0) return 0;   // Exenta
    if ($v === 2 || $v === 5) return 5;   // IVA 5%
    if ($v === 3 || $v === 10) return 10; // IVA 10%
    return 10;
}

function ensurePosOfflineSyncTable(PDO $pdo, string $dbase): void
{
    static $ready = [];
    if (isset($ready[$dbase])) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS $dbase.pos_offline_sync (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sync_id VARCHAR(80) NOT NULL,
            id_empresa INT NOT NULL DEFAULT 0,
            id_caja INT NOT NULL DEFAULT 0,
            id_usuario INT NOT NULL DEFAULT 0,
            id_factura INT NOT NULL DEFAULT 0,
            payload_json MEDIUMTEXT NULL,
            response_json MEDIUMTEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            synced_at DATETIME NULL,
            UNIQUE KEY uniq_sync_id (sync_id),
            KEY idx_factura (id_factura),
            KEY idx_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $ready[$dbase] = true;
}

function findPosOfflineSync(PDO $pdo, string $dbase, string $syncId): ?array
{
    if ($syncId === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT sync_id, id_factura, response_json, estado
        FROM $dbase.pos_offline_sync
        WHERE sync_id = :sync_id
        LIMIT 1
    ");
    $stmt->execute([':sync_id' => $syncId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function savePosOfflineSync(
    PDO $pdo,
    string $dbase,
    string $syncId,
    int $idEmpresa,
    int $idCaja,
    int $idUsuario,
    int $idFactura,
    array $payload,
    array $response
): void {
    if ($syncId === '') {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO $dbase.pos_offline_sync
            (sync_id, id_empresa, id_caja, id_usuario, id_factura, payload_json, response_json, estado, synced_at)
        VALUES
            (:sync_id, :id_empresa, :id_caja, :id_usuario, :id_factura, :payload_json, :response_json, 'PROCESADO', NOW())
        ON DUPLICATE KEY UPDATE
            id_empresa = VALUES(id_empresa),
            id_caja = VALUES(id_caja),
            id_usuario = VALUES(id_usuario),
            id_factura = VALUES(id_factura),
            payload_json = VALUES(payload_json),
            response_json = VALUES(response_json),
            estado = 'PROCESADO',
            synced_at = NOW()
    ");
    $stmt->execute([
        ':sync_id' => $syncId,
        ':id_empresa' => $idEmpresa,
        ':id_caja' => $idCaja,
        ':id_usuario' => $idUsuario,
        ':id_factura' => $idFactura,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function isPendingSalePayload(array $data, array $payments): bool
{
    $status = strtoupper(trim((string)($data['status'] ?? '')));
    if ($status === 'PENDIENTE' || $status === 'PENDING') {
        return true;
    }

    $pendingNotes = trim((string)($data['pendiente_notes'] ?? $data['pending_notes'] ?? ''));
    if ($pendingNotes !== '') {
        return true;
    }

    $flag = $data['is_pending'] ?? null;
    if ($flag !== null) {
        $flagStr = strtolower(trim((string)$flag));
        if ($flag === true || $flag === 1 || $flag === '1' || $flagStr === 'true' || $flagStr === 'yes' || $flagStr === 'si' || $flagStr === 'sí') {
            return true;
        }
    }

    $method = strtolower(trim((string)($data['payment_method'] ?? $data['forma_pago'] ?? '')));
    if ($method === 'pendiente') {
        return true;
    }

    foreach ($payments as $p) {
        if (!is_array($p)) continue;
        $m = strtolower(trim((string)($p['method'] ?? $p['payment_method'] ?? '')));
        if ($m === 'pendiente') return true;
        $rowFlag = $p['is_pending'] ?? null;
        if ($rowFlag === true || $rowFlag === 1 || $rowFlag === '1' || strtolower(trim((string)$rowFlag)) === 'true') {
            return true;
        }
        $rowStatus = strtoupper(trim((string)($p['status'] ?? '')));
        if ($rowStatus === 'PENDIENTE' || $rowStatus === 'PENDING') {
            return true;
        }
        $rowNotes = trim((string)($p['pendiente_notes'] ?? $p['pending_notes'] ?? ''));
        if ($rowNotes !== '') {
            return true;
        }
    }
    return false;
}

function resolveInvoicePaymentMethod(array $payments, string $fallbackRaw, int $formaPago): string
{
    $methods = [];
    if (!empty($payments) && is_array($payments)) {
        foreach ($payments as $p) {
            if (!is_array($p)) continue;
            $m = strtolower(trim((string)($p['method'] ?? $p['payment_method'] ?? '')));
            if ($m !== '') $methods[] = $m;
        }
    }
    if (empty($methods)) {
        $methods[] = strtolower(trim($fallbackRaw));
    }

    $normalized = [];
    foreach ($methods as $m) {
        if ($m === 'tarjeta') {
            $normalized[] = 'TARJETA';
        } elseif ($m === 'transferencia' || $m === 'transfer') {
            $normalized[] = 'TRANSFERENCIA';
        } elseif ($m === 'qr' || $m === 'pix') {
            $normalized[] = 'QR';
        } elseif ($m === 'credito' || $m === 'crédito') {
            $normalized[] = 'CREDITO';
        } elseif ($m === 'pendiente') {
            $normalized[] = 'PENDIENTE';
        } else {
            $normalized[] = 'EFECTIVO';
        }
    }

    $normalized = array_values(array_unique($normalized));
    if (count($normalized) > 1) {
        return 'MIXTO';
    }
    if (!empty($normalized)) {
        return $normalized[0];
    }

    // Fallback final por forma_pago
    if ($formaPago === 2) return 'TARJETA';
    if ($formaPago === 3) return 'TRANSFERENCIA';
    if ($formaPago === 4) return 'QR';
    if ($formaPago === 5) return 'CREDITO';
    return 'EFECTIVO';
}

function resolveCreditSalePlan(array $data, array $payments, float $total): array
{
    $creditRow = null;
    foreach ($payments as $payment) {
        if (!is_array($payment)) continue;
        $method = strtolower(trim((string)($payment['method'] ?? $payment['payment_method'] ?? '')));
        if ($method === 'credito' || $method === 'crédito') {
            $creditRow = $payment;
            break;
        }
    }

    $installments = max(1, (int)(
        $creditRow['credit_installments']
        ?? $creditRow['installments']
        ?? $data['credit_installments']
        ?? 1
    ));

    $dueDateRaw = trim((string)(
        $creditRow['credit_due_date']
        ?? $creditRow['due_date']
        ?? $data['credit_due_date']
        ?? ''
    ));
    $dueDate = normalizeCreditDueDate($dueDateRaw);
    if ($dueDate === '') {
        $dueDate = date('Y-m-d', strtotime('+30 days'));
    }

    return [
        'installments' => $installments,
        'due_date' => $dueDate,
        'notes' => trim((string)($creditRow['credit_notes'] ?? $data['credit_notes'] ?? '')),
        'interest_pct' => max(0, (float)($creditRow['credit_interest_pct'] ?? $data['credit_interest_pct'] ?? 0)),
        'mora_pct' => max(0, (float)($creditRow['credit_mora_pct'] ?? $data['credit_mora_pct'] ?? 0)),
        'grace_days' => max(0, (int)($creditRow['credit_grace_days'] ?? $data['credit_grace_days'] ?? 0)),
        'total' => max(0, (float)$total),
    ];
}

function normalizeCreditDueDate(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    $ts = strtotime($raw);
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}

function persistCreditSaleArtifacts(
    PDO $pdo,
    string $dbase,
    int $idFactura,
    int $idCliente,
    string $nroFactura,
    int $idUsuario,
    int $idSucursal,
    float $total,
    array $creditPlan
): void {
    if ($idFactura <= 0 || $idCliente <= 0 || $total <= 0) {
        throw new Exception('Datos inválidos para registrar venta a crédito');
    }

    ensureFacturaVentasCreditColumns($pdo, $dbase);
    $stmtFactura = $pdo->prepare("
        UPDATE $dbase.factura_ventas
        SET saldo = :saldo,
            pendiente = :pendiente,
            medio_cobro = 'CREDITO'
        WHERE id_factura = :id_factura
    ");
    $stmtFactura->execute([
        ':saldo' => $total,
        ':pendiente' => $total,
        ':id_factura' => $idFactura,
    ]);

    insertCreditClientLedgerEntry(
        $pdo,
        $dbase,
        $idFactura,
        $idCliente,
        $idUsuario,
        $total,
        $nroFactura,
        $creditPlan
    );

    insertCreditInstallments(
        $pdo,
        $dbase,
        $idFactura,
        $idCliente,
        $idUsuario,
        max(1, $idSucursal),
        $nroFactura,
        $creditPlan
    );
}

function ensureFacturaVentasCreditColumns(PDO $pdo, string $dbase): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $dbase.factura_ventas");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = strtolower((string)($row['Field'] ?? ''));
        }
        if (!in_array('saldo', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.factura_ventas ADD COLUMN saldo DECIMAL(15,2) NULL AFTER total");
        }
        if (!in_array('pendiente', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.factura_ventas ADD COLUMN pendiente DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER saldo");
        }
        if (!in_array('medio_cobro', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.factura_ventas ADD COLUMN medio_cobro VARCHAR(20) NULL AFTER condicion");
        }
        $pdo->exec("UPDATE $dbase.factura_ventas SET saldo = total WHERE saldo IS NULL");
    } catch (Throwable $e) {
        throw new Exception('No se pudo preparar factura_ventas para crédito: ' . $e->getMessage());
    }
}

function insertCreditClientLedgerEntry(
    PDO $pdo,
    string $dbase,
    int $idFactura,
    int $idCliente,
    int $idUsuario,
    float $total,
    string $nroFactura,
    array $creditPlan
): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $dbase.extracto_cliente");
    } catch (Throwable $e) {
        throw new Exception('Tabla extracto_cliente no disponible para ventas a crédito');
    }

    $available = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $available[] = strtolower((string)($row['Field'] ?? ''));
    }

    $concepto = 'Venta a credito Fact. ' . trim($nroFactura !== '' ? $nroFactura : ('#' . $idFactura));
    if (!empty($creditPlan['notes'])) {
        $concepto .= ' | ' . trim((string)$creditPlan['notes']);
    }
    $concepto = substr($concepto, 0, 255);

    $map = [
        'codigo' => $idCliente,
        'concepto' => $concepto,
        'debito' => $total,
        'credito' => 0,
        'fecha' => '__NOW__',
        'login' => $idUsuario,
        'id_login' => $idUsuario,
        'estado' => 1,
        'id_factura' => $idFactura,
        'referencia' => 41,
        'tabla_relacion' => 'factura_ventas',
        'id_relacion' => $idFactura,
        'medio_cobro' => 'CREDITO',
    ];

    $cols = [];
    $vals = [];
    $params = [];
    foreach ($map as $col => $value) {
        if (!in_array($col, $available, true)) continue;
        $cols[] = $col;
        if ($value === '__NOW__') {
            $vals[] = 'NOW()';
            continue;
        }
        $param = ':' . $col;
        $vals[] = $param;
        $params[$param] = $value;
    }

    if (empty($cols)) {
        throw new Exception('extracto_cliente no tiene columnas compatibles para registrar crédito');
    }

    $sql = "INSERT INTO $dbase.extracto_cliente (" . implode(', ', $cols) . ")
            VALUES (" . implode(', ', $vals) . ")";
    $stmtInsert = $pdo->prepare($sql);
    $stmtInsert->execute($params);
}

function insertCreditInstallments(
    PDO $pdo,
    string $dbase,
    int $idFactura,
    int $idCliente,
    int $idUsuario,
    int $idSucursal,
    string $nroFactura,
    array $creditPlan
): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $dbase.documentos");
    } catch (Throwable $e) {
        throw new Exception('Tabla documentos no disponible para ventas a crédito');
    }

    $available = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $available[] = strtolower((string)($row['Field'] ?? ''));
    }

    $installments = max(1, (int)($creditPlan['installments'] ?? 1));
    $dueDate = normalizeCreditDueDate((string)($creditPlan['due_date'] ?? ''));
    if ($dueDate === '') {
        $dueDate = date('Y-m-d', strtotime('+30 days'));
    }
    $graceDays = max(0, (int)($creditPlan['grace_days'] ?? 0));
    $moraPct = max(0, (float)($creditPlan['mora_pct'] ?? 0));
    $interestPct = max(0, (float)($creditPlan['interest_pct'] ?? 0));
    $baseAmount = max(0, (float)($creditPlan['total'] ?? 0));
    $totalInterest = $baseAmount * ($interestPct / 100);
    $totalFinanced = $baseAmount + $totalInterest;

    $totalBase = floor($totalFinanced / $installments);
    $capitalBase = floor($baseAmount / $installments);
    $interestBase = floor($totalInterest / $installments);
    $totalRemainder = $totalFinanced - ($totalBase * $installments);
    $capitalRemainder = $baseAmount - ($capitalBase * $installments);
    $interestRemainder = $totalInterest - ($interestBase * $installments);

    for ($i = 1; $i <= $installments; $i++) {
        $cuotaTotal = $totalBase;
        $cuotaCapital = $capitalBase;
        $cuotaInteres = $interestBase;
        if ($i === $installments) {
            $cuotaTotal += $totalRemainder;
            $cuotaCapital += $capitalRemainder;
            $cuotaInteres += $interestRemainder;
        }

        $fechaVencimiento = date('Y-m-d', strtotime($dueDate . ' + ' . (($i - 1) * 30) . ' days'));
        $cantidadCuota = $i . '/' . $installments;
        $concepto = 'Cuota ' . $cantidadCuota . ' - Fac. ' . trim($nroFactura !== '' ? $nroFactura : ('#' . $idFactura));

        $map = [
            'id_sucursal' => $idSucursal,
            'id_cliente' => $idCliente,
            'id_factura' => $idFactura,
            'fecha_creacion' => '__NOW__',
            'fecha_vencimiento' => $fechaVencimiento,
            'capital' => $cuotaCapital,
            'cantidad_cuota' => $cantidadCuota,
            'periodo_cuota' => 30,
            'int_capital' => $cuotaInteres,
            'int_moratorio' => 0,
            'total' => $cuotaTotal,
            'concepto' => substr($concepto, 0, 255),
            'id_login' => $idUsuario,
            'pagado' => 0,
            'pendiente' => $cuotaTotal,
            'estado' => 1,
            'inforcomf' => 0,
            'dias_gracia' => $graceDays,
            'porc_mora' => $moraPct,
        ];

        $cols = [];
        $vals = [];
        $params = [];
        foreach ($map as $col => $value) {
            if (!in_array($col, $available, true)) continue;
            $cols[] = $col;
            if ($value === '__NOW__') {
                $vals[] = 'NOW()';
                continue;
            }
            $param = ':' . $col . '_' . $i;
            $vals[] = $param;
            $params[$param] = $value;
        }

        if (empty($cols)) {
            throw new Exception('documentos no tiene columnas compatibles para registrar cuotas');
        }

        $sql = "INSERT INTO $dbase.documentos (" . implode(', ', $cols) . ")
                VALUES (" . implode(', ', $vals) . ")";
        $stmtInsert = $pdo->prepare($sql);
        $stmtInsert->execute($params);
    }
}

function savePosPayments(PDO $pdo, string $dbase, int $idFactura, int $idUsuario, array $payments, string $fallbackMethod, float $total): void
{
    $tablaPagos = ensurePosPaymentsTable($pdo, $dbase);
    if ($tablaPagos === '') {
        return;
    }

    $rows = [];
    if (!empty($payments) && is_array($payments)) {
        foreach ($payments as $p) {
            if (!is_array($p)) continue;
            $rows[] = $p;
        }
    }

    if (empty($rows)) {
        $rows[] = [
            'method' => $fallbackMethod !== '' ? $fallbackMethod : 'efectivo',
            'amount' => $total
        ];
    }

    $sql = "INSERT INTO $dbase.$tablaPagos (
                id_factura, id_usuario, metodo, monto, currency,
                cash_received, cash_change,
                voucher_number, transfer_reference, qr_transaction_code,
                card_terminal_reference, card_auth_code, card_nsu, card_acquirer,
                card_brand, card_masked_pan, card_rrn, card_batch,
                card_installments, card_financing_type, card_processor, card_type,
                card_capture_payload_json
            ) VALUES (
                :id_factura, :id_usuario, :metodo, :monto, :currency,
                :cash_received, :cash_change,
                :voucher_number, :transfer_reference, :qr_transaction_code,
                :card_terminal_reference, :card_auth_code, :card_nsu, :card_acquirer,
                :card_brand, :card_masked_pan, :card_rrn, :card_batch,
                :card_installments, :card_financing_type, :card_processor, :card_type,
                :card_capture_payload_json
            )";
    $stmt = $pdo->prepare($sql);

    foreach ($rows as $row) {
        $metodo = strtolower(trim((string)($row['method'] ?? $row['payment_method'] ?? $fallbackMethod ?: 'efectivo')));
        if ($metodo === '') $metodo = 'efectivo';
        $monto = (float)($row['amount'] ?? $row['monto'] ?? $total);
        if ($monto <= 0) $monto = $total;
        $pixTxid = '';

        if ($metodo === 'pix' || $metodo === 'qr') {
            $pixTxid = trim((string)($row['qr_transaction_code'] ?? $row['pix_reference'] ?? ''));
            if ($pixTxid === '') {
                throw new Exception('Referencia PIX requerida');
            }
            assertPixPaymentPaid($pdo, $dbase, $pixTxid, $monto);
        }

        $payload = [
            'raw' => $row
        ];

        $stmt->execute([
            ':id_factura' => $idFactura,
            ':id_usuario' => $idUsuario,
            ':metodo' => $metodo,
            ':monto' => $monto,
            ':currency' => strtoupper(trim((string)($row['currency'] ?? 'PYG'))),
            ':cash_received' => (float)($row['cash_received'] ?? 0),
            ':cash_change' => (float)($row['cash_change'] ?? 0),
            ':voucher_number' => trim((string)($row['voucher_number'] ?? '')),
            ':transfer_reference' => trim((string)($row['transfer_reference'] ?? '')),
            ':qr_transaction_code' => trim((string)($row['qr_transaction_code'] ?? $row['pix_reference'] ?? '')),
            ':card_terminal_reference' => trim((string)($row['card_terminal_reference'] ?? $row['reference'] ?? '')),
            ':card_auth_code' => trim((string)($row['card_auth_code'] ?? $row['auth_code'] ?? '')),
            ':card_nsu' => trim((string)($row['card_nsu'] ?? $row['nsu'] ?? '')),
            ':card_acquirer' => trim((string)($row['card_acquirer'] ?? $row['acquirer'] ?? '')),
            ':card_brand' => trim((string)($row['card_brand'] ?? $row['brand'] ?? '')),
            ':card_masked_pan' => trim((string)($row['card_masked_pan'] ?? $row['masked_pan'] ?? '')),
            ':card_rrn' => trim((string)($row['card_rrn'] ?? $row['rrn'] ?? '')),
            ':card_batch' => trim((string)($row['card_batch'] ?? $row['batch'] ?? '')),
            ':card_installments' => max(1, (int)($row['card_installments'] ?? $row['installments'] ?? 1)),
            ':card_financing_type' => trim((string)($row['card_financing_type'] ?? $row['financing_type'] ?? '')),
            ':card_processor' => trim((string)($row['card_processor'] ?? $row['processor'] ?? '')),
            ':card_type' => trim((string)($row['card_type'] ?? '')),
            ':card_capture_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        if ($pixTxid !== '') {
            try {
                $stmtPix = $pdo->prepare("UPDATE $dbase.pagos_pix
                    SET id_factura = :id_factura, updated_at = NOW()
                    WHERE txid = :txid");
                $stmtPix->execute([
                    ':id_factura' => $idFactura,
                    ':txid' => $pixTxid
                ]);
            } catch (Throwable $e) {
                // No romper venta por vínculo auxiliar.
            }
        }
    }
}

function registerSalePaymentsInCashLedger(
    PDO $pdo,
    string $dbase,
    int $idEmpresa,
    int $idCaja,
    int $idUsuario,
    int $idFactura,
    string $nroFactura,
    array $payments,
    string $fallbackMethod,
    float $total
): void {
    if ($idCaja <= 0 || $idFactura <= 0 || $idUsuario <= 0) return;

    // Regla dura: si la venta es pendiente, no registrar cobro en caja.
    $fallbackNorm = strtolower(trim((string)$fallbackMethod));
    if ($fallbackNorm === 'pendiente') return;

    $rows = [];
    if (!empty($payments) && is_array($payments)) {
        foreach ($payments as $p) {
            if (is_array($p)) $rows[] = $p;
        }
    }
    if (empty($rows)) {
        $rows[] = ['method' => $fallbackMethod !== '' ? $fallbackMethod : 'efectivo', 'amount' => $total];
    }

    $allow = ['efectivo', 'tarjeta', 'transferencia', 'transfer', 'pix', 'qr'];
    $inserted = 0;
    ensureExtractoCajaMedioCobroColumn($pdo, $dbase);
    $hasComprobante = false;
    $hasMedioCobro = false;
    try {
        $stmtCols = $pdo->query("DESCRIBE $dbase.extracto_caja");
        while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
            $f = strtolower((string)($col['Field'] ?? ''));
            if ($f === 'comprobante') $hasComprobante = true;
            if ($f === 'medio_cobro') $hasMedioCobro = true;
        }
    } catch (Throwable $e) {
        // Si no se puede leer estructura, usar modo conservador sin campos opcionales.
    }

    $cols = [
        'sucursal', 'codigo', 'concepto', 'debito', 'credito', 'fecha',
        'login', 'id_login', 'estado', 'moneda', 'cambio', 'operacion', 'referencia'
    ];
    $vals = [
        '1', ':id_caja', ':concepto', '0', ':credito', 'NOW()',
        ':login', ':id_login', '1', '1', '1', '2', '41'
    ];
    if ($hasComprobante) {
        $cols[] = 'comprobante';
        $vals[] = ':comprobante';
    }
    if ($hasMedioCobro) {
        $cols[] = 'medio_cobro';
        $vals[] = ':medio_cobro';
    }
    $cols[] = 'tabla_relacion';
    $vals[] = "'factura_ventas'";
    $cols[] = 'id_relacion';
    $vals[] = ':id_factura';

    $sql = "INSERT INTO $dbase.extracto_caja (" . implode(', ', $cols) . ")
            VALUES (" . implode(', ', $vals) . ")";
    $stmt = $pdo->prepare($sql);

    foreach ($rows as $row) {
        $methodRaw = strtolower(trim((string)($row['method'] ?? $row['payment_method'] ?? $fallbackMethod)));
        if ($methodRaw === 'pendiente') continue;
        if ($methodRaw === '') $methodRaw = 'efectivo';
        if (!in_array($methodRaw, $allow, true)) continue;

        $amount = (float)($row['amount'] ?? $row['monto'] ?? 0);
        if ($amount <= 0) continue;

        $medio = mapCashLedgerMethod($methodRaw);
        $comprobante = '';
        if ($methodRaw === 'tarjeta') $comprobante = (string)($row['voucher_number'] ?? '');
        if ($methodRaw === 'transferencia' || $methodRaw === 'transfer') $comprobante = (string)($row['transfer_reference'] ?? '');
        if ($methodRaw === 'pix' || $methodRaw === 'qr') $comprobante = (string)($row['qr_transaction_code'] ?? $row['pix_reference'] ?? '');

        $concepto = strtoupper($medio) . ' | Cobro Fact. ' . trim($nroFactura !== '' ? $nroFactura : (string)$idFactura);

        $params = [
            ':id_caja' => $idCaja,
            ':concepto' => substr($concepto, 0, 60),
            ':credito' => $amount,
            ':login' => $idUsuario,
            ':id_login' => $idUsuario,
            ':id_factura' => $idFactura
        ];
        if ($hasComprobante) $params[':comprobante'] = substr(trim($comprobante), 0, 20);
        if ($hasMedioCobro) $params[':medio_cobro'] = $medio;
        $stmt->execute($params);
        $inserted++;
    }

    // Si no hubo pagos válidos permitidos, registrar fallback contado estándar.
    if ($inserted === 0 && in_array(strtolower(trim($fallbackMethod)), $allow, true) && $total > 0) {
        $medio = mapCashLedgerMethod(strtolower(trim($fallbackMethod)));
        $concepto = strtoupper($medio) . ' | Cobro Fact. ' . trim($nroFactura !== '' ? $nroFactura : (string)$idFactura);
        $params = [
            ':id_caja' => $idCaja,
            ':concepto' => substr($concepto, 0, 60),
            ':credito' => $total,
            ':login' => $idUsuario,
            ':id_login' => $idUsuario,
            ':id_factura' => $idFactura
        ];
        if ($hasComprobante) $params[':comprobante'] = '';
        if ($hasMedioCobro) $params[':medio_cobro'] = $medio;
        $stmt->execute($params);
    }
}

function ensureExtractoCajaMedioCobroColumn(PDO $pdo, string $dbase): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $dbase.extracto_caja LIKE 'medio_cobro'");
        $exists = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);
        if ($exists) return;

        // Agregar la columna en esquemas legacy para unificar comportamiento de cobros por medio.
        $pdo->exec("ALTER TABLE $dbase.extracto_caja ADD COLUMN medio_cobro VARCHAR(20) NULL");
    } catch (Throwable $e) {
        // No interrumpir venta si no hay permisos DDL o la estructura no permite alter.
        error_log("POS Venta: no se pudo crear columna medio_cobro en $dbase.extracto_caja: " . $e->getMessage());
    }
}

function mapCashLedgerMethod(string $method): string
{
    $m = strtolower(trim($method));
    if ($m === 'tarjeta') return 'TARJETA';
    if ($m === 'transferencia' || $m === 'transfer') return 'TRANSFERENCIA';
    if ($m === 'pix' || $m === 'qr') return 'QR';
    return 'EFECTIVO';
}

function isUserVendedorCajero(PDO $pdo, int $idEmpresa, int $idUsuario): bool
{
    try {
        $stmt = $pdo->prepare("SELECT role FROM sec_users WHERE id_login = :id AND id_empresa = :emp LIMIT 1");
        $stmt->execute([':id' => $idUsuario, ':emp' => $idEmpresa]);
        $role = (string)($stmt->fetchColumn() ?: '');
        $norm = normalizeRoleString($role);
        return $norm === 'vendedor cajero' || $norm === 'vendedorcajero';
    } catch (Throwable $e) {
        return false;
    }
}

function normalizeRoleString(string $text): string
{
    $txt = trim(strtolower($text));
    if ($txt === '') return '';
    $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($conv !== false && $conv !== '') $txt = strtolower($conv);
    $txt = preg_replace('/[^a-z0-9 ]+/', ' ', $txt);
    $txt = preg_replace('/\s+/', ' ', $txt);
    return trim($txt);
}

function ensureUserNotificationsTable(PDO $pdo, string $masterDb): void
{
    $table = "{$masterDb}.smx_user_notifications";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$table} (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function notifyCompanyAdmins(
    PDO $pdo,
    string $masterDb,
    int $idEmpresa,
    int $idRemitenteLogin,
    string $tipo,
    string $titulo,
    string $mensaje,
    ?string $url = null,
    bool $forceDispatch = false
): void {
    if ($idEmpresa <= 0) {
        return;
    }

    ensureUserNotificationsTable($pdo, $masterDb);

    $stmtAdmins = $pdo->prepare("
        SELECT id_login, priv_admin, role
        FROM {$masterDb}.sec_users
        WHERE id_empresa = :id_empresa
          AND active = 'Y'
    ");
    $stmtAdmins->execute([':id_empresa' => $idEmpresa]);
    $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$admins) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO {$masterDb}.smx_user_notifications
        (id_empresa, id_dest_login, id_remitente_login, tipo, titulo, mensaje, url, leida, fecha_creacion)
        VALUES
        (:id_empresa, :id_dest_login, :id_remitente_login, :tipo, :titulo, :mensaje, :url, 0, NOW())
    ");
    $recent = $pdo->prepare("
        SELECT id
        FROM {$masterDb}.smx_user_notifications
        WHERE id_empresa = :id_empresa
          AND id_dest_login = :id_dest_login
          AND id_remitente_login = :id_remitente_login
          AND titulo = :titulo
          AND mensaje = :mensaje
          AND COALESCE(url, '') = COALESCE(:url, '')
          AND fecha_creacion >= (NOW() - INTERVAL 2 MINUTE)
        ORDER BY id DESC
        LIMIT 1
    ");

    $pushDestinations = [];

    foreach ($admins as $admin) {
        $idDest = (int)($admin['id_login'] ?? 0);
        if ($idDest <= 0) {
            continue;
        }
        $privAdmin = strtoupper(trim((string)($admin['priv_admin'] ?? 'N')));
        $role = strtoupper(trim((string)($admin['role'] ?? '')));
        $hasAdminPriv = in_array($privAdmin, ['Y', 'S', '1', 'TRUE'], true);
        $hasAdminRole = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
            || strpos($role, 'ADMIN') !== false
            || strpos($role, 'SUPERVIS') !== false;
        if (!$hasAdminPriv && !$hasAdminRole) {
            continue;
        }

        $params = [
            ':id_empresa' => $idEmpresa,
            ':id_dest_login' => $idDest,
            ':id_remitente_login' => $idRemitenteLogin,
            ':titulo' => substr($titulo, 0, 150),
            ':mensaje' => $mensaje,
            ':url' => $url,
        ];
        if (!$forceDispatch) {
            $recent->execute($params);
            if ($recent->fetchColumn()) {
                continue;
            }
        }
        $insert->execute($params + [
            ':tipo' => substr(trim($tipo) !== '' ? $tipo : 'info', 0, 20),
        ]);
        $pushDestinations[] = $idDest;
    }

    if (!empty($pushDestinations) && (smxPosPushEnabled() || smxMobileFcmEnabled())) {
        smxPosNotifyUserLoginsPush($pdo, $masterDb, $pushDestinations, [
            'title' => $titulo !== '' ? $titulo : 'Nueva autorización pendiente',
            'body' => $mensaje !== '' ? $mensaje : 'Tenés una solicitud pendiente en autorizaciones.',
            'url' => $url ?: '/public/pos/autorizaciones.php',
            'tag' => 'pos-auth-' . $idEmpresa . '-' . md5($titulo . '|' . $mensaje . '|' . (string)$idRemitenteLogin),
            'channel' => 'authorization_request',
            'peer_login' => $idRemitenteLogin,
            'icon' => '/public/assets/logo-192.png',
            'badge' => '/public/assets/logo-192.png',
            '_id_empresa' => $idEmpresa,
            '_id_remitente_login' => $idRemitenteLogin,
            '_web_push_extra_login_ids' => $idRemitenteLogin > 0 ? [$idRemitenteLogin] : [],
            '_fcm_extra_login_ids' => $idRemitenteLogin > 0 ? [$idRemitenteLogin] : [],
        ]);
    }
}

function invalidatePopularProductsCache(int $idEmpresa, int $idSucursal = 0): void
{
    if ($idEmpresa <= 0) return;
    $tmpDir = rtrim(sys_get_temp_dir(), '/\\');
    $patterns = [];
    if ($idSucursal > 0) {
        $patterns[] = $tmpDir . "/sistemax_popular_{$idEmpresa}_s{$idSucursal}_p*.json";
    }
    $patterns[] = $tmpDir . "/sistemax_popular_{$idEmpresa}_s*_p*.json";

    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if (!is_array($files) || empty($files)) continue;
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}

function assertPixPaymentPaid(PDO $pdo, string $dbase, string $txid, float $expectedAmount = 0): void
{
    try {
        $pdo->query("SELECT 1 FROM $dbase.pagos_pix LIMIT 1");
    } catch (Throwable $e) {
        throw new Exception('Tabla de pagos PIX no disponible');
    }

    $stmt = $pdo->prepare("SELECT txid, amount, status, expires_at
        FROM $dbase.pagos_pix
        WHERE txid = :txid
        ORDER BY id DESC
        LIMIT 1");
    $stmt->execute([':txid' => $txid]);
    $pix = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pix) {
        throw new Exception('Cobro PIX no encontrado para la referencia indicada');
    }

    $status = strtoupper(trim((string)($pix['status'] ?? '')));
    if ($status !== 'PAID') {
        throw new Exception('PIX pendiente: confirme el pago antes de cobrar');
    }

    $amount = (float)($pix['amount'] ?? 0);
    if ($expectedAmount > 0 && $amount > 0 && abs($amount - $expectedAmount) > 1.0) {
        throw new Exception('Monto PIX no coincide con el total de la venta');
    }
}

function ensurePosPaymentsTable(PDO $pdo, string $dbase): string
{
    $candidates = ['factura_ventas_pagos', 'factura_venta_pagos', 'pagos_factura_ventas'];
    foreach ($candidates as $table) {
        try {
            $pdo->query("SELECT 1 FROM $dbase.$table LIMIT 1");
            ensurePosPaymentsCashColumns($pdo, $dbase, $table);
            return $table;
        } catch (Throwable $e) {
            continue;
        }
    }

    $create = "CREATE TABLE IF NOT EXISTS $dbase.factura_ventas_pagos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_factura INT NOT NULL,
        id_usuario INT NULL,
        metodo VARCHAR(30) NOT NULL,
        monto DECIMAL(14,2) NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'PYG',
        voucher_number VARCHAR(120) NULL,
        transfer_reference VARCHAR(160) NULL,
        qr_transaction_code VARCHAR(160) NULL,
        card_terminal_reference VARCHAR(160) NULL,
        card_auth_code VARCHAR(80) NULL,
        card_nsu VARCHAR(80) NULL,
        card_acquirer VARCHAR(80) NULL,
        card_brand VARCHAR(80) NULL,
        card_masked_pan VARCHAR(40) NULL,
        card_rrn VARCHAR(80) NULL,
        card_batch VARCHAR(80) NULL,
        card_installments INT NOT NULL DEFAULT 1,
        card_financing_type VARCHAR(40) NULL,
        card_processor VARCHAR(40) NULL,
        card_type VARCHAR(40) NULL,
        card_capture_payload_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_factura (id_factura),
        KEY idx_metodo (metodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($create);
        ensurePosPaymentsCashColumns($pdo, $dbase, 'factura_ventas_pagos');
        return 'factura_ventas_pagos';
    } catch (Throwable $e) {
        error_log('No se pudo crear factura_ventas_pagos: ' . $e->getMessage());
        return '';
    }
}

function ensurePosPaymentsCashColumns(PDO $pdo, string $dbase, string $table): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $dbase.$table");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = strtolower((string)($row['Field'] ?? ''));
        }
        if (!in_array('cash_received', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.$table ADD COLUMN cash_received DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER currency");
        }
        if (!in_array('cash_change', $cols, true)) {
            $pdo->exec("ALTER TABLE $dbase.$table ADD COLUMN cash_change DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER cash_received");
        }
    } catch (Throwable $e) {
        error_log("No se pudo asegurar columnas cash_* en $dbase.$table: " . $e->getMessage());
    }
}
