<?php

/**
 * Script de prueba para verificar que sifen_retry.php funciona correctamente
 */

// Simular una petición POST
$_POST['id_factura'] = 0; // ID inválido para probar manejo de errores
$_POST['id_empresa'] = 169;

// Capturar la salida
ob_start();
include 'sifen_retry.php';
$output = ob_get_clean();

// Mostrar resultado
header('Content-Type: application/json');
echo $output;
