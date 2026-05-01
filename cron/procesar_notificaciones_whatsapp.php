<?php
/**
 * Cron Job: Procesar cola de notificaciones WhatsApp
 *
 * Ejemplo crontab (cada minuto):
 * * * * * php /var/www/html/desarrollo/cron/procesar_notificaciones_whatsapp.php >> /var/log/sistemax_whatsapp.log 2>&1
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/Support/whatsapp_queue.php';

date_default_timezone_set('America/Asuncion');

function logLine(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

try {
    $pdo = Database::getMasterConnection();
    sx_ensure_whatsapp_outbox_table($pdo);

    $limit = (int)($_SERVER['argv'][1] ?? 50);
    if ($limit <= 0) $limit = 50;
    if ($limit > 500) $limit = 500;

    $stats = sx_process_whatsapp_outbox($pdo, $limit);
    logLine("Procesado WhatsApp outbox: picked={$stats['picked']} sent={$stats['sent']} failed={$stats['failed']}");
} catch (Throwable $e) {
    logLine('ERROR: ' . $e->getMessage());
    exit(1);
}

exit(0);
