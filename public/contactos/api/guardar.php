<?php
/**
 * Contactos API - Guardar (Crear/Actualizar)
 * POST: { action: "create"|"update", contacto: {...} }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

// Verificar permisos según acción
$actionType = $input['action'] ?? 'create';
if ($actionType === 'create') {
    Permission::requirePermission('app_grid_clientes', 'priv_insert');
} else {
    Permission::requirePermission('app_grid_clientes', 'priv_update');
}

$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? 0);
$action = $input['action'] ?? 'create';
$c = $input['contacto'] ?? [];

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    // Detectar columnas disponibles y tipo real
    $colsStmt = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'clientes'");
    $availableCols = [];
    $columnTypes = [];
    foreach (($colsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $meta) {
        $name = (string)($meta['COLUMN_NAME'] ?? '');
        if ($name === '') continue;
        $availableCols[] = $name;
        $columnTypes[$name] = strtolower((string)($meta['DATA_TYPE'] ?? ''));
    }
    $isNumericColumn = static function (string $column) use ($columnTypes): bool {
        $type = $columnTypes[$column] ?? '';
        return in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'], true);
    };

    $pdo->beginTransaction();

    // Columnas base que siempre existen
    $baseCols = [
        'sucursal', 'cuenta', 'fecha', 'documento', 'numero', 'nombre',
        'direccion', 'estado', 'raiting', 'obs'
    ];
    $baseParams = [
        ':sucursal'  => (int)($c['sucursal'] ?? 1),
        ':cuenta'    => (int)($c['cuenta'] ?? 3),
        ':fecha'     => $c['fecha'] ?? date('Y-m-d'),
        ':documento' => trim($c['documento'] ?? '11'),
        ':numero'    => trim($c['numero'] ?? ''),
        ':nombre'    => trim($c['nombre'] ?? ''),
        ':direccion' => trim($c['direccion'] ?? ''),
        ':estado'    => (int)($c['estado'] ?? 1),
        ':raiting'   => (int)($c['raiting'] ?? 1),
        ':obs'       => trim($c['obs'] ?? ''),
    ];
    $colParamMap = [
        'sucursal' => ':sucursal', 'cuenta' => ':cuenta', 'fecha' => ':fecha',
        'documento' => ':documento', 'numero' => ':numero', 'nombre' => ':nombre',
        'direccion' => ':direccion', 'estado' => ':estado', 'raiting' => ':raiting',
        'obs' => ':obs',
    ];

    // Columnas opcionales
    $optionalMap = [
        [in_array('telefono', $availableCols),       'telefono',       ':tel',     trim($c['telefono'] ?? '')],
        [in_array('email', $availableCols),           'email',          ':email',   trim($c['email'] ?? '')],
        [in_array('ciudad', $availableCols),          'ciudad',         ':ciudad',  $isNumericColumn('ciudad') ? (int)($c['ciudad'] ?? 0) : trim((string)($c['ciudad'] ?? ''))],
        [in_array('pais', $availableCols),            'pais',           ':pais',    $isNumericColumn('pais') ? (int)($c['pais'] ?? 17) : trim((string)($c['pais'] ?? 'Paraguay'))],
        [in_array('fecha_nacimiento', $availableCols),'fecha_nacimiento',':fnac',   $c['fecha_nacimiento'] ?? null],
        [in_array('linea_credito', $availableCols),   'linea_credito',  ':lcred',   (float)($c['linea_credito'] ?? 0)],
        [in_array('vendedor_asignado', $availableCols),'vendedor_asignado',':vend', (int)($c['vendedor_asignado'] ?? 0)],
        [in_array('timbrado', $availableCols),        'timbrado',       ':timb',    trim($c['timbrado'] ?? '')],
        [in_array('complemento_direccion1', $availableCols), 'complemento_direccion1', ':comp1', trim($c['complemento_direccion1'] ?? '')],
        [in_array('complemento_direccion2', $availableCols), 'complemento_direccion2', ':comp2', trim($c['complemento_direccion2'] ?? '')],
        [in_array('numero_casa', $availableCols),     'numero_casa',    ':ncasa',   trim($c['numero_casa'] ?? '')],
        [in_array('salario', $availableCols),         'salario',        ':sal',     (float)($c['salario'] ?? 0)],
        [in_array('comision', $availableCols),        'comision',       ':com',     (float)($c['comision'] ?? 0)],
        [in_array('tipo_comision', $availableCols),   'tipo_comision',  ':tcom',    (int)($c['tipo_comision'] ?? 0)],
        [in_array('periodo', $availableCols),         'periodo',        ':per',     (int)($c['periodo'] ?? 0)],
        [in_array('dia_pago_salario', $availableCols),'dia_pago_salario',':dpago',  (int)($c['dia_pago_salario'] ?? 0)],
    ];

    foreach ($optionalMap as [$exists, $col, $param, $val]) {
        if ($exists) {
            $baseCols[] = $col;
            $baseParams[$param] = $val;
            $colParamMap[$col] = $param;
        }
    }

    if ($action === 'create') {
        $numero = trim($c['numero'] ?? '');
        if (empty($numero)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'RUC/CI requerido']);
            exit;
        }
        if (empty(trim($c['nombre'] ?? ''))) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Nombre requerido']);
            exit;
        }

        // Generar llave = cuenta.documento.numero
        $llave = (int)($c['cuenta'] ?? 3) . '.' . trim($c['documento'] ?? '11') . '.' . $numero;

        // Validar unicidad de numero
        $stmt = $pdo->prepare("SELECT id FROM {$db}.clientes WHERE numero = :n");
        $stmt->execute([':n' => $numero]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => "El RUC/CI '{$numero}' ya existe"]);
            exit;
        }

        // Agregar llave y login
        $baseCols[] = 'llave';
        $baseParams[':llave'] = $llave;
        $colParamMap['llave'] = ':llave';

        if (in_array('login', $availableCols)) {
            $baseCols[] = 'login';
            $baseParams[':login'] = $id_login;
            $colParamMap['login'] = ':login';
        }

        $placeholders = [];
        foreach ($baseCols as $col) {
            $placeholders[] = $colParamMap[$col];
        }
        $sql = "INSERT INTO {$db}.clientes (" . implode(', ', $baseCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($baseParams);
        $idContacto = (int)$pdo->lastInsertId();

    } elseif ($action === 'update') {
        $idContacto = (int)($c['id'] ?? 0);
        if ($idContacto <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'ID de contacto requerido']);
            exit;
        }

        // Validar unicidad de numero excluyendo actual
        $numero = trim($c['numero'] ?? '');
        if (!empty($numero)) {
            $stmt = $pdo->prepare("SELECT id FROM {$db}.clientes WHERE numero = :n AND id != :id");
            $stmt->execute([':n' => $numero, ':id' => $idContacto]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => "El RUC/CI '{$numero}' ya existe en otro contacto"]);
                exit;
            }
        }

        // Actualizar llave
        $llave = (int)($c['cuenta'] ?? 3) . '.' . trim($c['documento'] ?? '11') . '.' . $numero;
        $baseCols[] = 'llave';
        $baseParams[':llave'] = $llave;
        $colParamMap['llave'] = ':llave';

        $setClauses = [];
        foreach ($baseCols as $col) {
            $setClauses[] = "{$col} = {$colParamMap[$col]}";
        }
        $baseParams[':id'] = $idContacto;
        $sql = "UPDATE {$db}.clientes SET " . implode(', ', $setClauses) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($baseParams);

    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        exit;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $action === 'create' ? 'Contacto creado correctamente' : 'Contacto actualizado correctamente',
        'id' => $idContacto
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
