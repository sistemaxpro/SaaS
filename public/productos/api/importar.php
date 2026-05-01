<?php
/**
 * Productos API - Importación masiva CSV
 * POST multipart/form-data: file=archivo.csv
 * 
 * Formato CSV esperado (con encabezados):
 * codigo,descripcion,referencia,precio_compra,precio_venta,iva,grupo,marca,modelo,unidad_medida,stock_minimo,stock_maximo,codigo_barra
 * 
 * GET: ?action=template — Descargar plantilla CSV
 */

header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requirePermission('app_grid_mercaderias', 'priv_insert');

$id_empresa = (int)($_REQUEST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? 0);

// Descargar plantilla
if (($_GET['action'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_productos.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo "codigo,descripcion,referencia,precio_compra,precio_venta,iva_porcentaje,grupo,marca,modelo,unidad_medida,stock_minimo,stock_maximo,codigo_barra\n";
    echo "001,Producto Ejemplo,REF-001,50000,75000,10,General,,,,0,100,7891234567890\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'Archivo CSV requerido']);
    exit;
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'])) {
    echo json_encode(['success' => false, 'error' => 'Solo archivos CSV/TXT']);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    // Cargar catálogos existentes para mapeo por nombre
    $grupos = [];
    $marcas = [];
    $modelos = [];
    foreach ($pdo->query("SELECT id, grupo FROM {$db}.mercaderia_grupo")->fetchAll() as $r) {
        $grupos[mb_strtolower(trim($r['grupo']))] = $r['id'];
    }
    foreach ($pdo->query("SELECT id, marca FROM {$db}.mercaderia_marca")->fetchAll() as $r) {
        $marcas[mb_strtolower(trim($r['marca']))] = $r['id'];
    }
    foreach ($pdo->query("SELECT id, modelo FROM {$db}.mercaderia_modelo")->fetchAll() as $r) {
        $modelos[mb_strtolower(trim($r['modelo']))] = $r['id'];
    }

    // Leer CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        echo json_encode(['success' => false, 'error' => 'No se pudo leer el archivo']);
        exit;
    }

    // Detectar separador (coma o punto y coma)
    $firstLine = fgets($handle);
    rewind($handle);
    $separator = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Leer encabezados
    $headers = fgetcsv($handle, 0, $separator);
    if (!$headers) {
        echo json_encode(['success' => false, 'error' => 'CSV sin encabezados']);
        exit;
    }
    // Limpiar BOM
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $headers = array_map(function($h) { return mb_strtolower(trim($h)); }, $headers);

    // Mapeo de columnas
    $colMap = [
        'codigo' => ['codigo', 'cve_producto', 'code', 'cod'],
        'descripcion' => ['descripcion', 'desproducto', 'nombre', 'description', 'desc', 'producto'],
        'referencia' => ['referencia', 'ref'],
        'precio_compra' => ['precio_compra', 'costo', 'cost', 'compra'],
        'precio_venta' => ['precio_venta', 'precio', 'price', 'venta'],
        'iva_porcentaje' => ['iva_porcentaje', 'iva', 'impuesto', 'tax'],
        'grupo' => ['grupo', 'categoria', 'category', 'group'],
        'marca' => ['marca', 'brand'],
        'modelo' => ['modelo', 'model'],
        'unidad_medida' => ['unidad_medida', 'unidad', 'unit'],
        'stock_minimo' => ['stock_minimo', 'min_stock'],
        'stock_maximo' => ['stock_maximo', 'max_stock'],
        'codigo_barra' => ['codigo_barra', 'barcode', 'ean', 'upc'],
    ];

    $headerIndex = [];
    foreach ($colMap as $field => $aliases) {
        foreach ($aliases as $alias) {
            $pos = array_search($alias, $headers);
            if ($pos !== false) {
                $headerIndex[$field] = $pos;
                break;
            }
        }
    }

    if (!isset($headerIndex['descripcion'])) {
        echo json_encode(['success' => false, 'error' => 'Columna "descripcion" o "nombre" requerida en el CSV']);
        exit;
    }

    $pdo->beginTransaction();

    // Detectar columnas disponibles en tblproductos para este tenant
    $colsStmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'tblproductos'");
    $availableCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasCodBarraCol  = in_array('codigo_barra', $availableCols);
    $hasUnidadMedida = in_array('unidad_medida', $availableCols);

    $creados = 0;
    $actualizados = 0;
    $errores = [];
    $fila = 1;

    // Preparar statements dinámicos según columnas disponibles
    $stmtCheckCod = $pdo->prepare("SELECT idproducto FROM {$db}.tblproductos WHERE cve_producto = :c");
    $stmtNextCode = $pdo->query("SELECT IFNULL(MAX(CAST(cve_producto AS UNSIGNED)), 0) FROM {$db}.tblproductos WHERE cve_producto REGEXP '^[0-9]+$'");
    $nextAutoCode = (int)$stmtNextCode->fetchColumn() + 1;

    // Construir INSERT dinámico
    $insertCols = ['cve_producto', 'desproducto', 'referencia', 'precio_compra', 'precio_venta', 'impuesto', 'iva', 'grupo', 'marca', 'modelo', 'stock_minimo', 'stock_maximo', 'Estado'];
    $insertPlaceholders = [':cve', ':des', ':ref', ':pc', ':pv', ':imp', ':iva', ':grp', ':mar', ':mod', ':smin', ':smax', '1'];
    if ($hasUnidadMedida) { $insertCols[] = 'unidad_medida'; $insertPlaceholders[] = ':um'; }
    if ($hasCodBarraCol)  { $insertCols[] = 'codigo_barra';  $insertPlaceholders[] = ':cb'; }

    $stmtInsert = $pdo->prepare("INSERT INTO {$db}.tblproductos (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertPlaceholders) . ")");

    // Construir UPDATE dinámico
    $updateSets = ['desproducto = :des', 'referencia = :ref', 'precio_compra = :pc', 'precio_venta = :pv', 'impuesto = :imp', 'iva = :iva', 'grupo = :grp', 'marca = :mar', 'modelo = :mod', 'stock_minimo = :smin', 'stock_maximo = :smax'];
    if ($hasUnidadMedida) $updateSets[] = 'unidad_medida = :um';
    if ($hasCodBarraCol)  $updateSets[] = 'codigo_barra = :cb';

    $stmtUpdate = $pdo->prepare("UPDATE {$db}.tblproductos SET " . implode(', ', $updateSets) . " WHERE idproducto = :id");

    $stmtCB = $pdo->prepare("INSERT IGNORE INTO {$db}.codigo_barra (id_producto, codigo_barra, id_login) VALUES (:id, :cb, :login)");

    // Auto-crear catálogos
    $stmtNewGrupo = $pdo->prepare("INSERT INTO {$db}.mercaderia_grupo (grupo) VALUES (:n)");
    $stmtNewMarca = $pdo->prepare("INSERT INTO {$db}.mercaderia_marca (marca) VALUES (:n)");
    $stmtNewModelo = $pdo->prepare("INSERT INTO {$db}.mercaderia_modelo (modelo) VALUES (:n)");

    while (($row = fgetcsv($handle, 0, $separator)) !== false) {
        $fila++;
        if (count($row) < 2) continue;
        // Saltar filas vacías
        $desc = trim($row[$headerIndex['descripcion']] ?? '');
        if (empty($desc)) continue;

        try {
            $codigo = trim($row[$headerIndex['codigo'] ?? -1] ?? '');
            $referencia = trim($row[$headerIndex['referencia'] ?? -1] ?? '');
            $precioCompra = (float)str_replace(',', '.', $row[$headerIndex['precio_compra'] ?? -1] ?? '0');
            $precioVenta = (float)str_replace(',', '.', $row[$headerIndex['precio_venta'] ?? -1] ?? '0');
            $ivaPorcentaje = (float)str_replace(',', '.', $row[$headerIndex['iva_porcentaje'] ?? -1] ?? '10');
            $ivaCode = ($ivaPorcentaje <= 0) ? 3 : (($ivaPorcentaje <= 5) ? 2 : 1); // 1=10%, 2=5%, 3=exenta
            $stockMin = (float)($row[$headerIndex['stock_minimo'] ?? -1] ?? '0');
            $stockMax = (float)($row[$headerIndex['stock_maximo'] ?? -1] ?? '0');
            $codigoBarra = trim($row[$headerIndex['codigo_barra'] ?? -1] ?? '');
            $unidadMedida = trim($row[$headerIndex['unidad_medida'] ?? -1] ?? '') ?: null;

            // Resolver grupo
            $grupoNombre = trim($row[$headerIndex['grupo'] ?? -1] ?? '');
            $grupoId = 0;
            if (!empty($grupoNombre)) {
                $key = mb_strtolower($grupoNombre);
                if (isset($grupos[$key])) {
                    $grupoId = $grupos[$key];
                } else {
                    $stmtNewGrupo->execute([':n' => $grupoNombre]);
                    $grupoId = (int)$pdo->lastInsertId();
                    $grupos[$key] = $grupoId;
                }
            }

            // Resolver marca
            $marcaNombre = trim($row[$headerIndex['marca'] ?? -1] ?? '');
            $marcaId = 0;
            if (!empty($marcaNombre)) {
                $key = mb_strtolower($marcaNombre);
                if (isset($marcas[$key])) {
                    $marcaId = $marcas[$key];
                } else {
                    $stmtNewMarca->execute([':n' => $marcaNombre]);
                    $marcaId = (int)$pdo->lastInsertId();
                    $marcas[$key] = $marcaId;
                }
            }

            // Resolver modelo
            $modeloNombre = trim($row[$headerIndex['modelo'] ?? -1] ?? '');
            $modeloId = 0;
            if (!empty($modeloNombre)) {
                $key = mb_strtolower($modeloNombre);
                if (isset($modelos[$key])) {
                    $modeloId = $modelos[$key];
                } else {
                    $stmtNewModelo->execute([':n' => $modeloNombre]);
                    $modeloId = (int)$pdo->lastInsertId();
                    $modelos[$key] = $modeloId;
                }
            }

            // Verificar si existe por código
            $existingId = null;
            if (!empty($codigo)) {
                $stmtCheckCod->execute([':c' => $codigo]);
                $existing = $stmtCheckCod->fetch();
                if ($existing) $existingId = (int)$existing['idproducto'];
            }

            $params = [
                ':des'  => $desc,
                ':ref'  => $referencia,
                ':pc'   => $precioCompra,
                ':pv'   => $precioVenta,
                ':imp'  => $ivaPorcentaje,
                ':iva'  => $ivaCode,
                ':grp'  => $grupoId,
                ':mar'  => $marcaId,
                ':mod'  => $modeloId,
                ':smin' => $stockMin,
                ':smax' => $stockMax,
            ];
            if ($hasUnidadMedida) $params[':um'] = $unidadMedida;
            if ($hasCodBarraCol)  $params[':cb'] = $codigoBarra;

            if ($existingId) {
                $params[':id'] = $existingId;
                $stmtUpdate->execute($params);
                $idproducto = $existingId;
                $actualizados++;
            } else {
                if (empty($codigo)) {
                    $codigo = str_pad($nextAutoCode++, 6, '0', STR_PAD_LEFT);
                }
                $params[':cve'] = $codigo;
                $stmtInsert->execute($params);
                $idproducto = (int)$pdo->lastInsertId();
                $creados++;
            }

            // Código de barra adicional
            if (!empty($codigoBarra)) {
                $stmtCB->execute([':id' => $idproducto, ':cb' => $codigoBarra, ':login' => $id_login]);
            }

        } catch (Exception $rowErr) {
            $errores[] = "Fila {$fila}: " . $rowErr->getMessage();
            if (count($errores) > 50) break;
        }
    }

    fclose($handle);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Importación completada",
        'data' => [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'errores' => count($errores),
            'detalle_errores' => array_slice($errores, 0, 20)
        ]
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
