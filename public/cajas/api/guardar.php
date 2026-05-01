<?php
/**
 * Cajas API - Guardar (Crear/Actualizar)
 * POST: { action: "create"|"update", caja: {...}, usuarios: [...] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

// Leer input una sola vez
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$action = $input['action'] ?? 'create';

// Verificar permisos según acción
if ($action === 'create') {
    Permission::requirePermission('app_grid_caja', 'priv_insert');
} else {
    Permission::requirePermission('app_grid_caja', 'priv_update');
}

$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$caja = $input['caja'] ?? [];
$usuariosAsignados = $input['usuarios'] ?? [];

function normalizeMetodoCobroPermitido($raw): string {
    $allowed = ['PENDIENTE', 'EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'QR', 'CREDITO'];
    $alias = [
        'PENDIENTE' => 'PENDIENTE',
        'PTE' => 'PENDIENTE',
        'TRANSFER' => 'TRANSFERENCIA',
        'TRANSFERENCIA' => 'TRANSFERENCIA',
        'PIX' => 'QR',
        'QR' => 'QR',
        'CREDITO' => 'CREDITO',
        'CRÉDITO' => 'CREDITO',
        'EFECTIVO' => 'EFECTIVO',
        'TARJETA' => 'TARJETA'
    ];
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = explode(',', (string)$raw);
    }
    $out = [];
    foreach ($parts as $p) {
        $v = strtoupper(trim((string)$p));
        if (isset($alias[$v])) {
            $v = $alias[$v];
        }
        if ($v !== '' && in_array($v, $allowed, true) && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    if (empty($out)) {
        $out = $allowed;
    }
    return implode(',', $out);
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $rawMetodoCobro = $caja['metodo_cobro_permitido'] ?? ($caja['metodos_cobro_permitidos'] ?? '');
    $metodoCobroPermitido = normalizeMetodoCobroPermitido($rawMetodoCobro);
    $impresoraInput = trim((string)($caja['impresora'] ?? ($caja['impresor'] ?? '')));
    $colImpresora = 'impresora';
    try {
        $stmtImpCol = $pdo->query("SHOW COLUMNS FROM {$db}.cajas");
        $colsImp = $stmtImpCol ? $stmtImpCol->fetchAll(PDO::FETCH_COLUMN, 0) : [];
        if (is_array($colsImp)) {
            if (in_array('impresora', $colsImp, true)) {
                $colImpresora = 'impresora';
            } elseif (in_array('impresor', $colsImp, true)) {
                $colImpresora = 'impresor';
            } else {
                $colImpresora = '';
            }
        }
    } catch (Exception $eColsImp) {
        $colImpresora = 'impresora';
    }
    $hasMetodoCobroPermitido = false;
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM {$db}.cajas LIKE 'metodo_cobro_permitido'");
        $hasMetodoCobroPermitido = (bool)$stmtCol->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $hasMetodoCobroPermitido = false;
    }
    if (!$hasMetodoCobroPermitido) {
        try {
            $pdo->exec("ALTER TABLE {$db}.cajas ADD COLUMN metodo_cobro_permitido VARCHAR(255) NULL AFTER impresora");
            $hasMetodoCobroPermitido = true;
        } catch (Exception $e) {
            $hasMetodoCobroPermitido = false;
        }
    }

    if (empty($caja['caja'])) {
        echo json_encode(['ok' => false, 'error' => 'El nombre de la caja es obligatorio']);
        exit;
    }

    $pdo->beginTransaction();

    if ($action === 'create') {
        $fields = "id_empresa, caja, tipo, id_sucursal, id_moneda, saldo_maximo, timbrado, vencimiento, fecha_inicio_timbrado, factura_1, factura_2, factura_3";
        $values = ":id_empresa, :caja, :tipo, :id_sucursal, :id_moneda, :saldo_maximo, :timbrado, :vencimiento, :fecha_inicio_timbrado, :f1, :f2, :f3";
        if ($colImpresora !== '') {
            $fields .= ", {$colImpresora}";
            $values .= ", :impresora";
        }
        if ($hasMetodoCobroPermitido) {
            $fields .= ", metodo_cobro_permitido";
            $values .= ", :metodo_cobro_permitido";
        }
        $sql = "INSERT INTO {$db}.cajas ({$fields}) VALUES ({$values})";
        $stmt = $pdo->prepare($sql);
        $params = [
            ':id_empresa'           => $id_empresa,
            ':caja'                 => trim($caja['caja']),
            ':tipo'                 => (int)($caja['tipo'] ?? 1),
            ':id_sucursal'          => (int)($caja['id_sucursal'] ?? 1),
            ':id_moneda'            => (int)($caja['id_moneda'] ?? 1),
            ':saldo_maximo'         => (float)($caja['saldo_maximo'] ?? 50000000),
            ':timbrado'             => trim($caja['timbrado'] ?? ''),
            ':vencimiento'          => $caja['vencimiento'] ?? null,
            ':fecha_inicio_timbrado'=> $caja['fecha_inicio_timbrado'] ?? null,
            ':f1'                   => (int)($caja['factura_1'] ?? 1),
            ':f2'                   => (int)($caja['factura_2'] ?? 1),
            ':f3'                   => (int)($caja['factura_3'] ?? 1),
        ];
        if ($colImpresora !== '') {
            $params[':impresora'] = $impresoraInput;
        }
        if ($hasMetodoCobroPermitido) {
            $params[':metodo_cobro_permitido'] = $metodoCobroPermitido;
        }
        $stmt->execute($params);
        $id_caja = $pdo->lastInsertId();
        $msg = 'Caja creada exitosamente';

    } else {
        $id_caja = (int)($caja['id_caja'] ?? 0);
        if ($id_caja <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID de caja inválido']);
            exit;
        }

        $sql = "UPDATE {$db}.cajas SET 
                    caja = :caja, tipo = :tipo, id_sucursal = :id_sucursal, id_moneda = :id_moneda,
                    saldo_maximo = :saldo_maximo, timbrado = :timbrado, vencimiento = :vencimiento,
                    fecha_inicio_timbrado = :fecha_inicio_timbrado,
                    factura_1 = :f1, factura_2 = :f2, factura_3 = :f3";
        if ($colImpresora !== '') {
            $sql .= ", {$colImpresora} = :impresora";
        }
        if ($hasMetodoCobroPermitido) {
            $sql .= ", metodo_cobro_permitido = :metodo_cobro_permitido";
        }
        $sql .= "
                WHERE id_caja = :id_caja";
        $stmt = $pdo->prepare($sql);
        $params = [
            ':caja'                 => trim($caja['caja']),
            ':tipo'                 => (int)($caja['tipo'] ?? 1),
            ':id_sucursal'          => (int)($caja['id_sucursal'] ?? 1),
            ':id_moneda'            => (int)($caja['id_moneda'] ?? 1),
            ':saldo_maximo'         => (float)($caja['saldo_maximo'] ?? 50000000),
            ':timbrado'             => trim($caja['timbrado'] ?? ''),
            ':vencimiento'          => $caja['vencimiento'] ?? null,
            ':fecha_inicio_timbrado'=> $caja['fecha_inicio_timbrado'] ?? null,
            ':f1'                   => (int)($caja['factura_1'] ?? 1),
            ':f2'                   => (int)($caja['factura_2'] ?? 1),
            ':f3'                   => (int)($caja['factura_3'] ?? 1),
            ':id_caja'              => $id_caja,
        ];
        if ($colImpresora !== '') {
            $params[':impresora'] = $impresoraInput;
        }
        if ($hasMetodoCobroPermitido) {
            $params[':metodo_cobro_permitido'] = $metodoCobroPermitido;
        }
        $stmt->execute($params);
        $msg = 'Caja actualizada exitosamente';
    }

    // Actualizar usuarios asignados y sincronizar sec_users.caja_def (usado por POS)
    if ($id_caja > 0) {
        try {
            $usuariosPrevios = [];
            $stmtPrev = $pdo->prepare("SELECT id_login FROM {$db}.cajas_usuarios WHERE id_caja = ?");
            $stmtPrev->execute([$id_caja]);
            $usuariosPrevios = array_map('intval', $stmtPrev->fetchAll(PDO::FETCH_COLUMN));

            // Eliminar asignaciones existentes
            $pdo->prepare("DELETE FROM {$db}.cajas_usuarios WHERE id_caja = ?")->execute([$id_caja]);
            
            // Insertar nuevas asignaciones
            $usuariosNuevos = [];
            if (!empty($usuariosAsignados)) {
                $stmtIns = $pdo->prepare("INSERT INTO {$db}.cajas_usuarios (id_caja, id_login) VALUES (?, ?)");
                foreach ($usuariosAsignados as $idLogin) {
                    $idLogin = (int)$idLogin;
                    if ($idLogin <= 0) continue;
                    $stmtIns->execute([$id_caja, $idLogin]);
                    $usuariosNuevos[] = $idLogin;
                }
            }

            $usuariosNuevos = array_values(array_unique($usuariosNuevos));
            $usuariosPrevios = array_values(array_unique($usuariosPrevios));
            $usuariosDesasignados = array_values(array_diff($usuariosPrevios, $usuariosNuevos));

            // Sincronizar caja por defecto del usuario en master (POS lee sec_users.caja_def)
            $masterPdo = Database::getMasterConnection();
            $cols = [];
            try {
                $stmtCols = $masterPdo->query("SHOW COLUMNS FROM sec_users");
                foreach (($stmtCols ? $stmtCols->fetchAll(PDO::FETCH_ASSOC) : []) as $col) {
                    $cols[(string)($col['Field'] ?? '')] = true;
                }
            } catch (Exception $eCols) {
                $cols = [];
            }

            $setAssigned = [];
            if (!empty($cols['caja_def'])) $setAssigned[] = "caja_def = ?";
            if (!empty($cols['id_caja'])) $setAssigned[] = "id_caja = ?";
            if (!empty($setAssigned)) {
                if (!empty($usuariosNuevos)) {
                    $ph = implode(',', array_fill(0, count($usuariosNuevos), '?'));
                    $sqlAssign = "UPDATE sec_users SET " . implode(', ', $setAssigned) . " WHERE id_empresa = ? AND id_login IN ({$ph})";
                    $stmtAssign = $masterPdo->prepare($sqlAssign);
                    $paramsAssign = [$id_caja, $id_caja, $id_empresa];
                    if (count($setAssigned) === 1) {
                        $paramsAssign = [$id_caja, $id_empresa];
                    }
                    foreach ($usuariosNuevos as $uid) $paramsAssign[] = (int)$uid;
                    $stmtAssign->execute($paramsAssign);
                }

                if (!empty($usuariosDesasignados)) {
                    $setUnassign = [];
                    if (!empty($cols['caja_def'])) $setUnassign[] = "caja_def = 0";
                    if (!empty($cols['id_caja'])) $setUnassign[] = "id_caja = 0";
                    if (!empty($setUnassign)) {
                        $ph2 = implode(',', array_fill(0, count($usuariosDesasignados), '?'));
                        $whereCaja = !empty($cols['caja_def']) ? " AND caja_def = ?" : (!empty($cols['id_caja']) ? " AND id_caja = ?" : "");
                        $sqlUnassign = "UPDATE sec_users SET " . implode(', ', $setUnassign) . " WHERE id_empresa = ? AND id_login IN ({$ph2}){$whereCaja}";
                        $stmtUnassign = $masterPdo->prepare($sqlUnassign);
                        $paramsUnassign = [$id_empresa];
                        foreach ($usuariosDesasignados as $uid) $paramsUnassign[] = (int)$uid;
                        if ($whereCaja !== '') $paramsUnassign[] = $id_caja;
                        $stmtUnassign->execute($paramsUnassign);
                    }
                }
            }
        } catch (Exception $e) {
            // cajas_usuarios puede no existir en todas las empresas
            error_log("Cajas: Error asignando usuarios: " . $e->getMessage());
        }
    }

    $pdo->commit();

    $impresoraGuardada = '';
    if ($colImpresora !== '') {
        try {
            $stmtCheck = $pdo->prepare("SELECT {$colImpresora} FROM {$db}.cajas WHERE id_caja = ? LIMIT 1");
            $stmtCheck->execute([(int)$id_caja]);
            $impresoraGuardada = (string)($stmtCheck->fetchColumn() ?: '');
        } catch (Exception $e) {
            $impresoraGuardada = '';
        }
    }

    echo json_encode(['ok' => true, 'msg' => $msg, 'id_caja' => $id_caja, 'impresora_guardada' => $impresoraGuardada]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
