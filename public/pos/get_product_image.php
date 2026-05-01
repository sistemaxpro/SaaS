<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Verificar autenticación
if (!isset($_SESSION['id_login']) || !$_SESSION['id_login']) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Obtener parámetros
$productId = $_GET['id'] ?? '';
$rutaImagenesURL = $_SESSION['ruta_imagen_mercaderias'] ?? '';

if (empty($productId) || empty($rutaImagenesURL)) {
    echo json_encode(['error' => 'Parámetros inválidos', 'ruta' => $rutaImagenesURL]);
    exit;
}

// Convertir URL a ruta del sistema de archivos
// https://sistemax.com.py/_lib/file/img/productos/tienda_169/ -> /var/www/html/scriptcase/app/smx/_lib/file/img/productos/tienda_169/
$rutaImagenes = str_replace('https://sistemax.com.py/', '/var/www/html/scriptcase/app/smx/', $rutaImagenesURL);

// Si la ruta no comienza con /, es relativa
if (substr($rutaImagenes, 0, 1) !== '/') {
    $rutaImagenes = '/var/www/html/scriptcase/app/smx/' . $rutaImagenes;
}

// Extensiones permitidas
$extensiones = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// Buscar archivo que coincida con el patrón {id}_*.{extension}
$imagenEncontrada = null;

foreach ($extensiones as $ext) {
    // Buscar archivos que comiencen con {id}_ y terminen con la extensión
    $patron = $rutaImagenes . $productId . '_*.' . $ext;
    $archivos = glob($patron);
    
    if (!empty($archivos) && file_exists($archivos[0])) {
        // Tomar el primer archivo encontrado
        $imagenEncontrada = basename($archivos[0]);
        break;
    }
}

if ($imagenEncontrada) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'url' => $rutaImagenesURL . $imagenEncontrada
    ]);
} else {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Imagen no encontrada',
        'debug' => [
            'productId' => $productId,
            'rutaURL' => $rutaImagenesURL,
            'rutaFS' => $rutaImagenes,
            'exists' => is_dir($rutaImagenes),
            'readable' => is_readable($rutaImagenes)
        ]
    ]);
}
exit;
