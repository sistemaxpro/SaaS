<?php

/**
 * Script de prueba para DELETE de empresa
 * Simula una solicitud HTTP POST al endpoint de eliminación
 */

// Simular la sesión
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['dbu'] = 'serproc1';
$_SESSION['server'] = '168.231.95.50';
$_SESSION['user'] = 'sistemax';
$_SESSION['password'] = 'Armagedon123';
$_SESSION['id_empresa'] = 1042;

// ID de empresa a eliminar (cambiar según sea necesario)
$idEmpresa = 1042;
$ruc = '80102866-3';  // RUC a confirmar

// Datos POST para enviar
$postData = [
    'id_empresa' => $idEmpresa,
    'confirmacion' => $ruc
];

// URL del endpoint
$url = 'http://localhost/admin_empresa/editar_empresa.php?action=delete';

// Crear contexto para file_get_contents
$options = [
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => json_encode($postData),
        'timeout' => 60
    ]
];

$context = stream_context_create($options);

echo "Enviando solicitud DELETE para empresa {$idEmpresa} con RUC {$ruc}\n";
echo "URL: {$url}\n";
echo "Datos: " . json_encode($postData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Ejecutar solicitud
$response = @file_get_contents($url, false, $context);

if ($response === false) {
    echo "Error: No se pudo conectar al endpoint\n";
    echo "Headers: " . print_r($http_response_header ?? [], true);
} else {
    echo "Respuesta recibida:\n";

    // Intentar decodificar JSON
    $data = json_decode($response, true);

    if ($data === null) {
        echo "ERROR: Respuesta no es JSON válido\n";
        echo "Raw Response:\n" . substr($response, 0, 1000) . "\n";
    } else {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
