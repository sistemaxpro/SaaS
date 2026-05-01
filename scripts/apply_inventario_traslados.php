<?php
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once __DIR__ . '/../public/productos/config/db_config.php';

$migrationSql = trim((string)file_get_contents(__DIR__ . '/../database/migrations/013_inventario_traslados.sql'));
if ($migrationSql === '') {
    fwrite(STDERR, "No se pudo leer la migracion 013_inventario_traslados.sql\n");
    exit(1);
}

$empresaIds = [];
$applyAll = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--all') {
        $applyAll = true;
        continue;
    }
    if (str_starts_with($arg, '--empresa=')) {
        $id = (int)substr($arg, strlen('--empresa='));
        if ($id > 0) {
            $empresaIds[] = $id;
        }
    }
}

$master = getMasterConnection();

if ($applyAll) {
    $stmt = $master->query("SELECT id_empresa FROM empresa WHERE id_empresa > 0 ORDER BY id_empresa");
    $empresaIds = array_map(static fn(array $row): int => (int)$row['id_empresa'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

$empresaIds = array_values(array_unique(array_filter($empresaIds)));

if (empty($empresaIds)) {
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php scripts/apply_inventario_traslados.php --empresa=118\n");
    fwrite(STDERR, "  php scripts/apply_inventario_traslados.php --all\n");
    exit(1);
}

$ok = 0;
$fail = 0;

foreach ($empresaIds as $idEmpresa) {
    try {
        $conn = getEmpresaConnection($idEmpresa);
        $pdo = $conn['pdo'];
        $db = $conn['dbName'];
        $sql = str_replace('CREATE TABLE IF NOT EXISTS inventario_traslados', "CREATE TABLE IF NOT EXISTS {$db}.inventario_traslados", $migrationSql);
        $pdo->exec($sql);
        echo "[OK] Empresa {$idEmpresa} ({$db}) migrada\n";
        $ok++;
    } catch (Throwable $e) {
        fwrite(STDERR, "[ERROR] Empresa {$idEmpresa}: {$e->getMessage()}\n");
        $fail++;
    }
}

echo "Resultado: {$ok} ok, {$fail} con error\n";
exit($fail > 0 ? 2 : 0);
