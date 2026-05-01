<?php
// Router para php -S en desarrollo local.
// Inyecta las variables de entorno necesarias para conectar a MySQL local.

putenv('SISTEMAX_ENV=dev');
putenv('SISTEMAX_MASTER_DB_HOST=127.0.0.1');
putenv('SISTEMAX_MASTER_DB_PORT=3306');
putenv('SISTEMAX_MASTER_DB_NAME=serproc1');
putenv('SISTEMAX_MASTER_DB_USER=sistemax');
putenv('SISTEMAX_MASTER_DB_PASS=Armagedon123');

// Resolver la URL desde la raíz del proyecto
// Así /public/menu/menu.php → <project_root>/public/menu/menu.php
$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

if (is_file($file)) {
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        return false; // archivos estáticos los sirve PHP directamente
    }
    require $file;
} elseif (is_dir($file)) {
    $index = rtrim($file, '/') . '/index.php';
    if (is_file($index)) {
        require $index;
    } else {
        http_response_code(403);
    }
} else {
    http_response_code(404);
    echo "404 – No encontrado: $uri";
}
