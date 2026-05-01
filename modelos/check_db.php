<?php
session_start();
$dbHost = $_SESSION["server"] ?? "168.231.95.50";
$dbUser = $_SESSION["user"] ?? "sistemax";
$dbPass = $_SESSION["password"] ?? "Armagedon123";
$dbName = $_SESSION["dbu"] ?? "serproc1";
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $stmt = $pdo->query("SHOW COLUMNS FROM sec_users LIKE "ruc_default"");
    echo $stmt->fetch() ? "EXISTS" : "MISSING";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage(); }
