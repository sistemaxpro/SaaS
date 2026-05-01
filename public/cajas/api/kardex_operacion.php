<?php
/**
 * Cajas API - Registrar Operación en Kardex (extracto_caja)
 * POST: Registra una entrada o salida en la caja
 * 
 * Tipos de operación:
 *  - salida_contacto:    Salida con ref. Contacto
 *  - entrada_contacto:   Entrada con ref. Contacto
 *  - salida_caja:        Salida de caja a caja (transferencia)
 *  - entrada_caja:       Entrada de caja a caja (transferencia)
 *  - salida_cuenta:      Salida con ref. Cuenta
 *  - entrada_cuenta:     Entrada con ref. Cuenta
 *  - salida_banco:       Salida con ref. Banco
 *  - entrada_banco:      Entrada con ref. Banco
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_caja');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validaciones básicas
$tipo_op   = trim($input['tipo_operacion'] ?? '');
$id_caja   = (int)($input['id_caja'] ?? 0);
$importe   = (float)($input['importe'] ?? 0);
$concepto  = trim($input['concepto'] ?? '');
$medio     = trim($input['medio_cobro'] ?? 'EFECTIVO');
$ref_id    = (int)($input['referencia_id'] ?? 0);
$ref_nombre = trim($input['referencia_nombre'] ?? '');
$comprobante = trim($input['comprobante'] ?? '');

// Operaciones válidas
$ops_validas = [
    'salida_contacto', 'entrada_contacto',
    'salida_caja', 'entrada_caja',
    'salida_cuenta', 'entrada_cuenta',
    'salida_banco', 'entrada_banco'
];

if (!in_array($tipo_op, $ops_validas)) {
    echo json_encode(['ok' => false, 'error' => 'Tipo de operación no válido']);
    exit;
}

if ($id_caja <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID de caja requerido']);
    exit;
}

if ($importe <= 0) {
    echo json_encode(['ok' => false, 'error' => 'El importe debe ser mayor a 0']);
    exit;
}

if ($concepto === '') {
    echo json_encode(['ok' => false, 'error' => 'El concepto es obligatorio']);
    exit;
}

$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login   = (int)($_SESSION['id_login'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 1);

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db  = $conn['dbName'];

    // Verificar que la caja existe
    $stmtC = $pdo->prepare("SELECT id_caja, caja, id_sucursal FROM {$db}.cajas WHERE id_caja = ?");
    $stmtC->execute([$id_caja]);
    $caja = $stmtC->fetch();
    if (!$caja) {
        echo json_encode(['ok' => false, 'error' => 'Caja no encontrada']);
        exit;
    }

    $sucursal = (int)($caja['id_sucursal'] ?? $id_sucursal);

    // Determinar si es crédito (entrada) o débito (salida)
    $es_entrada = strpos($tipo_op, 'entrada') !== false;
    $credito = $es_entrada ? $importe : 0;
    $debito  = $es_entrada ? 0 : $importe;

    // Determinar campos de relación según tipo
    $tabla_relacion    = null;
    $extracto_relacion = null;
    $codigo_relacion   = null;
    $id_cuenta_ref     = null;
    $beneficiario_val  = $ref_nombre ?: null;

    // Mapeo de operaciones a código de operación y referencia
    $op_code = 2; // Operación de caja genérica
    $ref_code = $es_entrada ? 16 : 15; // 16=Cobro varios, 15=Pago varios

    switch ($tipo_op) {
        case 'salida_contacto':
        case 'entrada_contacto':
            $op_code = 5;
            $tabla_relacion = 'clientes';
            $codigo_relacion = $ref_id > 0 ? $ref_id : null;
            $ref_code = $es_entrada ? 16 : 15;
            break;

        case 'salida_caja':
        case 'entrada_caja':
            $op_code = 8; // Transferencia
            $extracto_relacion = 'extracto_caja';
            $codigo_relacion = $ref_id > 0 ? $ref_id : null;
            $ref_code = $es_entrada ? 16 : 15;
            break;

        case 'salida_cuenta':
        case 'entrada_cuenta':
            $op_code = 6;
            $tabla_relacion = 'cuentas';
            $id_cuenta_ref = $ref_id > 0 ? $ref_id : null;
            $codigo_relacion = $ref_id > 0 ? $ref_id : null;
            $ref_code = $es_entrada ? 16 : 15;
            break;

        case 'salida_banco':
        case 'entrada_banco':
            $op_code = 7;
            $tabla_relacion = 'bancos';
            $codigo_relacion = $ref_id > 0 ? $ref_id : null;
            $ref_code = $es_entrada ? 16 : 15;
            break;
    }

    // INSERT en extracto_caja
    $sql = "INSERT INTO {$db}.extracto_caja (
                sucursal, operacion, codigo, concepto, descripcion,
                medio_cobro, credito, debito, saldo, login,
                comprobante, beneficiario, fecha, cantidad, moneda, cambio,
                referencia,
                tabla_relacion, extracto_relacion, codigo_relacion,
                id_cuenta_referencia, estado, conciliado
            ) VALUES (
                :sucursal, :operacion, :codigo, :concepto, :descripcion,
                :medio_cobro, :credito, :debito, 0, :login,
                :comprobante, :beneficiario, NOW(), :cantidad, 1, 1,
                :referencia,
                :tabla_relacion, :extracto_relacion, :codigo_relacion,
                :id_cuenta_referencia, 1, 0
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sucursal'             => $sucursal,
        ':operacion'            => $op_code,
        ':codigo'               => $id_caja,
        ':concepto'             => mb_substr($concepto, 0, 60),
        ':descripcion'          => mb_substr($ref_nombre, 0, 80) ?: null,
        ':medio_cobro'          => mb_substr($medio, 0, 15),
        ':credito'              => $credito,
        ':debito'               => $debito,
        ':login'                => $id_login,
        ':comprobante'          => $comprobante ?: null,
        ':beneficiario'         => $beneficiario_val,
        ':cantidad'             => $importe,
        ':referencia'           => $ref_code,
        ':tabla_relacion'       => $tabla_relacion,
        ':extracto_relacion'    => $extracto_relacion,
        ':codigo_relacion'      => $codigo_relacion,
        ':id_cuenta_referencia' => $id_cuenta_ref,
    ]);

    $newId = $pdo->lastInsertId();

    // Si es transferencia caja a caja, crear el registro espejo en la otra caja
    if (in_array($tipo_op, ['salida_caja', 'entrada_caja']) && $ref_id > 0) {
        // Verificar que la caja destino/origen existe
        $stmtRef = $pdo->prepare("SELECT id_caja, caja, id_sucursal FROM {$db}.cajas WHERE id_caja = ?");
        $stmtRef->execute([$ref_id]);
        $cajaRef = $stmtRef->fetch();

        if ($cajaRef) {
            $credito_espejo = $es_entrada ? 0 : $importe;
            $debito_espejo  = $es_entrada ? $importe : 0;
            $concepto_espejo = ($es_entrada ? 'Salida' : 'Entrada') . ' | Transf. de ' . $caja['caja'] . ' | ' . $concepto;

            $stmtE = $pdo->prepare("INSERT INTO {$db}.extracto_caja (
                sucursal, operacion, codigo, concepto, descripcion,
                medio_cobro, credito, debito, saldo, login,
                comprobante, beneficiario, fecha, cantidad, moneda, cambio,
                referencia,
                tabla_relacion, extracto_relacion, codigo_relacion,
                id_extracto_ref, extracto_ref, estado, conciliado
            ) VALUES (
                :sucursal, 8, :codigo, :concepto, :descripcion,
                :medio_cobro, :credito, :debito, 0, :login,
                :comprobante, :beneficiario, NOW(), :cantidad, 1, 1,
                :referencia,
                NULL, 'extracto_caja', :codigo_relacion,
                :id_extracto_ref, 'extracto_caja', 1, 0
            )");

            $stmtE->execute([
                ':sucursal'          => (int)$cajaRef['id_sucursal'],
                ':codigo'            => $ref_id,
                ':concepto'          => mb_substr($concepto_espejo, 0, 60),
                ':descripcion'       => mb_substr($caja['caja'], 0, 80),
                ':medio_cobro'       => mb_substr($medio, 0, 15),
                ':credito'           => $credito_espejo,
                ':debito'            => $debito_espejo,
                ':login'             => $id_login,
                ':comprobante'       => $comprobante ?: null,
                ':beneficiario'      => $caja['caja'],
                ':cantidad'          => $importe,
                ':referencia'        => $es_entrada ? 15 : 16,
                ':codigo_relacion'   => $id_caja,
                ':id_extracto_ref'   => $newId,
            ]);
        }
    }

    $tipo_label = $es_entrada ? 'Entrada' : 'Salida';
    echo json_encode([
        'ok'  => true,
        'msg' => "{$tipo_label} registrada correctamente",
        'id'  => (int)$newId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
