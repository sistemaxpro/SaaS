<?php
/**
 * API Usuarios - Catálogos (Sucursales / Cajas)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';

$id_empresa = (int)($_SESSION['id_empresa'] ?? 0);
if ($id_empresa <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Sin sesión']);
    exit;
}

Permission::requireAccess('usuarios');

function tableExists(PDO $pdo, string $schema, string $table): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = :s AND table_name = :t LIMIT 1");
    $stmt->execute([':s' => $schema, ':t' => $table]);
    return (bool)$stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $schema, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = :s AND table_name = :t AND column_name = :c LIMIT 1");
    $stmt->execute([':s' => $schema, ':t' => $table, ':c' => $column]);
    return (bool)$stmt->fetchColumn();
}

try {
    $master = getMasterPdo();
    $stmtDb = $master->prepare("SELECT dbase FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
    $stmtDb->execute([':id' => $id_empresa]);
    $db = (string)($stmtDb->fetchColumn() ?: '');

    if ($db === '') {
        echo json_encode(['ok' => false, 'error' => 'No se encontró base de empresa']);
        exit;
    }

    $sucursales = [];
    // Prioridad: nueva tabla normalizada {db}.sucursales
    if (tableExists($master, $db, 'sucursales')) {
        $idCol = columnExists($master, $db, 'sucursales', 'id_sucursal') ? 'id_sucursal' : 'id';
        $nameCol = columnExists($master, $db, 'sucursales', 'sucursal')
            ? 'sucursal'
            : (columnExists($master, $db, 'sucursales', 'nombre') ? 'nombre' : $idCol);
        $sqlSuc = "SELECT {$idCol} AS id_sucursal, {$nameCol} AS nombre FROM {$db}.sucursales ORDER BY {$idCol}";
        $sucursales = $master->query($sqlSuc)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Fallback legado: {db}.sucursal
    } elseif (tableExists($master, $db, 'sucursal')) {
        if (columnExists($master, $db, 'sucursal', 'SUC') && columnExists($master, $db, 'sucursal', 'NOMBRE')) {
            $sqlSuc = "SELECT SUC AS id_sucursal, NOMBRE AS nombre FROM {$db}.sucursal ORDER BY SUC";
        } else {
            $idCol = columnExists($master, $db, 'sucursal', 'id_sucursal') ? 'id_sucursal' : 'id';
            $nameCol = columnExists($master, $db, 'sucursal', 'nombre') ? 'nombre' : (columnExists($master, $db, 'sucursal', 'NOMBRE') ? 'NOMBRE' : $idCol);
            $sqlSuc = "SELECT {$idCol} AS id_sucursal, {$nameCol} AS nombre FROM {$db}.sucursal ORDER BY {$idCol}";
        }
        $sucursales = $master->query($sqlSuc)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $cajas = [];
    if (tableExists($master, $db, 'cajas')) {
        $nameCol = columnExists($master, $db, 'cajas', 'caja') ? 'caja' : (columnExists($master, $db, 'cajas', 'nombre') ? 'nombre' : 'id_caja');
        $sqlCaja = "SELECT id_caja, {$nameCol} AS nombre, COALESCE(id_sucursal,0) AS id_sucursal FROM {$db}.cajas ORDER BY id_caja";
        $cajas = $master->query($sqlCaja)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $tiposPrecio = [];
    if (tableExists($master, $db, 'tipo_precio')) {
        $idCol = columnExists($master, $db, 'tipo_precio', 'id') ? 'id' : null;
        $nameCol = columnExists($master, $db, 'tipo_precio', 'tipo') ? 'tipo' : null;
        if ($idCol && $nameCol) {
            $sqlTp = "SELECT {$idCol} AS id, {$nameCol} AS tipo FROM {$db}.tipo_precio ORDER BY {$idCol}";
            $tiposPrecio = $master->query($sqlTp)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    echo json_encode([
        'ok' => true,
        'sucursales' => $sucursales,
        'cajas' => $cajas,
        'tipos_precio' => $tiposPrecio
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
