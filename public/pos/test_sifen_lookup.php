<?php
// Script de prueba para SIFEN lookup
error_reporting(E_ALL);
ini_set('display_errors', 1);

$_GET['action'] = 'sifen_lookup';
$_GET['ruc'] = '80165605';
$_GET['id_empresa'] = '1039';

// Simular sesión
$_SESSION['id_empresa'] = 1039;
$_SESSION['id_login'] = 1;
$_SESSION['dbu'] = 'serproc1';
$_SESSION['server'] = 'localhost';
$_SESSION['user'] = 'sistemax';
$_SESSION['password'] = 'Armagedon123';

echo "=== TEST SIFEN LOOKUP ===\n\n";
include 'api/clientes.php';
