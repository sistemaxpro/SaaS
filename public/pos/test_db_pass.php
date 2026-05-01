<?php
require_once __DIR__ . '/../../../config/bootstrap.php';

try {
    Database::getMasterConnection();
    echo "✓ Conexión a serproc1 OK\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
