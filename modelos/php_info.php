<?php
/**
 * Script para mostrar información detallada de PHP
 * Muestra versión, extensiones y configuración del sistema
 */

// Configurar zona horaria por defecto
date_default_timezone_set('America/Montevideo');

// Configurar la salida HTML
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Información de PHP - Plataforma SIFEN</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1, h2 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #007bff;
        }
        .info-card h3 {
            margin-top: 0;
            color: #007bff;
        }
        .extension-list {
            columns: 3;
            column-gap: 20px;
        }
        .extension-item {
            break-inside: avoid;
            padding: 2px 0;
        }
        .highlight {
            background-color: #fff3cd;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #ffc107;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .back-link:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🐘 Información de PHP - Plataforma SIFEN</h1>
        
        <div class="highlight">
            <strong>Versión de PHP:</strong> <?php echo PHP_VERSION; ?><br>
            <strong>Versión de Zend Engine:</strong> <?php echo zend_version(); ?><br>
            <strong>Sistema Operativo:</strong> <?php echo PHP_OS; ?><br>
            <strong>Arquitectura:</strong> <?php echo php_uname('m'); ?>
        </div>

        <div class="info-grid">
            <div class="info-card">
                <h3>📊 Información del Sistema</h3>
                <strong>SAPI:</strong> <?php echo php_sapi_name(); ?><br>
                <strong>Servidor:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'No disponible'; ?><br>
                <strong>Directorio de trabajo:</strong> <?php echo getcwd(); ?><br>
                <strong>Usuario PHP:</strong> <?php echo get_current_user(); ?><br>
                <strong>Memoria límite:</strong> <?php echo ini_get('memory_limit'); ?><br>
                <strong>Tiempo máximo ejecución:</strong> <?php echo ini_get('max_execution_time'); ?>s
            </div>

            <div class="info-card">
                <h3>📁 Directorios Importantes</h3>
                <strong>Directorio de configuración:</strong><br>
                <?php echo php_ini_loaded_file() ?: 'No encontrado'; ?><br><br>
                <strong>Directorios adicionales:</strong><br>
                <?php echo php_ini_scanned_files() ?: 'Ninguno'; ?><br><br>
                <strong>Include path:</strong><br>
                <?php echo get_include_path(); ?>
            </div>

            <div class="info-card">
                <h3>🔧 Configuración Crítica</h3>
                <strong>Display errors:</strong> <?php echo ini_get('display_errors') ? 'Activado' : 'Desactivado'; ?><br>
                <strong>Log errors:</strong> <?php echo ini_get('log_errors') ? 'Activado' : 'Desactivado'; ?><br>
                <strong>Error reporting:</strong> <?php echo error_reporting(); ?><br>
                <strong>Upload max filesize:</strong> <?php echo ini_get('upload_max_filesize'); ?><br>
                <strong>Post max size:</strong> <?php echo ini_get('post_max_size'); ?><br>
                <strong>Default timezone:</strong> <?php echo date_default_timezone_get(); ?>
            </div>
        </div>

        <h2>🔌 Extensiones de PHP Cargadas (<?php echo count(get_loaded_extensions()); ?> total)</h2>
        
        <div class="highlight">
            <strong>Extensiones importantes para SIFEN:</strong>
            <?php
            $important_extensions = ['openssl', 'soap', 'curl', 'xml', 'simplexml', 'dom', 'libxml'];
            $loaded = get_loaded_extensions();
            foreach ($important_extensions as $ext) {
                $status = in_array($ext, $loaded) ? '✅' : '❌';
                echo "<span style='margin-right: 15px;'>{$status} {$ext}</span>";
            }
            ?>
        </div>

        <div class="extension-list">
            <?php
            $extensions = get_loaded_extensions();
            sort($extensions);
            foreach ($extensions as $extension) {
                echo "<div class='extension-item'>📦 {$extension}</div>";
            }
            ?>
        </div>

        <h2>🌐 Variables de Entorno del Servidor</h2>
        <div class="info-card">
            <?php
            $server_vars = ['HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'DOCUMENT_ROOT', 'REQUEST_URI', 'HTTP_USER_AGENT'];
            foreach ($server_vars as $var) {
                if (isset($_SERVER[$var])) {
                    echo "<strong>{$var}:</strong> " . htmlspecialchars($_SERVER[$var]) . "<br>";
                }
            }
            ?>
        </div>

        <h2>📋 Información Detallada de PHP</h2>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <p><strong>Nota:</strong> La información completa de phpinfo() se muestra a continuación:</p>
        </div>
        
        <?php
        // Mostrar phpinfo() completo
        phpinfo();
        ?>

        <a href="factura_tpy.php" class="back-link">← Volver a SIFEN</a>
    </div>
</body>
</html>