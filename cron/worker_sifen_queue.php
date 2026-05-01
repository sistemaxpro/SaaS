#!/usr/bin/env php
<?php
/**
 * Worker de cola FE SIFEN
 * Uso:
 *   php cron/worker_sifen_queue.php
 *   php cron/worker_sifen_queue.php --limit=20
 */

declare(strict_types=1);

if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'CLI';
}

// En CLI no existe HTTP_HOST y el SDK cae a localhost/facturacion/procesar.php (host incorrecto).
// Forzamos endpoint interno correcto para puente SOAP de SIFEN.
if (!getenv('SIFEN_PROCESAR_URL')) {
    putenv('SIFEN_PROCESAR_URL=https://sistemax.pro/public/_lib/php-sifen3-custom/procesar.php');
}

require_once __DIR__ . '/../public/pos/config/db_config.php';
require_once __DIR__ . '/../public/pos/api/sifen_queue.php';

$limit = 10;
foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $v = (int)substr($arg, 8);
        if ($v > 0) {
            $limit = $v;
        }
    }
}

$lockFile = __DIR__ . '/../logs/worker_sifen_queue.lock';
$fh = fopen($lockFile, 'c+');
if (!$fh) {
    fwrite(STDERR, "No se pudo crear lock file: {$lockFile}\n");
    exit(1);
}
if (!flock($fh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] worker_sifen_queue: otro worker ya está corriendo\n");
    fclose($fh);
    exit(0);
}

try {
    $pdoMaster = getMasterConnection();
    sifenQueueEnsureTable($pdoMaster);

    $processed = 0;
    for ($i = 0; $i < $limit; $i++) {
        $res = sifenQueueProcessOne($pdoMaster, null);
        if (!(bool)($res['processed'] ?? false)) {
            break;
        }
        $processed++;
        $estado = (string)($res['estado'] ?? 'N/D');
        $idFactura = (int)($res['id_factura'] ?? 0);
        $msg = (string)($res['message'] ?? '');
        fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] FE queue: factura={$idFactura} estado={$estado} msg={$msg}\n");
    }

    fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] FE queue: procesados={$processed}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] FE queue error: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}

exit(0);
