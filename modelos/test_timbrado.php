<?php
session_start();
$id_empresa = $_SESSION['id_empresa'] ?? 1039;
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

echo "<h3>Test Timbrado</h3>";
echo "id_empresa: $id_empresa<br>";
echo "dbHost: $dbHost<br>";
echo "masterDb: $masterDb<br>";

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $stmt = $pdo->prepare("SELECT timbrado, empresa FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($row);
    echo "</pre>";
    echo "<b>Timbrado: " . ($row['timbrado'] ?? 'NO ENCONTRADO') . "</b>";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
