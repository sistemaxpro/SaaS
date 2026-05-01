<?php
/**
 * Benchmark: search_dropdown performance
 * Uso: cd /var/www/html/desarrollo && php public/pos/test_search_perf.php
 */

require_once __DIR__ . '/config/db_config.php';

$empresaConn = getEmpresaConnection(169);
$pdo = $empresaConn['pdo'];
$dbName = $empresaConn['dbName'];

$testQueries = [
    'cola' => 'una palabra',
    'cola reten' => 'dos palabras',
    '5000' => 'número (barcode)',
    'válvula' => 'con acentos',
    'x' => 'letra sola',
];

echo "=== BENCHMARK: search_dropdown ===\n";
echo "DB: $dbName\n\n";

foreach ($testQueries as $q => $desc) {
    echo "Query: \"$q\" ($desc)\n";
    
    // FULLTEXT test
    $start = microtime(true);
    $ftQuery = '+' . str_replace(' ', '* +', $q) . '*';
    try {
        $stmt = $pdo->prepare("SELECT p.idproducto FROM $dbName.tblproductos p WHERE p.Estado = 1 AND MATCH(p.desproducto, p.cve_producto, p.referencia) AGAINST(:ft IN BOOLEAN MODE) LIMIT 20");
        $stmt->execute([':ft' => $ftQuery]);
        $ftResults = count($stmt->fetchAll(PDO::FETCH_COLUMN));
        $ftTime = (microtime(true) - $start) * 1000;
        echo "  FULLTEXT: $ftResults resultados en {$ftTime}ms ✅\n";
    } catch (\Exception $e) {
        $ftTime = 0;
        echo "  FULLTEXT: FALLO ❌\n";
    }
    
    // LIKE test (fallback)
    $start = microtime(true);
    $words = explode(' ', $q);
    $likeSql = "SELECT p.idproducto FROM $dbName.tblproductos p WHERE p.Estado = 1";
    $params = [];
    foreach ($words as $idx => $w) {
        $likeSql .= " AND (p.desproducto LIKE :q_d{$idx} OR p.cve_producto LIKE :q_c{$idx} OR p.referencia LIKE :q_r{$idx})";
        $val = '%' . $w . '%';
        $params[":q_d{$idx}"] = $val;
        $params[":q_c{$idx}"] = $val;
        $params[":q_r{$idx}"] = $val;
    }
    $likeSql .= " LIMIT 20";
    $stmt = $pdo->prepare($likeSql);
    $stmt->execute($params);
    $likeResults = count($stmt->fetchAll(PDO::FETCH_COLUMN));
    $likeTime = (microtime(true) - $start) * 1000;
    echo "  LIKE fallback: $likeResults resultados en {$likeTime}ms";
    if ($likeTime > 200) {
        echo " ⚠️ LENTO\n";
    } else {
        echo " ✅\n";
    }
    
    if ($ftTime > 0) {
        $ratio = round($likeTime / $ftTime, 1);
        echo "  FULLTEXT es {$ratio}x más rápido\n";
    }
    echo "\n";
}

echo "\n=== VERIFICACIÓN DE ÍNDICES ===\n";
$stmt = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS 
                    WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME = 'tblproductos' 
                    AND INDEX_NAME LIKE '%search%'");
$indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($indexes)) {
    echo "Índices FULLTEXT encontrados: " . implode(', ', $indexes) . " ✅\n";
} else {
    echo "⚠️  No hay índices FULLTEXT. Creando...\n";
    try {
        $pdo->exec("ALTER TABLE $dbName.tblproductos ADD FULLTEXT INDEX ft_search_dropdown (desproducto, cve_producto, referencia)");
        echo "Índice creado ✅\n";
    } catch (\Exception $e) {
        echo "Error creando índice: " . $e->getMessage() . "\n";
    }
}
?>
